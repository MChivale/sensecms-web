<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\ExtensionContext;
use App\Core\WebPushSubscriptions;
use RuntimeException;
use Throwable;

final class WebPushController extends Controller
{
    public function __construct(private readonly ExtensionContext $context) {}

    public function handle(string $method): never
    {
        header('Cache-Control: no-store');
        if (!$this->context->auth->check()) $this->result(false,'Authentication is required.',null,401);
        $push=new WebPushSubscriptions($this->context->db,$this->context->config,$this->context->root);$userId=$this->context->auth->id()??0;
        if ($method==='GET') $this->result(true,'Web Push status loaded.',null,200,$push->status($userId));
        try {
            if ($this->context->access->isDemoUser()) throw new RuntimeException('Demo mode is read only. Browser notifications cannot be changed.',403);
            $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
            $token=(string)($input['csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));if(!$this->context->auth->verifyCsrf($token))throw new RuntimeException('Your session expired. Refresh and try again.',419);
            $action=(string)($input['action']??'');$deliveryError=false;
            if($action==='subscribe'){$push->subscribe($userId,(array)($input['subscription']??[]),(string)($_SERVER['HTTP_USER_AGENT']??''));$message='Browser notifications enabled for this device.';}
            elseif($action==='unsubscribe'){$push->unsubscribe($userId,(string)($input['endpoint']??''));$message='Browser notifications disabled for this device.';}
            elseif($action==='unsubscribe_all'){$push->unsubscribeAll($userId);$message='Browser notifications revoked for all devices.';}
            elseif($action==='preferences'){$push->savePreferences($userId,(array)($input['sources']??[]));$message='Browser notification preferences saved.';}
            elseif($action==='test'){$queued=$push->queueTest($userId);if(!$queued)throw new RuntimeException('Enable browser notifications on this device before sending a test.');$result=$push->deliver(10,microtime(true)+14,$userId);$deliveryError=($result['web_push_sent']??0)===0&&(($result['web_push_expired']??0)+($result['web_push_failed']??0)+($result['web_push_unknown']??0)+($result['web_push_skipped']??0)>0);$message=($result['web_push_sent']??0)>0?'The push service accepted the test notification.':(($result['web_push_expired']??0)>0?'The push service rejected an expired subscription. Enable Web Push again to renew this browser.':($deliveryError?'The push service did not confirm delivery. Check delivery history before retrying.':'The test notification was queued; delivery is not yet confirmed.'));}
            else throw new RuntimeException('The Web Push action is invalid.');
            $this->context->db->prepare('INSERT INTO activity_log(user_id,event,subject_type,context,created_at) VALUES (?,"web_push.changed","user",?,NOW())')->execute([$userId,json_encode(['action'=>$action],JSON_THROW_ON_ERROR)]);
            $this->result(!$deliveryError,$message,null,$deliveryError?422:200,$push->status($userId));
        } catch(Throwable$error){$safe=$error instanceof RuntimeException&&!$error instanceof \PDOException;$code=$safe&&in_array($error->getCode(),[403,419],true)?$error->getCode():422;$this->result(false,$safe?$error->getMessage():'Browser notifications could not be changed.',null,$code);}
    }
}
