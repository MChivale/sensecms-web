<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class NotificationDispatcher
{
    private array $channels = [];
    private NotificationAudience $audience;
    private WebPushSubscriptions $webPush;
    private ?TelegramConnectionClient $telegram = null;
    private ?array $telegramRecipients = null;
    private float $deadline;

    public function __construct(private readonly PDO $db, private readonly array $config, private readonly string $root)
    {
        $this->audience = new NotificationAudience($db);
        $this->webPush = new WebPushSubscriptions($db,$config,$root);
    }

    public function run(int $limit = 50): array
    {
        $name=substr('sensecms.notifications.'.hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,64);
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);
        if ((int)$lock->fetchColumn()!==1) return ['skipped'=>'Worker is busy.'];
        try {
            $this->deadline=microtime(true)+40;
            $this->channels=(new NotificationChannels($this->db,$this->root,(string)$this->config['secrets_key']))->active();
            foreach($this->channels as$channel)if($channel['slug']==='telegram-notifications'&&$channel['delivery']==='central')$this->recipients($channel);
            $this->db->exec("UPDATE notification_deliveries SET status='unknown',last_error='Interrupted send; review with provider before retrying.',updated_at=UTC_TIMESTAMP() WHERE status='processing'");
            $this->webPush->recover();$pushActive=$this->webPush->available()&&$this->webPush->hasActive();
            if (!$this->channels&&!$pushActive) return ['channels'=>0,'web_push'=>0,'sent'=>0];
            $this->collect(max(1,min(200,$limit)));
            return ['channels'=>count($this->channels),'web_push'=>$pushActive?1:0] + $this->deliver(max(1,min(200,$limit)));
        } finally { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }

    private function collect(int $limit): void
    {
        $sinceValues=array_column($this->channels,'since');$pushSince=$this->webPush->since();if($pushSince)$sinceValues[]=$pushSince;$since=min($sinceValues);
        $offset=(int)$this->db->query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW())')->fetchColumn();
        $localSince=gmdate('Y-m-d H:i:s',strtotime($since.' UTC')+$offset);
        $sources=[
            'user'=>['user_notifications','id,user_id,title,message,url,created_at'],
            'chat'=>['ai_messages','id,conversation_id,role,content,COALESCE((SELECT NULLIF(TRIM(c.visitor_name),"") FROM ai_conversations c WHERE c.id=ai_messages.conversation_id),"Website visitor") sender_name,created_at'],
            'form'=>['form_submissions','id,created_at'],
            'event'=>['notification_events','id,event_key,user_id,source,subject,title,message,url,channels,context,created_at'],
        ];
        foreach ($sources as $source=>[$table,$columns]) {
            $cursor=(int)$this->cursor($source,'0');
            if ($source!=='event') $columns=str_replace('created_at','TIMESTAMPADD(SECOND,TIMESTAMPDIFF(SECOND,NOW(),UTC_TIMESTAMP()),created_at) created_at',$columns);
            $statement=$this->db->prepare("SELECT {$columns} FROM {$table} WHERE ".($source==='event'?'processed_at IS NULL AND id>?':'id>?')." AND created_at>=? ORDER BY id LIMIT ?");
            if ($source==='event') $cursor=0;
            $statement->bindValue(1,$cursor,PDO::PARAM_INT);$statement->bindValue(2,$source==='event'?$since:$localSince);$statement->bindValue(3,$limit,PDO::PARAM_INT);$statement->execute();
            $rows=$statement->fetchAll();
            foreach ($rows as $row) {
                if (microtime(true)>$this->deadline-5) return;
                $event=match($source) {
                    'user'=>['key'=>'user:'.$row['id'],'source'=>'user','subject'=>(string)$row['id'],'user_id'=>(int)$row['user_id'],'title'=>$row['title'],'message'=>$row['message'],'url'=>$row['url']],
                    'chat'=>['key'=>'chat:'.$row['conversation_id'],'source'=>'chat','subject'=>$row['conversation_id'],'title'=>'Live chat','message'=>$this->chatMessage($row),'url'=>'/conversations?conversation='.rawurlencode($row['conversation_id'])],
                    'form'=>['key'=>'form:'.$row['id'],'source'=>'form','subject'=>(string)$row['id'],'title'=>'Form Inbox','message'=>'A new form submission is available in SenseCMS.','url'=>'/forms/submissions'],
                    'event'=>['key'=>$row['event_key'],'source'=>$row['source'],'subject'=>$row['subject'],'user_id'=>(int)$row['user_id'],'title'=>$row['title'],'message'=>$row['message'],'url'=>$row['url'],'channels'=>$row['channels']===''?[]:explode(',',$row['channels'])],
                };
                if ($source==='event') $event['context']=json_decode($row['context']??'{}',true,8,JSON_THROW_ON_ERROR);
                if ($source!=='chat' || $row['role']==='visitor') $this->enqueue($event+['created_at'=>$row['created_at']]);
                if ($source==='event') $this->db->prepare('UPDATE notification_events SET processed_at=UTC_TIMESTAMP() WHERE id=? AND channels=?')->execute([$row['id'],$row['channels']]);
                else $this->setCursor($source,(string)$row['id']);
            }
            // Revisit the recent commit window; a larger ID may commit before an earlier transaction.
            if ($source!=='event' && count($rows)<$limit) {
                $floor=(int)$this->db->query("SELECT COALESCE(MIN(id),0) FROM {$table} WHERE created_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)")->fetchColumn();
                if ($floor>0) $this->setCursor($source,(string)max(0,$floor-1));
            }
        }
        // Completion time, not response ID: a survey may remain open for days.
        $cursor=json_decode($this->cursor('survey',json_encode([$localSince,0],JSON_THROW_ON_ERROR)),true,8,JSON_THROW_ON_ERROR);
        $statement=$this->db->prepare('SELECT id,completed_at FROM survey_responses WHERE status="completed" AND (completed_at>? OR (completed_at=? AND id>?)) ORDER BY completed_at,id LIMIT ?');
        $statement->bindValue(1,$cursor[0]);$statement->bindValue(2,$cursor[0]);$statement->bindValue(3,(int)$cursor[1],PDO::PARAM_INT);$statement->bindValue(4,$limit,PDO::PARAM_INT);$statement->execute();
        $rows=$statement->fetchAll();
        foreach ($rows as $row) {
            if (microtime(true)>$this->deadline-5) return;
            $this->enqueue(['key'=>'survey:'.$row['id'],'source'=>'survey','subject'=>(string)$row['id'],'title'=>'Survey responses','message'=>'A completed survey response is available in SenseCMS.','url'=>'/surveys','created_at'=>gmdate('Y-m-d H:i:s',strtotime($row['completed_at'].' UTC')-$offset)]);
            $this->setCursor('survey',json_encode([$row['completed_at'],(int)$row['id']],JSON_THROW_ON_ERROR));
        }
        if (count($rows)<$limit) $this->setCursor('survey',json_encode([max($localSince,gmdate('Y-m-d H:i:s',time()+$offset-300)),0],JSON_THROW_ON_ERROR));
        foreach ($this->db->query('SELECT id,name,version,available_version FROM extension_packages WHERE available_version IS NOT NULL')->fetchAll() as $row) {
            if (!version_compare((string)$row['available_version'],(string)$row['version'],'>')) continue;
            $this->enqueue(['key'=>'update:'.$row['id'].':'.$row['available_version'],'source'=>'update','subject'=>(string)$row['id'],'title'=>'Extension update available','message'=>mb_substr($row['name'].' '.$row['available_version'].' is available.',0,500),'url'=>'/system/extensions?tab=catalog','created_at'=>gmdate('Y-m-d H:i:s')]);
        }
        $license=(new Runtime($this->root))->license();$license->enforce((string)$this->config['base_url']);$notice=$license->expiringNotice();
        if ($notice) $this->enqueue(['key'=>'license:'.gmdate('Y-m-d').':'.$notice,'source'=>'license','subject'=>'installation','title'=>'SenseCMS license','message'=>$notice,'url'=>'/license','created_at'=>gmdate('Y-m-d H:i:s')]);
    }

    private function enqueue(array $event): void
    {
        if (!preg_match('#^/(?!/)[A-Za-z0-9_/?=&%.~-]*$#D',(string)$event['url'])) return;
        $statement=$this->db->prepare('INSERT INTO notification_deliveries (plugin_slug,user_id,event_key,payload,settings_revision,status,created_at,updated_at) VALUES (?,?,?,?,?,"pending",UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id');
        $chatPrior=($event['source']??'')==='chat'?$this->db->prepare("SELECT 1 FROM notification_deliveries WHERE plugin_slug=? AND user_id=? AND status IN ('pending','processing','sent','unknown') AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.source'))='chat' AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.subject'))=? LIMIT 1"):null;
        foreach ($this->channels as $channel) {
            if ($event['created_at']<$channel['since'] || (!empty($event['channels']) && !in_array($channel['slug'],$event['channels'],true))) continue;
            $recipients=$this->recipients($channel);
            foreach ((array)$recipients as $userId=>$recipient) {
                if ((!empty($event['user_id']) && (int)$event['user_id']!==(int)$userId) || !$this->audience->allows((int)$userId,$event)) continue;
                if($chatPrior){$chatPrior->execute([$channel['slug'],(int)$userId,(string)$event['subject']]);if($chatPrior->fetchColumn())continue;}
                $statement->execute([$channel['slug'],(int)$userId,hash('sha256',$event['key']),json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$channel['revision']]);
            }
        }
        $this->webPush->enqueue($event,$this->audience);
    }

    private function chatMessage(array $row): string
    {
        $name=trim((string)($row['sender_name']??''))?:'Website visitor';
        $message=html_entity_decode(strip_tags((string)($row['content']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $message=trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$message))?:'-';
        return 'Sender: '.mb_substr($name,0,150)."\n\nMessage: ".mb_substr($message,0,700);
    }

    private function deliver(int $limit): array
    {
        $statement=$this->db->prepare('SELECT * FROM notification_deliveries WHERE status="pending" ORDER BY id LIMIT ?');$statement->bindValue(1,$limit,PDO::PARAM_INT);$statement->execute();
        $counts=['sent'=>0,'unknown'=>0,'skipped'=>0];
        foreach ($statement->fetchAll() as $row) {
            if (microtime(true)>$this->deadline-16) break;
            $channels=(new NotificationChannels($this->db,$this->root,(string)$this->config['secrets_key']))->active();
            $channel=null;foreach($channels as $candidate)if($candidate['slug']===$row['plugin_slug']){$channel=$candidate;break;}
            $event=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);$recipients=$channel?$this->recipients($channel):[];$recipient=$recipients[$row['user_id']]??null;
            if (!$channel || !is_string($recipient) || !hash_equals($row['settings_revision'],$channel['revision']) || !$this->audience->allows((int)$row['user_id'],$event)) {
                $this->status((int)$row['id'],'skipped','Recipient, settings or access changed.');$counts['skipped']++;continue;
            }
            $settings=$channel['settings'];if($channel['delivery']!=='central')$settings[$channel['slug']==='telegram-notifications'?'chat_id':'recipient']=$recipient;
            $base=rtrim((string)$this->config['base_url'],'/');
            if (!filter_var($base,FILTER_VALIDATE_URL) || parse_url($base,PHP_URL_SCHEME)!=='https') { $this->status((int)$row['id'],'skipped','A valid HTTPS installation URL is required.');$counts['skipped']++;continue; }
            $this->status((int)$row['id'],'processing');
            try {
                $title=mb_substr($event['title'],0,180);$body=mb_substr($event['message'],0,1000)."\n".$base.$event['url'];
                if($channel['slug']==='telegram-notifications'&&$channel['delivery']==='central')$this->telegram()->deliver((int)$row['user_id'],(string)$row['event_key'],$title,$body);else$channel['provider']->send($settings,['title'=>$title,'body'=>$body]);
                $this->status((int)$row['id'],'sent');$counts['sent']++;
            } catch (RuntimeException$error) { if($error->getCode()===404){$this->status((int)$row['id'],'skipped','The recipient disconnected Telegram.');$counts['skipped']++;}else{$this->status((int)$row['id'],'unknown','Provider did not confirm delivery; review before retrying.');$counts['unknown']++;} }
            catch (Throwable) { $this->status((int)$row['id'],'unknown','Provider did not confirm delivery; review before retrying.');$counts['unknown']++; }
        }
        return $counts+$this->webPush->deliver($limit,$this->deadline);
    }

    private function recipients(array$channel):array
    {
        if($channel['slug']==='telegram-notifications'&&$channel['delivery']==='central'){
            if($this->telegramRecipients===null){
                try{$this->telegramRecipients=array_fill_keys($this->telegram()->recipients(),'central');}
                catch(Throwable$error){error_log('Telegram recipient discovery failed: '.$error->getMessage());$this->telegramRecipients=[];}
            }
            return$this->telegramRecipients;
        }
        $recipients=json_decode((string)($channel['settings']['recipient_map']??''),true);return is_array($recipients)?$recipients:[];
    }

    private function telegram():TelegramConnectionClient
    {
        return$this->telegram??=new TelegramConnectionClient((new Runtime($this->root))->license(),['base_url'=>(string)($this->config['integrations']['telegram_broker_url']??'')],(string)$this->config['base_url']);
    }

    private function cursor(string $source,string $default): string
    {
        $statement=$this->db->prepare('SELECT cursor_value FROM notification_cursors WHERE source=?');$statement->execute([$source]);return (string)($statement->fetchColumn()?:$default);
    }

    private function setCursor(string $source,string $value): void
    {
        $this->db->prepare('INSERT INTO notification_cursors(source,cursor_value,updated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE cursor_value=VALUES(cursor_value),updated_at=VALUES(updated_at)')->execute([$source,$value]);
    }

    private function status(int $id,string $status,?string $error=null): void
    {
        $this->db->prepare('UPDATE notification_deliveries SET status=?,last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$status,$error,$id]);
    }
}
