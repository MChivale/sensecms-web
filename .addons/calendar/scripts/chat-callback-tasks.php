<?php
declare(strict_types=1);

use App\Core\{ChatCallbackTeam,CmsRepository,EventBus};

return static function (PDO $db): int {
    $cms=new CmsRepository($db,new EventBus());
    $team=new ChatCallbackTeam($db,$cms);
    $pl=$cms->defaultLocale()==='pl';
    $ids=$db->query('SELECT conversation_id FROM chat_callback_receipts WHERE routed_at IS NOT NULL AND calendar_event_id IS NULL AND (route_after IS NULL OR route_after<=UTC_TIMESTAMP()) ORDER BY work_date,conversation_id LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
    $count=0;
    foreach ($ids as $id) {
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT r.work_date,r.timezone,c.assigned_user_id,c.assigned_team_id FROM chat_callback_receipts r JOIN ai_conversations c ON c.id=r.conversation_id WHERE r.conversation_id=? AND r.calendar_event_id IS NULL FOR UPDATE');
            $q->execute([$id]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $q=$db->prepare('SELECT user_id FROM chat_callback_recipients WHERE conversation_id=? ORDER BY user_id');
                $q->execute([$id]);
                $members=array_values(array_intersect($q->fetchAll(PDO::FETCH_COLUMN),array_keys($team->recipients($row))));
                if ($members) {
                    $day=new DateTimeImmutable($row['work_date'],new DateTimeZone($row['timezone']));
                    $utc=new DateTimeZone('UTC');
                    $start=$day->setTimezone($utc)->format('Y-m-d H:i:s');
                    $end=$day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
                    $db->prepare("INSERT INTO calendar_events(uid,owner_id,title,description,event_type,status,visibility,timezone,start_at,end_at,all_day,created_at,updated_at)
                        VALUES(?,?,?,?,'general','callback_task','participants',?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
                        ->execute([$id,$members[0],$pl?'Kontakt z klientem po rozmowie na czacie':'Follow up on a chat request','/conversations?conversation='.$id,$row['timezone'],$start,$end]);
                    $q=$db->prepare('SELECT id FROM calendar_events WHERE uid=?');
                    $q->execute([$id]);$event=(int)$q->fetchColumn();
                    $add=$db->prepare("INSERT INTO calendar_event_participants(event_id,user_id,response_status) VALUES(?,?,'accepted')");
                    foreach ($members as $member) $add->execute([$event,$member]);
                    $db->prepare('UPDATE chat_callback_receipts SET calendar_event_id=? WHERE conversation_id=?')->execute([$event,$id]);
                    $count++;
                } else {
                    $db->prepare('UPDATE chat_callback_receipts SET route_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE conversation_id=?')->execute([$id]);
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();throw $error;
        }
    }
    return $count;
};
