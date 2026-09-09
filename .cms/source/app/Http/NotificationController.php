<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\ExtensionContext;
use App\Core\NotificationChannels;
use App\Core\LicenseService;
use App\Core\WhatsAppOnboardingClient;
use App\Core\TelegramConnectionClient;
use RuntimeException;
use Throwable;

final class NotificationController
{
    public function __construct(private readonly ExtensionContext $context) {}

    public function handle(string $method): never
    {
        $c=$this->context;
        if (!$c->auth->check()) { header('Location: /login',true,302);exit; }
        $c->access->assert('system.manage');
        $channels=new NotificationChannels($c->db,$c->root,(string)$c->config['secrets_key']);
        if ($method==='POST') {
            header('Content-Type: application/json');header('Cache-Control: no-store');
            try {
                if ($c->access->isDemoUser()) throw new RuntimeException('Demo mode is read only. Changes cannot be saved.',403);
                if (!$c->auth->verifyCsrf($_POST['csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.',419);
                $slug=(string)($_POST['slug']??'');$channels->save($slug,$_POST);
                $c->db->prepare('INSERT INTO activity_log(user_id,event,subject_type,context,created_at) VALUES (?,"notifications.configured","plugin",?,NOW())')->execute([$c->auth->id(),json_encode(['slug'=>(string)$_POST['slug'],'enabled'=>!empty($_POST['enabled']),'consent_confirmed'=>!empty($_POST['consent_confirmed'])],JSON_THROW_ON_ERROR)]);
                echo json_encode(['ok'=>true,'message'=>!empty($_POST['enabled'])?'Notification channel saved and verified.':'Notification channel disabled.','redirect'=>'/system/notifications']);
            } catch (Throwable $error) {
                $safe=$error instanceof RuntimeException && !$error instanceof \PDOException;
                http_response_code($safe && in_array($error->getCode(),[403,419],true)?$error->getCode():422);
                echo json_encode(['ok'=>false,'message'=>$safe?$error->getMessage():'The notification channel could not be saved.']);
            }
            exit;
        }
        $deliveries=$c->db->query('SELECT plugin_slug,status,COUNT(*) total FROM notification_deliveries GROUP BY plugin_slug,status')->fetchAll();
        $webPushDeliveries=$c->db->query('SELECT status,COUNT(*) total FROM web_push_deliveries GROUP BY status ORDER BY status')->fetchAll();
        $webPushStats=$c->db->query('SELECT COUNT(*) devices,COUNT(DISTINCT user_id) users,MAX(last_success_at) last_success_at FROM web_push_subscriptions WHERE active=1 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())')->fetch()?:[];
        $users=$c->db->query('SELECT id,name FROM users WHERE active=1 AND is_demo=0 ORDER BY name,id')->fetchAll();
        $telegramRecipients=null;try{$telegramRecipients=count($this->telegramClient()->recipients());}catch(Throwable){}
        $c->dashboard->notificationSettings(['notificationChannels'=>$channels->catalog(true),'notificationDeliveries'=>$deliveries,'notificationUsers'=>$users,'webPushDeliveries'=>$webPushDeliveries,'webPushStats'=>$webPushStats,'telegramRecipients'=>$telegramRecipients,'telegramProfileManage'=>$c->access->allows('system.owner')&&$channels->canManageTelegramProfile()]);
    }

    public function connect(): never
    {
        $c=$this->context; $this->guard();
        try {
            if ($c->access->isDemoUser()) throw new RuntimeException('Demo mode is read only. External services cannot be connected.',403);
            if (!$c->auth->verifyCsrf($_POST['csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.',419);
            header('Cache-Control: no-store'); header('Location: '.$this->client()->start(),true,303); exit;
        } catch (Throwable $error) { $this->fail($error); }
    }

    public function callback(): never
    {
        $c=$this->context; $this->guard();
        try {
            if ($c->access->isDemoUser()) throw new RuntimeException('Demo mode is read only. External services cannot be connected.',403);
            $claim=(string)($_GET['claim']??''); $credentials=$this->client()->claim($claim); (new NotificationChannels($c->db,$c->root,(string)$c->config['secrets_key']))->provisionWhatsApp($credentials);
            $c->db->prepare('INSERT INTO activity_log(user_id,event,subject_type,context,created_at) VALUES (? ,"notifications.whatsapp.connected","plugin",?,NOW())')->execute([$c->auth->id(),json_encode(['slug'=>'whatsapp-notifications'],JSON_THROW_ON_ERROR)]);
            $_SESSION['flash']='WhatsApp Business is connected securely. Complete the recipient and approved template settings, then verify and enable the channel.'; $_SESSION['flash_type']='success'; header('Cache-Control: no-store'); header('Location: /system/notifications',true,303); exit;
        } catch (Throwable $error) { $this->fail($error); }
    }

    public function telegramPhoto(): never
    {
        $c=$this->context; $this->guard();
        try {
            $photo=(new NotificationChannels($c->db,$c->root,(string)$c->config['secrets_key']))->telegramProfilePhoto();
            if (!$photo) { http_response_code(404); header('Cache-Control: private, no-store'); exit; }
            header('Content-Type: '.(string)$photo['mime']); header('Content-Length: '.strlen((string)$photo['body'])); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); echo $photo['body']; exit;
        } catch (Throwable) { http_response_code(503); header('Cache-Control: private, no-store'); exit; }
    }

    public function telegramProfile(string $action): never
    {
        $c=$this->context; $this->guard(); header('Content-Type: application/json'); header('Cache-Control: no-store');
        try {
            $c->access->assert('system.owner');
            if ($c->access->isDemoUser()) throw new RuntimeException('Demo mode is read only. The bot profile cannot be changed.',403);
            if (!$c->auth->verifyCsrf($_POST['csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.',419);
            $channels=new NotificationChannels($c->db,$c->root,(string)$c->config['secrets_key']);
            if ($action==='set') { $channels->setTelegramProfilePhoto((array)($_FILES['photo']??[])); $event='notifications.telegram.profile.updated'; $message='Telegram bot profile photo updated.'; }
            elseif ($action==='remove') { $channels->removeTelegramProfilePhoto(); $event='notifications.telegram.profile.removed'; $message=$channels->canManageTelegramProfile()?'Default Sense CMS logo restored.':'Telegram bot profile photo removed.'; }
            else throw new RuntimeException('The Telegram profile operation is invalid.');
            $c->db->prepare('INSERT INTO activity_log(user_id,event,subject_type,context,created_at) VALUES (?,? ,"plugin",?,NOW())')->execute([$c->auth->id(),$event,json_encode(['slug'=>'telegram-notifications'],JSON_THROW_ON_ERROR)]);
            echo json_encode(['ok'=>true,'message'=>$message,'redirect'=>'/system/notifications']);
        } catch (Throwable $error) {
            $safe=$error instanceof RuntimeException && !$error instanceof \PDOException; http_response_code($safe&&in_array($error->getCode(),[403,419],true)?$error->getCode():422); echo json_encode(['ok'=>false,'message'=>$safe?$error->getMessage():'The Telegram bot profile could not be changed.']);
        }
        exit;
    }

    private function guard(): void
    {
        $c=$this->context; if(!$c->auth->check()){header('Location: /login',true,302);exit;} $c->access->assert('system.manage');
    }

    private function client(): WhatsAppOnboardingClient
    {
        if (!method_exists(LicenseService::class, 'marketplaceHeaders')) throw new RuntimeException('The central WhatsApp license integration is not available in this Sense CMS installation.');
        $c=$this->context; return new WhatsAppOnboardingClient((new \App\Core\Runtime($c->root))->license(),['base_url'=>(string)($c->config['integrations']['whatsapp_onboarding_url']??'')],(string)$c->config['base_url']);
    }

    private function telegramClient(): TelegramConnectionClient
    {
        $c=$this->context;return new TelegramConnectionClient((new \App\Core\Runtime($c->root))->license(),['base_url'=>(string)($c->config['integrations']['telegram_broker_url']??'')],(string)$c->config['base_url']);
    }

    private function fail(Throwable $error): never
    {
        $safe=$error instanceof RuntimeException && !$error instanceof \PDOException; $_SESSION['flash']=$safe?$error->getMessage():'WhatsApp Business could not be connected.'; $_SESSION['flash_type']='warning'; header('Cache-Control: no-store'); header('Location: /system/notifications',true,303); exit;
    }
}
