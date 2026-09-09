<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class WebPushSubscriptions
{
    public const SOURCES=['user','chat','form','survey','calendar','update','license'];
    private Secrets $secrets;
    private WebPushKeyStore $keys;

    public function __construct(private readonly PDO $db,private readonly array $config,private readonly string $root)
    {
        $this->secrets=new Secrets((string)($config['secrets_key']??''));
        $this->keys=new WebPushKeyStore($root,(array)($config['web_push']??[]));
    }

    public function available(): bool
    {
        $base=(string)($this->config['base_url']??'');
        if (!filter_var($base,FILTER_VALIDATE_URL)||strtolower((string)parse_url($base,PHP_URL_SCHEME))!=='https') return false;
        try{$this->keys->ensure();return true;}catch(Throwable){return false;}
    }

    public function hasActive(): bool { return (bool)$this->db->query('SELECT 1 FROM web_push_subscriptions WHERE active=1 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) LIMIT 1')->fetchColumn(); }
    public function since(): ?string { $value=$this->db->query('SELECT MIN(created_at) FROM web_push_subscriptions WHERE active=1')->fetchColumn();return is_string($value)&&$value!==''?$value:null; }

    public function status(int $userId): array
    {
        $preference=$this->preference($userId);
        $statement=$this->db->prepare('SELECT endpoint_hash,device_label,last_seen_at,last_success_at,last_failure_at,failure_count FROM web_push_subscriptions WHERE user_id=? AND active=1 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) ORDER BY last_seen_at DESC');$statement->execute([$userId]);$devices=$statement->fetchAll();
        $key=$this->available()?$this->keys->ensure()['public_key']:null;
        return ['available'=>$key!==null,'public_key'=>$key,'enabled'=>$preference['enabled'],'sources'=>$preference['sources'],'source_options'=>self::SOURCES,'subscription_hashes'=>array_column($devices,'endpoint_hash'),'devices'=>$devices];
    }

    public function subscribe(int $userId,array $input,string $userAgent): void
    {
        if (!$this->available()) throw new RuntimeException('Web Push requires a valid HTTPS installation and writable private key storage.');
        $endpoint=trim((string)($input['endpoint']??''));$keys=(array)($input['keys']??[]);$p256dh=(string)($keys['p256dh']??'');$auth=(string)($keys['auth']??'');
        $this->validateSubscription($endpoint,$p256dh,$auth);
        (new WebPushSender($this->keys,(array)($this->config['web_push']??[])))->assertEndpoint($endpoint);
        $hash=hash('sha256',$endpoint);$check=$this->db->prepare('SELECT id,user_id,active,last_error FROM web_push_subscriptions WHERE endpoint_hash=?');$check->execute([$hash]);$existing=$check->fetch();
        if ($existing&&(int)$existing['user_id']!==$userId) throw new RuntimeException('This browser subscription is already assigned to another account. Revoke browser permission before trying again.');
        if ($existing&&!$existing['active']&&$existing['last_error']==='Browser subscription expired.') throw new RuntimeException('This browser subscription has expired. Refresh My settings and enable Web Push to create a new subscription.');
        $expiration=$input['expirationTime']??null;$expires=null;
        if (is_int($expiration)||is_float($expiration)||(is_string($expiration)&&ctype_digit($expiration))) { $seconds=(int)floor(((float)$expiration)/1000);if($seconds>time()&&$seconds<time()+315360000)$expires=gmdate('Y-m-d H:i:s',$seconds); }
        $encoding=(string)($input['contentEncoding']??'aes128gcm');if($encoding!=='aes128gcm')throw new RuntimeException('This browser content encoding is not supported.');
        $encrypted=$this->secrets->encrypt(json_encode(['endpoint'=>$endpoint,'p256dh'=>$p256dh,'auth'=>$auth],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$label=$this->deviceLabel($userAgent);
        $this->db->beginTransaction();
        try {
            if ($existing) $this->db->prepare('UPDATE web_push_subscriptions SET encrypted_subscription=?,device_label=?,content_encoding=?,active=1,expires_at=?,last_seen_at=UTC_TIMESTAMP(),failure_count=0,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$encrypted,$label,$encoding,$expires,$existing['id'],$userId]);
            else $this->db->prepare('INSERT INTO web_push_subscriptions(user_id,endpoint_hash,encrypted_subscription,device_label,content_encoding,active,expires_at,last_seen_at,created_at,updated_at) VALUES(?,?,?,?,?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$userId,$hash,$encrypted,$label,$encoding,$expires]);
            $this->db->prepare('INSERT INTO web_push_preferences(user_id,enabled,sources,created_at,updated_at) VALUES(?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,updated_at=UTC_TIMESTAMP()')->execute([$userId,json_encode(self::SOURCES,JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function unsubscribe(int $userId,string $endpoint): void
    {
        if ($endpoint===''||strlen($endpoint)>2048) throw new RuntimeException('The browser subscription is invalid.');
        $hash=hash('sha256',$endpoint);$this->db->beginTransaction();
        try{$ids=$this->db->prepare('SELECT id FROM web_push_subscriptions WHERE user_id=? AND endpoint_hash=?');$ids->execute([$userId,$hash]);$id=(int)($ids->fetchColumn()?:0);if($id){$this->db->prepare('DELETE FROM web_push_deliveries WHERE subscription_id=?')->execute([$id]);$this->db->prepare('DELETE FROM web_push_subscriptions WHERE id=? AND user_id=?')->execute([$id,$userId]);}$this->db->commit();}
        catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function unsubscribeAll(int $userId): void
    {
        $this->db->beginTransaction();
        try{$this->db->prepare('DELETE d FROM web_push_deliveries d INNER JOIN web_push_subscriptions s ON s.id=d.subscription_id WHERE s.user_id=?')->execute([$userId]);$this->db->prepare('DELETE FROM web_push_subscriptions WHERE user_id=?')->execute([$userId]);$this->db->prepare('UPDATE web_push_preferences SET enabled=0,updated_at=UTC_TIMESTAMP() WHERE user_id=?')->execute([$userId]);$this->db->commit();}
        catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function savePreferences(int $userId,array $sources): void
    {
        $sources=array_values(array_intersect(self::SOURCES,array_unique(array_map('strval',$sources))));
        $this->db->prepare('INSERT INTO web_push_preferences(user_id,enabled,sources,created_at,updated_at) VALUES(?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,sources=VALUES(sources),updated_at=UTC_TIMESTAMP()')->execute([$userId,json_encode($sources,JSON_THROW_ON_ERROR)]);
    }

    public function enqueue(array $event,NotificationAudience $audience): int
    {
        if (!empty($event['channels'])&&!in_array('web-push',$event['channels'],true)) return 0;
        if (!in_array((string)($event['source']??''),self::SOURCES,true)) return 0;
        $query='SELECT s.id,s.user_id,s.created_at,p.sources FROM web_push_subscriptions s INNER JOIN users u ON u.id=s.user_id AND u.active=1 AND u.is_demo=0 LEFT JOIN web_push_preferences p ON p.user_id=s.user_id WHERE s.active=1 AND (s.expires_at IS NULL OR s.expires_at>UTC_TIMESTAMP()) AND COALESCE(p.enabled,1)=1';$values=[];
        if (!empty($event['user_id'])){$query.=' AND s.user_id=?';$values[]=(int)$event['user_id'];}
        $statement=$this->db->prepare($query);$statement->execute($values);$insert=$this->db->prepare('INSERT INTO web_push_deliveries(subscription_id,user_id,event_key,payload,status,attempts,next_attempt_at,created_at,updated_at) VALUES(?,?,?,? ,"pending",0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id');$count=0;$audienceCache=[];
        foreach($statement->fetchAll()as$subscription){$sources=$this->sources((string)($subscription['sources']??''));if(!in_array($event['source'],$sources,true)||($event['created_at']??'')<$subscription['created_at'])continue;$userId=(int)$subscription['user_id'];if(!array_key_exists($userId,$audienceCache))$audienceCache[$userId]=$audience->allows($userId,$event);if(!$audienceCache[$userId])continue;$payload=$this->payload($event);$insert->execute([(int)$subscription['id'],$userId,hash('sha256',(string)$event['key']),json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$count+=$insert->rowCount()>0?1:0;}
        return $count;
    }

    public function queueTest(int $userId): int
    {
        $statement=$this->db->prepare('SELECT id FROM web_push_subscriptions WHERE user_id=? AND active=1 AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())');$statement->execute([$userId]);$insert=$this->db->prepare('INSERT INTO web_push_deliveries(subscription_id,user_id,event_key,payload,status,attempts,next_attempt_at,created_at,updated_at) VALUES(?,?,?,? ,"pending",0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=IF(status="sent",status,"pending"),next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()');$count=0;
        $key='test:'.bin2hex(random_bytes(12));$payload=['title'=>'SenseCMS notifications are ready','body'=>'This browser can now receive secure SenseCMS alerts.','url'=>'/dashboard','tag'=>'web-push-test-'.bin2hex(random_bytes(6)),'source'=>'web_push_test','test'=>true,'timestamp'=>gmdate(DATE_ATOM)];
        foreach($statement->fetchAll(PDO::FETCH_COLUMN)as$id){$insert->execute([(int)$id,$userId,hash('sha256',$key),json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$count++;}return$count;
    }

    public function recover(): void { $this->db->exec("UPDATE web_push_deliveries SET status='unknown',last_error='Interrupted send; delivery acceptance is indeterminate.',updated_at=UTC_TIMESTAMP() WHERE status='processing'"); }

    public function deliver(int $limit,float $deadline,?int $onlyUser=null): array
    {
        $sql='SELECT d.*,s.encrypted_subscription,s.active,s.expires_at,p.enabled preference_enabled,p.sources FROM web_push_deliveries d INNER JOIN web_push_subscriptions s ON s.id=d.subscription_id LEFT JOIN web_push_preferences p ON p.user_id=d.user_id WHERE d.status="pending" AND d.next_attempt_at<=UTC_TIMESTAMP()'.($onlyUser!==null?' AND d.user_id=?':'').' ORDER BY d.id LIMIT ?';$statement=$this->db->prepare($sql);$position=1;if($onlyUser!==null)$statement->bindValue($position++,$onlyUser,PDO::PARAM_INT);$statement->bindValue($position,$limit,PDO::PARAM_INT);$statement->execute();$counts=['web_push_sent'=>0,'web_push_retry'=>0,'web_push_expired'=>0,'web_push_failed'=>0,'web_push_unknown'=>0,'web_push_skipped'=>0];$sender=new WebPushSender($this->keys,(array)($this->config['web_push']??[]));$audience=new NotificationAudience($this->db);
        foreach($statement->fetchAll()as$row){if(microtime(true)>$deadline-2)break;$payload=json_decode((string)$row['payload'],true,16,JSON_THROW_ON_ERROR);if(!(bool)$row['active']||(!empty($row['expires_at'])&&$row['expires_at']<=gmdate('Y-m-d H:i:s'))||$row['preference_enabled']!==null&&!(bool)$row['preference_enabled']||empty($payload['test'])&&!in_array((string)$payload['source'],$this->sources((string)($row['sources']??'')),true)){$this->deliveryStatus((int)$row['id'],'skipped',null,'Subscription or preference changed.');$counts['web_push_skipped']++;continue;}
            $event=['source'=>$payload['source'],'subject'=>(string)($payload['subject']??''),'context'=>$payload['context']??[]];if(empty($payload['test'])&&!$audience->allows((int)$row['user_id'],$event)){$this->deliveryStatus((int)$row['id'],'skipped',null,'Access to the notification changed.');$counts['web_push_skipped']++;continue;}
            try{$plain=$this->secrets->decrypt((string)$row['encrypted_subscription']);$subscription=json_decode($plain,true,8,JSON_THROW_ON_ERROR);$this->deliveryStatus((int)$row['id'],'processing');$result=$sender->send($subscription,$payload);$status=(int)$result['status'];if($result['accepted']){$this->deliveryStatus((int)$row['id'],'sent',$status);$this->subscriptionSuccess((int)$row['subscription_id']);$counts['web_push_sent']++;}elseif($result['expired']){$this->deliveryStatus((int)$row['id'],'expired',$status,'Browser subscription expired.');$this->deactivate((int)$row['subscription_id'],'Browser subscription expired.');$counts['web_push_expired']++;}elseif($result['retryable']&&(int)$row['attempts']<4){$attempt=(int)$row['attempts']+1;$delay=min(3600,60*(2**($attempt-1)));$this->db->prepare('UPDATE web_push_deliveries SET status="pending",attempts=?,next_attempt_at=?,http_status=?,last_error="Push service temporarily unavailable.",updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$attempt,gmdate('Y-m-d H:i:s',time()+$delay),$status,$row['id']]);$counts['web_push_retry']++;}else{$this->deliveryStatus((int)$row['id'],'failed',$status,'Push service rejected the delivery.');$this->subscriptionFailure((int)$row['subscription_id'],'Push service rejected the delivery.');$counts['web_push_failed']++;}}
            catch(Throwable){$this->deliveryStatus((int)$row['id'],'unknown',null,'Push service acceptance is indeterminate; no automatic retry.');$this->subscriptionFailure((int)$row['subscription_id'],'Delivery result is indeterminate.');$counts['web_push_unknown']++;}
        }return$counts;
    }

    private function preference(int $userId): array
    {
        $statement=$this->db->prepare('SELECT enabled,sources FROM web_push_preferences WHERE user_id=?');$statement->execute([$userId]);$row=$statement->fetch();return ['enabled'=>$row?(bool)$row['enabled']:true,'sources'=>$this->sources((string)($row['sources']??''))];
    }
    private function sources(string $json): array { try{$sources=$json!==''?json_decode($json,true,8,JSON_THROW_ON_ERROR):self::SOURCES;}catch(Throwable){$sources=self::SOURCES;}return array_values(array_intersect(self::SOURCES,array_map('strval',is_array($sources)?$sources:[]))); }
    private function payload(array $event): array { return ['title'=>mb_substr((string)$event['title'],0,180),'body'=>mb_substr((string)$event['message'],0,1000),'url'=>(string)$event['url'],'tag'=>'sensecms-'.substr(hash('sha256',(string)$event['key']),0,24),'source'=>(string)$event['source'],'subject'=>(string)$event['subject'],'context'=>array_intersect_key((array)($event['context']??[]),array_flip(['start_at','expires_at'])),'timestamp'=>gmdate(DATE_ATOM,strtotime((string)$event['created_at'].' UTC')?:time())]; }
    private function validateSubscription(string $endpoint,string $p256dh,string $auth): void { if(strlen($endpoint)<16||strlen($endpoint)>2048||!filter_var($endpoint,FILTER_VALIDATE_URL))throw new RuntimeException('The browser subscription endpoint is invalid.');if(strlen(WebPushKeyStore::decode($p256dh))!==65||strlen(WebPushKeyStore::decode($auth))!==16)throw new RuntimeException('The browser subscription keys are invalid.'); }
    private function deviceLabel(string $userAgent): string { $browser=str_contains($userAgent,'Edg/')?'Edge':(str_contains($userAgent,'Firefox/')?'Firefox':(str_contains($userAgent,'Chrome/')?'Chrome':(str_contains($userAgent,'Safari/')?'Safari':'Browser')));$system=str_contains($userAgent,'Windows')?'Windows':(str_contains($userAgent,'Android')?'Android':(str_contains($userAgent,'iPhone')||str_contains($userAgent,'iPad')?'iOS / iPadOS':(str_contains($userAgent,'Macintosh')?'macOS':'device')));return mb_substr($browser.' on '.$system,0,120); }
    private function deliveryStatus(int $id,string $status,?int $http=null,?string $error=null): void { $this->db->prepare('UPDATE web_push_deliveries SET status=?,http_status=?,last_error=?,sent_at=IF(?="sent",UTC_TIMESTAMP(),sent_at),updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$status,$http,$error,$status,$id]); }
    private function subscriptionSuccess(int $id): void { $this->db->prepare('UPDATE web_push_subscriptions SET last_success_at=UTC_TIMESTAMP(),failure_count=0,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]); }
    private function subscriptionFailure(int $id,string $error): void { $this->db->prepare('UPDATE web_push_subscriptions SET last_failure_at=UTC_TIMESTAMP(),failure_count=LEAST(65535,failure_count+1),last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$error,$id]); }
    private function deactivate(int $id,string $error): void { $this->db->prepare('UPDATE web_push_subscriptions SET active=0,last_failure_at=UTC_TIMESTAMP(),last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$error,$id]); }
}
