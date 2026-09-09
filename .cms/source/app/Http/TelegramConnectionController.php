<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\ExtensionContext;
use App\Core\Runtime;
use App\Core\TelegramConnectionClient;
use RuntimeException;
use Throwable;

final class TelegramConnectionController
{
    public function __construct(private readonly ExtensionContext$context){}

    public function handle(string$method):never
    {
        $c=$this->context;header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try{
            if(!$c->auth->check())throw new RuntimeException('Your session has expired.',401);$c->access->assert('console.access');$user=$c->auth->user();$id=(int)($user['id']??0);
            if(!$this->installed()){$this->respond(true,['available'=>false,'connected'=>false],'Install and activate Telegram Notifications to connect your account.');}
            if($method==='GET'){$state=$this->client()->status($id);$this->respond(true,['available'=>true]+$state,null);}
            $input=$this->input();if(!$c->auth->verifyCsrf($input['csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??null)))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
            if($c->access->isDemoUser())throw new RuntimeException('Demo mode is read only. Telegram cannot be connected.',403);$action=(string)($input['action']??'');$client=$this->client();
            if($action==='start'){$result=$client->start($id,(string)($user['name']??''));$this->respond(true,['available'=>true,'connected'=>false]+$result,'Scan the QR code or open Telegram to finish connecting.');}
            if($action==='status'){$state=$client->status($id,(string)($input['request_token']??''));if($state['connected'])$this->audit('notifications.telegram.user.connected');$this->respond(true,['available'=>true]+$state,$state['connected']?'Telegram connected successfully.':'Waiting for Telegram confirmation.');}
            if($action==='disconnect'){$client->disconnect($id);$this->audit('notifications.telegram.user.disconnected');$this->respond(true,['available'=>true,'connected'=>false],'Telegram disconnected from this CMS account.');}
            if($action==='test'){$client->deliver($id,hash('sha256','test:'.$c->config['base_url'].':'.$id.':'.bin2hex(random_bytes(8))),'Telegram test','This private test confirms notifications from '.(string)parse_url((string)$c->config['base_url'],PHP_URL_HOST).".\n".(string)$c->config['base_url'].'/settings');$this->respond(true,['available'=>true]+$client->status($id),'Test notification sent.');}
            throw new RuntimeException('The Telegram action is invalid.',422);
        }catch(Throwable$error){$safe=$error instanceof RuntimeException&&!$error instanceof \PDOException;$status=$safe&&in_array($error->getCode(),[400,401,403,404,409,413,415,419,422,429,502,503],true)?$error->getCode():422;$this->respond(false,[],$safe?$error->getMessage():'Telegram could not be changed.',$status);}
    }

    private function installed():bool{$statement=$this->context->db->prepare("SELECT 1 FROM extension_packages WHERE type='plugin' AND slug='telegram-notifications' AND active=1 LIMIT 1");$statement->execute();return(bool)$statement->fetchColumn();}
    private function client():TelegramConnectionClient{$c=$this->context;return new TelegramConnectionClient((new Runtime($c->root))->license(),['base_url'=>(string)($c->config['integrations']['telegram_broker_url']??'')],(string)$c->config['base_url']);}
    private function input():array{if(!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))throw new RuntimeException('JSON request required.',415);if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)throw new RuntimeException('The request body is too large.',413);try{$data=json_decode((string)file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('The request body is invalid.',400);};return is_array($data)?$data:[];}
    private function audit(string$event):void{$c=$this->context;$c->db->prepare('INSERT INTO activity_log(user_id,event,subject_type,context,created_at) VALUES (?,? ,"user",?,NOW())')->execute([$c->auth->id(),$event,json_encode(['provider'=>'telegram'],JSON_THROW_ON_ERROR)]);}
    private function respond(bool$ok,array$data,?string$message,int$status=200):never{http_response_code($status);echo json_encode(['ok'=>$ok,'data'=>$data,'message'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
}
