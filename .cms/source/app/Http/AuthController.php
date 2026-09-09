<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\CmsRepository;
use App\Core\SoundSettings;
use App\Core\CaptchaService;
use App\Core\EmailSystem;

final class AuthController extends Controller
{
    public function __construct(private readonly Auth $auth, private readonly CmsRepository $cms, private readonly string $baseUrl, private readonly array $demo = [], private readonly ?EmailSystem $emailSystem = null) {}
    public function login(): never
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        $refHost = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
        $sensecmsUrl = 'https://www.sensecms.com/?IdRef=' . rawurlencode($refHost);
        $sounds = SoundSettings::from($this->cms->setting('sound_settings', [])); $captcha = new CaptchaService(); $captchaConfig = $captcha->config($this->cms->setting('captcha_settings', [])); $captcha->saveCodeForImage($captchaConfig); $this->view(dirname(__DIR__) . '/Views/login.php', ['csrf' => $this->auth->csrf(), 'error' => $_SESSION['login_error'] ?? null, 'notice'=>($_GET['password']??'')==='updated'?'Your password has been set. You can now sign in.':null, 'sounds' => $sounds, 'languages' => $this->cms->languages(), 'captcha' => $captchaConfig, 'sensecmsUrl' => $sensecmsUrl, 'demo' => !empty($this->demo['enabled']) ? ['email' => (string) ($this->demo['email'] ?? ''), 'password' => (string) ($this->demo['password'] ?? '')] : []]);
    }
    public function attempt(): never
    {
        $captcha = new CaptchaService(); $captchaConfig = $this->cms->setting('captcha_settings', []);
        $csrfValid = $this->auth->verifyCsrf($_POST['csrf'] ?? null);
        $captchaValid = $csrfValid && $captcha->valid((string) ($_POST['captcha'] ?? ''), $captchaConfig);
        $credentialsValid = $captchaValid && $this->auth->attempt(strtolower(trim((string) ($_POST['email'] ?? ''))), (string) ($_POST['password'] ?? ''));
        $this->auditLogin($csrfValid, $captchaValid, $credentialsValid);
        if (!$csrfValid || !$captchaValid || !$credentialsValid) {
            $message = !$csrfValid ? 'Your security session expired. Refresh and try again.' : (!$captchaValid ? 'The security code is incorrect or expired. A new code has been generated.' : 'Email address or password is incorrect. A new security code has been generated.');
            if ($this->wantsJson()) $this->result(false, $message, null, 422); $_SESSION['login_error'] = $message; $this->redirect('/login');
        }
        if (!empty($this->auth->user()['is_demo'])) $_SESSION['demo_notice_pending'] = true;
        else unset($_SESSION['demo_notice_pending']);
        $this->result(true, 'Welcome back to SenseCMS.', '/dashboard');
    }
    public function logout(): never { $this->auth->logout(); $this->result(true, 'You have been signed out.', '/login'); }
    public function captcha(): never { $captcha = new CaptchaService(); if (isset($_GET['refresh'])) $captcha->saveCodeForImage($captcha->config($this->cms->setting('captcha_settings', []))); $captcha->image(); }
    public function forgotPassword(): never { $this->recoveryView('request'); }
    public function requestPasswordReset(): never
    {
        $captcha=new CaptchaService('recovery');$config=$this->cms->setting('captcha_settings',[]);if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your security session expired. Refresh and try again.',null,419);if(!$captcha->valid((string)($_POST['captcha']??''),$config))$this->result(false,'The security code is incorrect or expired.',null,422);
        try{$this->emailSystem?->requestPasswordReset((string)($_POST['email']??''),(string)($_SERVER['REMOTE_ADDR']??''));}catch(\Throwable$error){error_log('Password reset e-mail failed: '.$error->getMessage());}
        $this->result(true,'If an active account matches that address, a secure reset link has been sent.','/forgot-password?sent=1');
    }
    public function passwordForm(): never { $this->recoveryView('password'); }
    public function setPassword(): never
    {
        if(!$this->auth->verifyCsrf($_POST['csrf']??null))$this->result(false,'Your security session expired. Refresh and try again.',null,419);$password=(string)($_POST['password']??'');if($password!==(string)($_POST['confirm_password']??''))$this->result(false,'The passwords do not match.',null,422);
        try{$this->emailSystem?->setPassword((string)($_POST['token']??''),$password);$this->result(true,'Your password has been set securely.','/login?password=updated');}catch(\RuntimeException$error){$this->result(false,$error->getMessage(),null,422);}
    }
    public function recoveryCaptcha(): never { $captcha=new CaptchaService('recovery');if(isset($_GET['refresh']))$captcha->saveCodeForImage($captcha->config($this->cms->setting('captcha_settings',[])));$captcha->image(); }
    private function auditLogin(bool $csrfValid, bool $captchaValid, bool $credentialsValid): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $record = ['time' => gmdate('c'), 'event' => 'admin.login', 'csrf' => $csrfValid, 'captcha' => $captchaValid, 'credentials' => $credentialsValid, 'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''))];
        @file_put_contents($dir . '/security.log', json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX); @chmod($dir . '/security.log', 0640);
    }
    private function recoveryView(string $mode): never
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');$captcha=new CaptchaService('recovery');$captchaConfig=$captcha->config($this->cms->setting('captcha_settings',[]));if($mode==='request')$captcha->saveCodeForImage($captchaConfig);$token=(string)($_GET['token']??'');$record=$mode==='password'?$this->emailSystem?->token($token):null;$this->view(dirname(__DIR__).'/Views/account-recovery.php',['csrf'=>$this->auth->csrf(),'mode'=>$mode,'token'=>$token,'record'=>$record,'sent'=>!empty($_GET['sent']),'captcha'=>$captchaConfig,'sounds'=>SoundSettings::from($this->cms->setting('sound_settings',[]))]);
    }
}
