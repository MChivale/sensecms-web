<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class ChatCallbackTeam
{
    public function __construct(private readonly PDO $db,private readonly CmsRepository $cms) {}

    /** An explicit owner or team takes precedence even when it currently has no eligible operator. */
    public function recipients(array $conversation): array
    {
        if ($conversation['assigned_user_id']) {
            $query=$this->db->prepare('SELECT id,email FROM users WHERE id=? AND active=1');
            $query->execute([$conversation['assigned_user_id']]);
        } else {
            $templates=(array)$this->cms->setting(ChatCallbackTemplates::SETTING,[]);
            $team=$conversation['assigned_team_id'] ?: ($templates['callback_routing']['team_id']??0);
            if (!$team) return [];
            $query=$this->db->prepare('SELECT u.id,u.email FROM live_chat_teams t JOIN live_chat_team_users m ON m.team_id=t.id JOIN users u ON u.id=m.user_id WHERE t.id=? AND t.active=1 AND u.active=1 ORDER BY u.id');
            $query->execute([$team]);
        }
        $eligible=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $access=AccessControl::forUser($this->db,(int)$user['id']);
            if (!$access->isDemoUser() && $access->allows('console.access') && $access->allows('chat.view')) $eligible[(int)$user['id']]=$user;
        }
        return $eligible;
    }

    public function route(): int
    {
        $ids=$this->db->query("SELECT conversation_id FROM chat_callback_receipts WHERE routed_at IS NULL AND (route_after IS NULL OR route_after<=UTC_TIMESTAMP()) ORDER BY work_date,conversation_id LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
        $created=0;
        foreach ($ids as $id) {
            $this->db->beginTransaction();
            try {
                $lock=$this->db->prepare("SELECT c.assigned_user_id,c.assigned_team_id FROM ai_conversations c JOIN chat_callback_receipts r ON r.conversation_id=c.id WHERE c.id=? AND r.routed_at IS NULL AND c.visitor_email IS NOT NULL FOR UPDATE");
                $lock->execute([$id]);$conversation=$lock->fetch(PDO::FETCH_ASSOC);
                if ($conversation) {
                    $users=$this->recipients($conversation);
                    if ($users) {
                        $pl=$this->cms->defaultLocale()==='pl';
                        foreach (array_keys($users) as $userId) {
                            $this->db->prepare("INSERT INTO chat_callback_recipients (conversation_id,user_id,status,next_attempt_at) VALUES (?,?,'pending',UTC_TIMESTAMP())")->execute([$id,$userId]);
                            $this->db->prepare("INSERT INTO user_notifications (user_id,type,title,message,url,created_at) VALUES (?,'chat_callback',?,?,?,UTC_TIMESTAMP())")->execute([$userId,$pl?'Prośba o kontakt z czatu':'Chat callback request',$pl?'Klient zostawił adres e-mail. Otwórz rozmowę, aby odpowiedzieć.':'A visitor left an email address. Open the conversation to follow up.','/conversations?conversation='.rawurlencode($id)]);
                        }
                        $this->db->prepare('UPDATE chat_callback_receipts SET routed_at=UTC_TIMESTAMP() WHERE conversation_id=?')->execute([$id]);
                        $created++;
                    } else {
                        $this->db->prepare('UPDATE chat_callback_receipts SET route_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE conversation_id=?')->execute([$id]);
                    }
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollBack();throw $error;
            }
        }
        return $created;
    }
}
