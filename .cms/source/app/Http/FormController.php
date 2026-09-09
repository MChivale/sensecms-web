<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\CaptchaService;
use App\Core\CmsRepository;
use App\Core\MailService;
use App\Core\EmailSystem;
use App\Core\FormMail;

final class FormController extends Controller
{
    public function __construct(private readonly CmsRepository $cms, private readonly array $config, private readonly ?EmailSystem $emailSystem = null) {}

    public function captcha(string $uid): never
    {
        if (!$this->cms->contactForm($uid, 'en', 'en')) { http_response_code(404); exit; }
        $service = new CaptchaService('form_'.$uid); $settings = $service->config(array_replace((array)$this->cms->setting('captcha_settings', []), ['enabled'=>true])); $service->saveCodeForImage($settings); $service->image();
    }

    public function submit(string $uid): never
    {
        $locale = is_string($_POST['locale'] ?? null) ? strtolower(trim($_POST['locale'])) : ''; $copy=$this->copy($locale); $active = array_column($this->cms->languages(), 'locale');
        if (!$this->wantsJson()) $this->result(false, $copy['ajax'], null, 406);
        $states=(array)$this->cms->setting('extension_states',[]);if(array_key_exists('forms',$states)&&!$states['forms'])$this->result(false,$copy['unavailable'],null,503);
        if (!is_string($_POST['csrf'] ?? null) || empty($_SESSION['public_form_csrf']) || !hash_equals($_SESSION['public_form_csrf'], $_POST['csrf'])) $this->result(false, $copy['session'], null, 419);
        if (!is_string($_POST['website'] ?? '') || trim((string) ($_POST['website'] ?? '')) !== '') $this->result(false, $copy['configuration'], null, 422);
        if (!in_array($locale, $active, true)) $this->result(false, $copy['language'], null, 422);
        $form = $this->cms->contactForm($uid, $locale, (string) ($this->config['default_locale'] ?? 'en'));
        if (!$form) $this->result(false, $copy['unavailable'], null, 404);
        $settings = $form['settings']; $limit = max(1, min(20, (int) ($settings['rate_limit'] ?? 5))); $visitorHash=$this->visitorHash(); $this->enforceRateLimit($uid, $limit, $copy['rate']);
        if(($settings['store_submissions']??true)&&$this->cms->recentFormSubmissionCount($uid,$visitorHash,date('Y-m-d H:i:s',time()-900))>=$limit)$this->result(false,$copy['rate'],null,429);
        $this->recordRate($uid);
        $captchaSettings = (new CaptchaService('form_'.$uid))->config(array_replace((array)$this->cms->setting('captcha_settings', []), ['enabled'=>true]));
        if (($settings['captcha'] ?? true) || !empty($settings['sender_copy'])) {
            if (!(new CaptchaService('form_'.$uid))->valid(is_string($_POST['captcha']??null)?$_POST['captcha']:'', $captchaSettings)) $this->result(false, $copy['captcha'], null, 422, ['captcha_url'=>'/captcha/forms/'.$uid.'.png?t='.time()]);
        }
        try { $payload = $this->payload((array) ($form['data']['fields'] ?? []), is_array($_POST['fields']??null)?$_POST['fields']:[], $copy); }
        catch (\RuntimeException $error) { $this->result(false, $error->getMessage(), null, 422, ['captcha_url'=>'/captcha/forms/'.$uid.'.png?t='.time()]); }
        $store = (bool) ($settings['store_submissions'] ?? true); $notify = (bool) ($settings['email_notifications'] ?? true);
        if (!$store && !$notify) $this->result(false, $copy['unavailable'], null, 503);
        $submission = null;
        if ($store) $submission = $this->cms->saveFormSubmission($form, $locale, $payload, $visitorHash);
        $receipt = null;
        if ($notify) {
            try {
                $recipient = trim((string) ($settings['recipient_email'] ?? $this->config['mail']['recipient'] ?? '')) ?: (string) ($this->config['mail']['recipient'] ?? '');
                $mailer = $this->emailSystem?->mailer() ?? new MailService((array) ($this->config['mail'] ?? []));
                $reference = (string) ($submission['uid'] ?? bin2hex(random_bytes(8)));
                $message = FormMail::render($form, $payload, $reference, false, $this->emailSystem?->formBrand() ?? []);
                $mailer->sendHtml($recipient, $message['subject'], $message['html'], $message['text'], $this->replyTo($payload));
                if ($submission) $this->cms->markSubmissionNotified((int) $submission['id']);
            } catch (\Throwable $error) {
                error_log('Form mail notification failed: ' . $error->getMessage());
                if (!$store) $this->result(false, $copy['delivery'], null, 503, ['captcha_url'=>'/captcha/forms/'.$uid.'.png?t='.time()]);
            }
        }
        if ($submission && !empty($settings['sender_copy']) && filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            try {
                if ($this->cms->recentFormRecipientCount($payload['email']) > 4) throw new \RuntimeException('Receipt daily limit reached.');
                $message = FormMail::render($form, $payload, (string)$submission['uid'], true, $this->emailSystem?->formBrand() ?? []);
                ($this->emailSystem?->mailer() ?? new MailService((array)($this->config['mail']??[])))->sendHtml($payload['email'],$message['subject'],$message['html'],$message['text']);
                $receipt = true;
            } catch (\Throwable $error) { $receipt = false; error_log('Form receipt delivery failed for submission ' . (int)$submission['id']); }
        }
        $message = trim((string) ($form['data']['success_message'] ?? '')) ?: 'Thank you. Your message has been received.';
        if ($receipt === true) $message .= ' A copy has been sent to your email address.';
        if ($receipt === false) $message .= ' Your message is saved, but we could not send the email copy. Please do not submit it again.';
        $this->result(true, $message, null, 200, ['receipt_sent'=>$receipt,'captcha_url'=>'/captcha/forms/'.$uid.'.png?t='.time()]);
    }

    private function payload(array $schema, array $input, array $copy): array
    {
        $payload = []; $seen = [];
        foreach ($schema as $field) {
            if (!is_array($field)) continue; $key = strtolower(trim((string) ($field['key'] ?? '')));
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) || isset($seen[$key])) throw new \RuntimeException($copy['configuration']);
            $seen[$key] = true; $type = (string) ($field['type'] ?? 'text'); $label = trim((string) ($field['label'] ?? $key)); $required = (bool) ($field['required'] ?? false); $raw = $input[$key] ?? '';
            if ($type === 'checkbox') { $value = is_string($raw) && in_array($raw, ['1','on','yes','true'], true); if ($required && !$value) throw new \RuntimeException(sprintf($copy['accepted'],$label)); $payload[$key] = $value; continue; }
            if (is_array($raw)) $raw = ''; $value = trim((string) $raw); $max = $type === 'textarea' ? 5000 : 500;
            if ($required && $value === '') throw new \RuntimeException(sprintf($copy['required'],$label));
            if (mb_strlen($value) > $max) throw new \RuntimeException(sprintf($copy['long'],$label));
            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException($copy['email']);
            if ($type === 'tel' && $value !== '' && !preg_match('/^[0-9+().\s-]{5,40}$/', $value)) throw new \RuntimeException($copy['phone']);
            if ($type === 'select' && $value !== '') { $options = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($field['options'] ?? '')) ?: []))); if (!in_array($value, $options, true)) throw new \RuntimeException(sprintf($copy['option'],$label)); }
            $payload[$key] = $value;
        }
        return $payload;
    }

    private function replyTo(array $payload):?string { foreach($payload as$value)if(is_string($value)&&filter_var($value,FILTER_VALIDATE_EMAIL))return$value;return null; }
    private function visitorHash(): string { $key=(string)($this->config['secrets_key']??'sensecms-form');return hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),$key?:'sensecms-form'); }
    private function enforceRateLimit(string $uid,int $limit,string $message):void { $key='form_rate_'.$uid;$now=time();$attempts=array_values(array_filter((array)($_SESSION[$key]??[]),static fn($time):bool=>(int)$time>$now-900));$_SESSION[$key]=$attempts;if(count($attempts)>=$limit)$this->result(false,$message,null,429); }
    private function recordRate(string $uid):void { $_SESSION['form_rate_'.$uid][]=time(); }
    private function copy(string $locale):array
    {
        return match($locale){
            'km'=>['ajax'=>'តម្រូវឱ្យប្រើសំណើ AJAX។','session'=>'សម័យបែបបទរបស់អ្នកបានផុតកំណត់។ សូមផ្ទុកទំព័រឡើងវិញ ហើយព្យាយាមម្ដងទៀត។','sent'=>'អរគុណ។ សាររបស់អ្នកត្រូវបានផ្ញើរួចហើយ។','language'=>'ភាសាដែលបានជ្រើសរើសមិនអាចប្រើបានទេ។','unavailable'=>'បែបបទនេះមិនអាចប្រើបានជាបណ្ដោះអាសន្នទេ។','delivery'=>'សាររបស់អ្នកមិនអាចផ្ញើបានទេ។ សូមព្យាយាមម្ដងទៀតនៅពេលក្រោយ។','rate'=>'អ្នកបានផ្ញើសារច្រើនពេក។ សូមព្យាយាមម្ដងទៀតនៅពេលក្រោយ។','captcha'=>'លេខកូដសុវត្ថិភាពមិនត្រឹមត្រូវទេ។ សូមសាកល្បងលេខកូដថ្មី។','configuration'=>'ការកំណត់រចនាសម្ព័ន្ធបែបបទមិនត្រឹមត្រូវទេ។','accepted'=>'ត្រូវតែយល់ព្រមលើ %s។','required'=>'%s គឺតម្រូវឱ្យបំពេញ។','long'=>'%s វែងពេក។','email'=>'សូមបញ្ចូលអាសយដ្ឋានអ៊ីមែលត្រឹមត្រូវ។','phone'=>'សូមបញ្ចូលលេខទូរស័ព្ទត្រឹមត្រូវ។','option'=>'សូមជ្រើសរើសជម្រើសត្រឹមត្រូវសម្រាប់ %s។'],
            'zh'=>['ajax'=>'必须使用 AJAX 请求。','session'=>'表单会话已过期，请刷新页面后重试。','sent'=>'谢谢，您的消息已发送。','language'=>'所选语言不可用。','unavailable'=>'此表单暂时不可用。','delivery'=>'消息发送失败，请稍后重试。','rate'=>'发送次数过多，请稍后再试。','captcha'=>'安全验证码不正确，请更换验证码后重试。','configuration'=>'表单配置无效。','accepted'=>'必须同意%s。','required'=>'%s为必填项。','long'=>'%s内容过长。','email'=>'请输入有效的电子邮箱地址。','phone'=>'请输入有效的电话号码。','option'=>'请为%s选择有效选项。'],
            default=>['ajax'=>'AJAX requests are required.','session'=>'Your form session expired. Refresh the page and try again.','sent'=>'Thank you. Your message has been sent.','language'=>'The selected language is unavailable.','unavailable'=>'This form is temporarily unavailable.','delivery'=>'Your message could not be delivered. Please try again later.','rate'=>'Too many messages were sent. Please try again later.','captcha'=>'The security code is incorrect. Try a new code.','configuration'=>'The form configuration is invalid.','accepted'=>'%s must be accepted.','required'=>'%s is required.','long'=>'%s is too long.','email'=>'Enter a valid email address.','phone'=>'Enter a valid phone number.','option'=>'Choose a valid option for %s.'],
        };
    }
}
