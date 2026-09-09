<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class NotificationAudience
{
    public function __construct(private readonly PDO $db) {}

    public function allows(int $userId, array $event): bool
    {
        $access = AccessControl::forUser($this->db, $userId);
        if (!$access->allows('console.access')) return false;
        if (!empty($event['context']['expires_at']) && (string)$event['context']['expires_at']<=gmdate('Y-m-d H:i:s')) return false;
        $id = (string)$event['subject']; $source = $event['source']; $scope = $access->facilityIds();
        if ($source === 'chat') {
            $row = $this->row('SELECT status FROM ai_conversations WHERE id=?', [$id]);
            return $access->allows('chat.view') && ($row['status'] ?? '') === 'queued' && (new AiRepository($this->db))->canAccessConversation($id,$userId);
        }
        if ($source === 'form') {
            $row = $this->row('SELECT facility_id FROM form_submissions WHERE id=? AND deleted_at IS NULL', [$id]);
            return $access->allows('forms.view') && $row && ($scope === null || in_array((int)$row['facility_id'],$scope,true));
        }
        if ($source === 'survey') {
            if (!$access->allows('surveys.responses')) return false;
            $row = $this->row('SELECT survey_id FROM survey_responses WHERE id=? AND status="completed"', [$id]);
            if (!$row) return false;
            $statement=$this->db->prepare('SELECT facility_id FROM survey_facilities WHERE survey_id=?');$statement->execute([$row['survey_id']]);$facilities=array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
            return $scope===null || !$facilities || (bool)array_intersect($scope,$facilities);
        }
        if ($source === 'user') {
            $row=$this->row('SELECT url FROM user_notifications WHERE id=? AND user_id=?',[$id,$userId]);
            if (!$row) return false;
            if (preg_match('#^/content/(pages|posts)/(\d+)#', $row['url'],$match)) {
                $table=$match[1];$item=$this->row("SELECT facility_id FROM {$table} WHERE id=? AND status<>'archived'",[$match[2]]);
                return $item && $access->allows('content.'.$table.'.view') && ($scope===null || in_array((int)$item['facility_id'],$scope,true));
            }
            return true;
        }
        if ($source === 'calendar') {
            if (!$access->allows('calendar.view') || !$this->row("SELECT id FROM extension_packages WHERE type='addon' AND slug='calendar' AND active=1",[])) return false;
            $row=$this->row('SELECT facility_id,owner_id,visibility,status,start_at FROM calendar_events WHERE id=?',[$id]);
            if (!$row || ($scope!==null && ($scope===[] || ((int)$row['facility_id']>0 && !in_array((int)$row['facility_id'],$scope,true))))) return false;
            if (!empty($event['context']['start_at']) && ($row['status']==='cancelled' || $row['start_at']!==$event['context']['start_at'])) return false;
            return $access->allows('system.owner') || (int)$row['owner_id']===$userId || in_array($row['visibility'],['facility','public'],true) || ($row['visibility']==='participants' && (bool)$this->row('SELECT user_id FROM calendar_event_participants WHERE event_id=? AND user_id=?',[$id,$userId]));
        }
        if ($source === 'update') return $access->allows('extensions.manage');
        if ($source === 'license') return $access->allows('system.manage');
        // Future modules register user-targeted notices through user_notifications.
        return false;
    }

    private function row(string $sql, array $values): array
    {
        $statement=$this->db->prepare($sql);$statement->execute($values);return $statement->fetch() ?: [];
    }
}
