<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class EmailSystem
{
    private const SETTINGS = 'email_system_settings';
    private const TEMPLATES = 'email_system_templates';
    private const FONTS = ['Arial','Georgia','Tahoma','Trebuchet MS','Verdana'];

    public function __construct(private readonly PDO $db, private readonly CmsRepository $cms, private readonly Secrets $secrets, private readonly array $config, private readonly string $baseUrl) {}

    public function dashboard(): array
    {
        $settings = $this->settings();
        unset($settings['server']['password_cipher']);
        $settings['server']['password_saved'] = $this->password() !== '';
        return ['settings'=>$settings,'templates'=>$this->templates(),'placeholders'=>['{{name}}','{{site_name}}','{{role}}','{{email}}','{{action_url}}','{{login_url}}','{{expires_in}}']];
    }

    public function mailer(): MailService
    {
        $server = $this->settings()['server'];
        if ($server['mode'] === 'disabled') throw new RuntimeException('E-mail delivery is disabled.');
        return new MailService(['host'=>$server['mode']==='smtp'?$server['host']:'', 'port'=>$server['port'], 'security'=>$server['security'], 'username'=>$server['username'], 'password'=>$this->password(), 'from_address'=>$server['from_address'], 'from_name'=>$server['from_name']]);
    }

    public function formBrand(): array
    {
        $server = $this->settings()['server'];
        return ['name'=>(string)$server['from_name'], 'email'=>(string)$server['from_address']];
    }

    public function saveServer(array $input, int $actorId): void
    {
        $settings = $this->settings();
        $mode = in_array(($input['mode'] ?? ''), ['smtp','native','disabled'], true) ? (string)$input['mode'] : 'disabled';
        $host = mb_substr(trim((string)($input['host'] ?? '')), 0, 253);
        $port = max(1, min(65535, (int)($input['port'] ?? 587)));
        $security = in_array(($input['security'] ?? ''), ['tls','ssl','none'], true) ? (string)$input['security'] : 'tls';
        $username = mb_substr(trim((string)($input['username'] ?? '')), 0, 190);
        $fromAddress = mb_strtolower(trim((string)($input['from_address'] ?? '')));
        $fromName = mb_substr(trim((string)($input['from_name'] ?? '')), 0, 120);
        if ($mode === 'smtp' && ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/D', $host))) throw new RuntimeException('Enter a valid SMTP server hostname or IP address.');
        if ($mode !== 'disabled' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid sender e-mail address.');
        if ($mode !== 'disabled' && mb_strlen($fromName) < 2) throw new RuntimeException('Sender name must contain at least two characters.');
        $cipher = (string)($settings['server']['password_cipher'] ?? '');
        $password = (string)($input['password'] ?? '');
        if (!empty($input['clear_password'])) $cipher = '';
        elseif ($password !== '') {
            if (strlen($password) > 512) throw new RuntimeException('The SMTP password is too long.');
            $cipher = $this->secrets->encrypt($password);
        }
        $settings['server'] = compact('mode','host','port','security','username','fromAddress','fromName') + ['from_address'=>$fromAddress,'from_name'=>$fromName,'password_cipher'=>$cipher];
        unset($settings['server']['fromAddress'],$settings['server']['fromName']);
        $this->cms->saveSetting(self::SETTINGS, $settings);
        $this->cms->recordActivity($actorId, 'email.server.updated', 'email', ['mode'=>$mode,'host'=>$host,'port'=>$port,'security'=>$security]);
    }

    public function saveAppearance(array $input, ?array $logo, int $actorId): void
    {
        $settings = $this->settings();
        $appearance = $settings['appearance'];
        $font = in_array(($input['font'] ?? ''), self::FONTS, true) ? (string)$input['font'] : 'Arial';
        foreach (['page_bg','content_bg','text_color','heading_color','accent_color','muted_color'] as $key) {
            $value = strtolower((string)($input[$key] ?? ''));
            if (!preg_match('/^#[0-9a-f]{6}$/D', $value)) throw new RuntimeException('Choose valid six-digit colours for the e-mail design.');
            $appearance[$key] = $value;
        }
        $appearance['font'] = $font;
        $url = trim((string)($input['logo_url'] ?? $appearance['logo_url']));
        if ($url !== '' && !$this->validAssetUrl($url)) throw new RuntimeException('Use a local asset path or a secure HTTPS logo URL.');
        $appearance['logo_url'] = $url;
        if ($logo && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $appearance['logo_url'] = $this->storeLogo($logo);
        if (!empty($input['restore_logo'])) $appearance['logo_url'] = '/theme-assets/sensecms/images/sensecms-logo-email.png';
        $settings['appearance'] = $appearance;
        $this->cms->saveSetting(self::SETTINGS, $settings);
        $this->cms->recordActivity($actorId, 'email.appearance.updated', 'email', ['logo'=>$appearance['logo_url'],'font'=>$font]);
    }

    public function saveTemplates(array $input, int $actorId): void
    {
        $templates = $this->templates();
        foreach (array_keys($templates) as $slug) {
            $value = is_array($input[$slug] ?? null) ? $input[$slug] : [];
            $subject = trim((string)($value['subject'] ?? ''));
            $heading = trim((string)($value['heading'] ?? ''));
            $body = trim((string)($value['body'] ?? ''));
            $button = trim((string)($value['button_label'] ?? ''));
            $footer = trim((string)($value['footer'] ?? ''));
            if ($subject === '' || $heading === '' || $body === '' || $button === '') throw new RuntimeException('Each e-mail template requires a subject, heading, message and action label.');
            if (strpbrk($subject, "\r\n") !== false || mb_strlen($subject) > 180 || mb_strlen($heading) > 180 || mb_strlen($body) > 5000 || mb_strlen($button) > 80 || mb_strlen($footer) > 500) throw new RuntimeException('One of the e-mail template fields is too long.');
            $templates[$slug] = compact('subject','heading','body') + ['button_label'=>$button,'footer'=>$footer];
        }
        $this->cms->saveSetting(self::TEMPLATES, $templates);
        $this->cms->recordActivity($actorId, 'email.templates.updated', 'email', ['templates'=>array_keys($templates)]);
    }

    public function sendTest(int $userId, ?string $recipient = null): string
    {
        $user = $this->user($userId);
        if (!$user) throw new RuntimeException('The current user account is unavailable.');
        $recipient = trim((string)$recipient) ?: trim((string)$user['email']);
        if (mb_strlen($recipient) > 190 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid test recipient e-mail address.');
        $this->send($recipient, 'E-mail delivery test', 'Your e-mail configuration is working', "This message confirms that your Base CMS installation can deliver secure system e-mails.\n\nNo further action is required.", 'Open Base CMS', $this->baseUrl . '/settings', 'Sent from the E-mail settings verification tool.');
        return $recipient;
    }

    public function sendWelcome(int $userId): void
    {
        $user = $this->user($userId);
        if (!$user) throw new RuntimeException('The user account is unavailable.');
        $token = $this->issue($userId, 'welcome', 72);
        try {
            $this->sendTemplate('welcome', $user, $this->baseUrl . '/set-password?token=' . rawurlencode($token), '72 hours');
        } catch (Throwable $error) {
            $this->invalidate($token);
            throw $error;
        }
    }

    public function requestPasswordReset(string $email, string $ip): void
    {
        $statement = $this->db->prepare('SELECT id,name,email FROM users WHERE email=? AND active=1 LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!$user) return;
        $rate = $this->db->prepare('SELECT COUNT(*) FROM email_action_tokens WHERE user_id=? AND purpose="password_reset" AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)');
        $rate->execute([(int)$user['id']]);
        if ((int)$rate->fetchColumn() >= 3) return;
        $token = $this->issue((int)$user['id'], 'password_reset', 1, $ip);
        try {
            $this->sendTemplate('password_reset', $user, $this->baseUrl . '/reset-password?token=' . rawurlencode($token), '1 hour');
        } catch (Throwable $error) {
            $this->invalidate($token);
            throw $error;
        }
    }

    public function token(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $statement = $this->db->prepare('SELECT t.purpose,t.expires_at,u.id user_id,u.name,u.email FROM email_action_tokens t INNER JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.active=1 LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        return $statement->fetch() ?: null;
    }

    public function setPassword(string $token, #[\SensitiveParameter] string $password): string
    {
        if (strlen($password) < 14 || strlen($password) > 200 || str_contains($password, "\0")) throw new RuntimeException('Your new password must contain 14 to 200 characters.');
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('SELECT t.id,t.user_id,t.purpose FROM email_action_tokens t INNER JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.active=1 FOR UPDATE');
            $statement->execute([hash('sha256', $token)]);
            $row = $statement->fetch();
            if (!$row) throw new RuntimeException('This secure link is invalid or has expired. Request a new one.');
            $this->db->prepare('UPDATE users SET password=?,updated_at=NOW(),session_version=session_version+1 WHERE id=?')->execute([password_hash($password, PASSWORD_ARGON2ID),(int)$row['user_id']]);
            $this->db->prepare('UPDATE email_action_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([(int)$row['user_id']]);
            $this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,"account.password.set","user",?,"{}",NOW())')->execute([(int)$row['user_id'],(int)$row['user_id']]);
            $this->db->commit();
            return (string)$row['purpose'];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function settings(): array
    {
        $mail = (array)($this->config['mail'] ?? []);
        $defaultMode = trim((string)($mail['host'] ?? '')) !== '' ? 'smtp' : (filter_var(($mail['from_address'] ?? ''), FILTER_VALIDATE_EMAIL) ? 'native' : 'disabled');
        $defaults = [
            'server'=>['mode'=>$defaultMode,'host'=>(string)($mail['host']??''),'port'=>(int)($mail['port']??587),'security'=>(string)($mail['security']??(((int)($mail['port']??587)===465)?'ssl':'tls')),'username'=>(string)($mail['username']??''),'password_cipher'=>'','from_address'=>(string)($mail['from_address']??''),'from_name'=>(string)($mail['from_name']??'Base CMS')],
            'appearance'=>['font'=>'Arial','page_bg'=>'#f1f5f9','content_bg'=>'#ffffff','text_color'=>'#334155','heading_color'=>'#0b2a63','accent_color'=>'#1763d6','muted_color'=>'#64748b','logo_url'=>'/theme-assets/sensecms/images/sensecms-logo-email.png'],
        ];
        $stored = $this->cms->setting(self::SETTINGS, []);
        if (!is_array($stored)) return $defaults;
        $settings=['server'=>array_replace($defaults['server'],(array)($stored['server']??[])),'appearance'=>array_replace($defaults['appearance'],(array)($stored['appearance']??[]))];
        if (($settings['appearance']['logo_url']??'')==='/theme-assets/sensecms/images/sensecms-logo-email.svg') $settings['appearance']['logo_url']=$defaults['appearance']['logo_url'];
        return $settings;
    }

    private function templates(): array
    {
        $defaults = [
            'welcome'=>['subject'=>'Welcome to {{site_name}}','heading'=>'Your account is ready','body'=>"Hello {{name}},\n\nYour account has been created with the {{role}} role. Use the secure button below to choose your password and enter the workspace.\n\nFor your protection, this one-time link expires in {{expires_in}}.",'button_label'=>'Set my password','footer'=>'If you were not expecting this invitation, you can safely ignore this message.'],
            'password_reset'=>['subject'=>'Reset your {{site_name}} password','heading'=>'Choose a new password','body'=>"Hello {{name}},\n\nWe received a request to reset the password for {{email}}. Use the secure button below to choose a new password.\n\nThis one-time link expires in {{expires_in}}. If you did not request a reset, no action is required.",'button_label'=>'Reset password','footer'=>'For security, this link can be used only once.'],
        ];
        $stored = $this->cms->setting(self::TEMPLATES, []);
        if (!is_array($stored)) return $defaults;
        foreach ($defaults as $slug=>$template) $defaults[$slug] = array_replace($template,(array)($stored[$slug]??[]));
        return $defaults;
    }

    private function issue(int $userId, string $purpose, int $hours, string $ip = ''): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE email_action_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND purpose=? AND used_at IS NULL')->execute([$userId,$purpose]);
            $this->db->prepare('INSERT INTO email_action_tokens (user_id,purpose,token_hash,request_ip_hash,expires_at,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())')->execute([$userId,$purpose,hash('sha256',$token),hash('sha256',$this->baseUrl.'|'.$ip),gmdate('Y-m-d H:i:s',time()+$hours*3600)]);
            $this->db->exec('DELETE FROM email_action_tokens WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');
            $this->db->commit();
            return $token;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function invalidate(string $token): void
    {
        $this->db->prepare('UPDATE email_action_tokens SET used_at=UTC_TIMESTAMP() WHERE token_hash=?')->execute([hash('sha256',$token)]);
    }

    private function sendTemplate(string $slug, array $user, string $actionUrl, string $expires): void
    {
        $template = $this->templates()[$slug];
        $role = trim((string)($user['role_name'] ?? '')) ?: 'platform user';
        $tokens = ['{{name}}'=>(string)$user['name'],'{{site_name}}'=>'Base CMS','{{role}}'=>$role,'{{email}}'=>(string)$user['email'],'{{action_url}}'=>$actionUrl,'{{login_url}}'=>$this->baseUrl.'/login','{{expires_in}}'=>$expires];
        $this->send((string)$user['email'], strtr($template['subject'],$tokens), strtr($template['heading'],$tokens), strtr($template['body'],$tokens), strtr($template['button_label'],$tokens), $actionUrl, strtr($template['footer'],$tokens));
    }

    private function send(string $to, string $subject, string $heading, string $body, string $button, string $actionUrl, string $footer): void
    {
        $settings = $this->settings();
        $server = $settings['server'];
        if ($server['mode'] === 'disabled') throw new RuntimeException('E-mail delivery is disabled. Configure it in System - E-mail.');
        $config = ['host'=>$server['mode']==='smtp'?$server['host']:'','port'=>$server['port'],'security'=>$server['security'],'username'=>$server['username'],'password'=>$this->password(),'from_address'=>$server['from_address'],'from_name'=>$server['from_name']];
        $a = $settings['appearance'];
        $logo = $this->absolute((string)$a['logo_url']);
        $font = htmlspecialchars((string)$a['font'],ENT_QUOTES,'UTF-8');
        $paragraphs = array_values(array_filter(preg_split('/\R{2,}/u',trim($body))?:[],'strlen'));
        $content = implode('',array_map(static fn(string $part):string=>'<p style="margin:0 0 18px;line-height:1.7">'.nl2br(htmlspecialchars($part,ENT_QUOTES,'UTF-8'),false).'</p>',$paragraphs));
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:'.htmlspecialchars($a['page_bg'],ENT_QUOTES,'UTF-8').';color:'.htmlspecialchars($a['text_color'],ENT_QUOTES,'UTF-8').';font-family:'.$font.',Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:'.htmlspecialchars($a['page_bg'],ENT_QUOTES,'UTF-8').';padding:32px 12px"><tr><td align="center"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:'.htmlspecialchars($a['content_bg'],ENT_QUOTES,'UTF-8').';border-radius:18px;overflow:hidden;box-shadow:0 12px 36px rgba(15,23,42,.08)"><tr><td align="center" style="padding:38px 40px 24px"><img src="'.htmlspecialchars($logo,ENT_QUOTES,'UTF-8').'" width="220" alt="SenseCMS" style="display:block;width:220px;max-width:70%;height:auto"></td></tr><tr><td style="padding:8px 40px 40px"><h1 style="margin:0 0 22px;color:'.htmlspecialchars($a['heading_color'],ENT_QUOTES,'UTF-8').';font-size:28px;line-height:1.25;text-align:center">'.htmlspecialchars($heading,ENT_QUOTES,'UTF-8').'</h1>'.$content.'<p style="margin:28px 0;text-align:center"><a href="'.htmlspecialchars($actionUrl,ENT_QUOTES,'UTF-8').'" style="display:inline-block;padding:14px 24px;border-radius:10px;background:'.htmlspecialchars($a['accent_color'],ENT_QUOTES,'UTF-8').';color:#fff;font-weight:700;text-decoration:none">'.htmlspecialchars($button,ENT_QUOTES,'UTF-8').'</a></p><p style="margin:24px 0 0;color:'.htmlspecialchars($a['muted_color'],ENT_QUOTES,'UTF-8').';font-size:13px;line-height:1.6">'.htmlspecialchars($footer,ENT_QUOTES,'UTF-8').'</p></td></tr></table><p style="margin:18px 0 0;color:'.htmlspecialchars($a['muted_color'],ENT_QUOTES,'UTF-8').';font-size:12px">Secure message from Base CMS</p></td></tr></table></body></html>';
        $text = $heading."\n\n".$body."\n\n".$button.': '.$actionUrl."\n\n".$footer;
        (new MailService($config))->sendHtml($to,$subject,$html,$text);
    }

    private function password(): string
    {
        $settings = $this->settings();
        $cipher = (string)($settings['server']['password_cipher'] ?? '');
        if ($cipher !== '') return $this->secrets->decrypt($cipher);
        return (string)($this->config['mail']['password'] ?? '');
    }

    private function user(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT u.id,u.name,u.email,(SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id ORDER BY ur.role_id LIMIT 1) role_name FROM users u WHERE u.id=? AND u.active=1 LIMIT 1');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    private function storeLogo(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new RuntimeException('The logo upload could not be verified.');
        if ((int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 2_000_000) throw new RuntimeException('The e-mail logo must be smaller than 2 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        if (!isset($types[$mime])) throw new RuntimeException('Upload a PNG, JPEG or WebP e-mail logo.');
        $dir = dirname(__DIR__,2).'/public/media/email';
        if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('The e-mail asset directory could not be created.');
        $name = 'logo-'.bin2hex(random_bytes(10)).'.'.$types[$mime];
        if (!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('The e-mail logo could not be stored.');
        @chmod($dir.'/'.$name,0640);
        return '/media/email/'.$name;
    }

    private function validAssetUrl(string $url): bool
    {
        if (str_starts_with($url,'/') && !str_starts_with($url,'//') && !preg_match('/[\x00-\x1f<>]/',$url)) return true;
        return filter_var($url,FILTER_VALIDATE_URL)!==false && strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https';
    }

    private function absolute(string $url): string { return str_starts_with($url,'/') ? rtrim($this->baseUrl,'/').$url : $url; }
}
