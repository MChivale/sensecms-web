<?php
declare(strict_types=1);

namespace SenseCMS\Calendar;

use App\Core\MailService;
use PDO;
use RuntimeException;
use Throwable;

final class CalendarDispatcher
{
    private CalendarIntegrationManager $integrations;
    private float $deadline;

    public function __construct(private readonly PDO $db, private readonly array $config, private readonly string $root)
    {
        $this->integrations = new CalendarIntegrationManager($db, $root, (string) ($config['secrets_key'] ?? ''));
    }

    public function run(int $limit = 50): array
    {
        $active = $this->db->query("SELECT active FROM extension_packages WHERE type='addon' AND slug='calendar'")->fetchColumn();
        if (!$active) return ['skipped' => 'Calendar is inactive.'];
        $name = substr('sensecms.calendar.worker.' . hash('sha256', (string)$this->db->query('SELECT DATABASE()')->fetchColumn()), 0, 64);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$name]);
        if ((int)$lock->fetchColumn() !== 1) return ['skipped' => 'Calendar is busy.'];
        $this->deadline = microtime(true) + 40;
        try {
            $this->recover();
            $limit = max(1, min(200, $limit));
            return ['reminders' => $this->reminders($limit), 'sync' => $this->sync($limit)];
        } finally { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }

    private function reminders(int $limit): array
    {
        $statement = $this->db->prepare("SELECT r.*,e.title,e.description,e.location,e.start_at,e.end_at,e.timezone,e.uid,e.all_day FROM calendar_reminders r INNER JOIN calendar_events e ON e.id=r.event_id WHERE r.status='pending' AND r.scheduled_at<=UTC_TIMESTAMP() AND e.status<>'cancelled' AND e.end_at>UTC_TIMESTAMP() ORDER BY r.scheduled_at,r.id LIMIT ?");
        $statement->bindValue(1, $limit, PDO::PARAM_INT); $statement->execute(); $sent = 0; $failed = 0; $queued = 0;
        foreach ($statement->fetchAll() as $reminder) {
            if (microtime(true) > $this->deadline) break;
            try {
                if(!$this->currentReminder($reminder)){$this->db->prepare("UPDATE calendar_reminders SET status='cancelled' WHERE id=? AND status='pending'")->execute([$reminder['id']]);continue;}
                if (!$this->deliver($reminder)) continue;
                $external=in_array($reminder['channel'],['browser','telegram','whatsapp'],true);
                $this->db->prepare('UPDATE calendar_reminders SET status=?,sent_at=IF(?="sent",UTC_TIMESTAMP(),NULL),last_error=NULL WHERE id=?')->execute([$external?'queued':'sent',$external?'queued':'sent',$reminder['id']]);
                if ($external) $queued++; else $sent++;
            } catch (Throwable) {
                $this->db->prepare("UPDATE calendar_reminders SET status='failed',last_error='Delivery requires review; no automatic external resend.' WHERE id=?")->execute([$reminder['id']]); $failed++;
            }
        }
        return ['sent' => $sent, 'queued' => $queued, 'failed' => $failed];
    }

    private function deliver(array $reminder): bool
    {
        $statement = $this->db->prepare('SELECT u.id,u.name,u.email FROM calendar_event_participants ep INNER JOIN users u ON u.id=ep.user_id AND u.active=1 WHERE ep.event_id=? ORDER BY u.id');
        $statement->execute([$reminder['event_id']]); $users = $statement->fetchAll();
        $zone=new \DateTimeZone($reminder['timezone']);$localStart=(new \DateTimeImmutable($reminder['start_at'],new \DateTimeZone('UTC')))->setTimezone($zone);$localEnd=(new \DateTimeImmutable($reminder['end_at'],new \DateTimeZone('UTC')))->setTimezone($zone);
        $when=!empty($reminder['all_day'])?($localStart->format('Y-m-d').($localEnd>$localStart->add(new \DateInterval('P1D'))?' - '.$localEnd->modify('-1 day')->format('Y-m-d'):'').' (all day)'):$localStart->format('Y-m-d H:i T');
        $title = 'Reminder: ' . $reminder['title']; $body = $when . ($reminder['location'] ? "\n" . $reminder['location'] : '');
        $telegramWhen=!empty($reminder['all_day'])?$when.' '.$reminder['timezone']:$localStart->format('Y-m-d H:i e');
        $telegramTitle='Reminder: '.$telegramWhen;
        $telegramBody="\nTitle: ".$reminder['title']."\n\nDescription: ".(trim((string)$reminder['description'])?:'-')."\n";
        $channel = (string)$reminder['channel']; $hasUnknown = false;
        $lookup = $this->db->prepare('SELECT status FROM calendar_deliveries WHERE reminder_id=? AND user_id=?');
        foreach ($users as $user) {
            if (microtime(true) > $this->deadline) return false;
            if(!$this->currentReminder($reminder))return false;
            $lookup->execute([$reminder['id'], $user['id']]); $status = $lookup->fetchColumn();
            if (in_array($status,['sent','queued'],true)) continue;
            // An ambiguous SMTP/API result must not trigger duplicate messages on a retry.
            if ($status === 'unknown' || $status === 'processing') { $hasUnknown = true; continue; }
            if ($channel === 'internal') {
                $this->db->beginTransaction();
                try {
                    $this->db->prepare('INSERT INTO calendar_notifications (user_id,event_id,channel,title,message,action_url,created_at) VALUES (?,?,"internal",?,?,?,UTC_TIMESTAMP())')->execute([$user['id'], $reminder['event_id'], mb_substr($title,0,180), mb_substr($body,0,1000), '/calendar?event='.$reminder['event_id']]);
                    $this->deliveryStatus($reminder, $user, 'sent');
                    $this->db->commit();
                } catch (Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
                continue;
            }
            if (in_array($channel,['telegram','whatsapp'],true)) {
                $this->db->beginTransaction();
                try {
                    $this->queueNotification($reminder,$user,$channel==='telegram'?$telegramTitle:$title,$channel==='telegram'?$telegramBody:$body,[$channel.'-notifications']);
                    $this->deliveryStatus($reminder,$user,'queued');$this->db->commit();
                } catch (Throwable $error) { if($this->db->inTransaction())$this->db->rollBack();throw $error; }
                continue;
            }
            if ($channel==='browser') {
                $this->db->beginTransaction();
                try {
                    $this->queueNotification($reminder,$user,$title,$body,['web-push']);
                    $this->deliveryStatus($reminder,$user,'queued');$this->db->commit();
                } catch (Throwable $error) { if($this->db->inTransaction())$this->db->rollBack();throw$error; }
                continue;
            }
            try {
                if ($channel!=='email') throw new RuntimeException('Unsupported reminder channel.');
                $this->deliveryStatus($reminder, $user, 'processing');
                (new MailService((array)($this->config['mail'] ?? [])))->send((string)$user['email'], '[My Calendar] '.$title, $body."\n\nMy Calendar");
                $this->deliveryStatus($reminder, $user, 'sent');
            } catch (Throwable) { $this->deliveryStatus($reminder, $user, 'unknown'); $hasUnknown = true; }
        }
        if ($hasUnknown) throw new RuntimeException('One or more deliveries need review.');
        return true;
    }

    private function deliveryStatus(array $reminder, array $user, string $status): void
    {
        $this->db->prepare('INSERT INTO calendar_deliveries (reminder_id,user_id,status,updated_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),updated_at=VALUES(updated_at)')->execute([$reminder['id'], $user['id'], $status]);
    }

    private function queueNotification(array $reminder,array $user,string $title,string $body,array $channels=[]): void
    {
        $channels=array_values(array_unique($channels));sort($channels);
        \App\Core\SystemNotifications::record($this->db,'reminder:'.$reminder['event_id'].':'.$reminder['scheduled_at'].':'.$reminder['offset_minutes'].':'.implode('+',$channels),(int)$user['id'],'calendar',(string)$reminder['event_id'],$title,$body,'/calendar?event='.$reminder['event_id'],$channels,['start_at'=>$reminder['start_at'],'expires_at'=>$reminder['end_at']]);
    }

    private function sync(int $limit): array
    {
        $statement = $this->db->prepare("SELECT j.*,e.uid,e.title,e.description,e.location,e.start_at,e.end_at,e.timezone,e.status event_status,e.visibility,e.all_day FROM calendar_sync_jobs j INNER JOIN calendar_events e ON e.id=j.event_id WHERE j.status='pending' AND j.available_at<=UTC_TIMESTAMP() ORDER BY j.id LIMIT ?");
        $statement->bindValue(1,$limit,PDO::PARAM_INT); $statement->execute(); $done=0; $failed=0;
        $providers = $this->integrations->active('calendar');
        foreach ($statement->fetchAll() as $job) {
            if (microtime(true) > $this->deadline) break;
            try {
                $event=$this->syncEvent((int)$job['event_id']);if(!$event){$this->db->prepare("UPDATE calendar_sync_jobs SET status='completed',completed_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?")->execute([$job['id']]);$done++;continue;}
                foreach ($providers as $integration) $this->syncProvider($integration,array_replace($job,$event));
                $this->db->prepare("UPDATE calendar_sync_jobs SET status='completed',completed_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?")->execute([$job['id']]); $done++;
            } catch (Throwable) {
                $attempts=(int)$job['attempts']+1; $delay=min(3600,60*(2**min(6,$attempts-1)));
                $this->db->prepare("UPDATE calendar_sync_jobs SET status=?,attempts=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$delay} SECOND),last_error='Provider sync failed; verify destination, credentials and permissions.' WHERE id=?")->execute([$attempts>=5?'failed':'pending',$attempts,$job['id']]); $failed++;
            }
        }
        return ['completed'=>$done,'failed'=>$failed,'providers'=>count($providers)];
    }

    private function syncProvider(array $integration, array $job): void
    {
        $settings=$integration['settings'];
        $identity=array_intersect_key($settings,array_flip(['calendar_id','calendar_url','apple_id','user_id','tenant_id']));
        ksort($identity); $account=hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
        $mapping=$this->db->prepare('SELECT external_id FROM calendar_external_events WHERE plugin_slug=? AND account_key=? AND event_id=? LIMIT 1');
        $mapping->execute([$integration['slug'],$account,$job['event_id']]); $externalId=$mapping->fetchColumn();
        // Installation-wide destinations are shared: never export participant-only/private events.
        if ($job['event_status']==='cancelled' || !in_array($job['visibility'],['facility','public'],true)) {
            if (is_string($externalId) && $externalId!=='') $integration['provider']->delete($settings,$externalId);
            $this->db->prepare('DELETE FROM calendar_external_events WHERE plugin_slug=? AND account_key=? AND event_id=?')->execute([$integration['slug'],$account,$job['event_id']]); return;
        }
        $newId=$integration['provider']->upsert($settings,$job,is_string($externalId)?$externalId:null);
        if (!is_string($newId) || $newId==='') throw new RuntimeException('Invalid provider event identifier.');
        $this->db->prepare('INSERT INTO calendar_external_events (plugin_slug,account_key,external_id,event_id,last_synced_at) VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),last_synced_at=VALUES(last_synced_at)')->execute([$integration['slug'],$account,$newId,$job['event_id']]);
    }

    private function recover(): void
    {
        $this->db->exec("UPDATE calendar_deliveries SET status='unknown' WHERE status='processing'");
        $this->db->exec("UPDATE calendar_reminders r JOIN calendar_events e ON e.id=r.event_id SET r.status='cancelled' WHERE r.status IN ('pending','processing') AND (e.status='cancelled' OR e.end_at<=UTC_TIMESTAMP())");
        foreach (['calendar_reminders','calendar_sync_jobs'] as $table) $this->db->exec("UPDATE {$table} SET status='pending',locked_at=NULL WHERE status='processing'");
    }

    private function currentReminder(array$reminder):bool
    {
        $statement=$this->db->prepare("SELECT e.status,e.start_at,e.end_at,r.status reminder_status FROM calendar_events e INNER JOIN calendar_reminders r ON r.event_id=e.id WHERE e.id=? AND r.id=?");$statement->execute([$reminder['event_id'],$reminder['id']]);$row=$statement->fetch();return(bool)$row&&$row['status']!=='cancelled'&&$row['reminder_status']==='pending'&&$row['start_at']===$reminder['start_at']&&$row['end_at']===$reminder['end_at']&&$row['end_at']>gmdate('Y-m-d H:i:s');
    }

    private function syncEvent(int$eventId):?array
    {
        $statement=$this->db->prepare('SELECT uid,title,description,location,start_at,end_at,timezone,status event_status,visibility,all_day FROM calendar_events WHERE id=?');$statement->execute([$eventId]);$row=$statement->fetch();return$row?:null;
    }
}
