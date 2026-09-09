<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class AiRepository
{
    public function __construct(private readonly PDO $db) {}

    public function providers(): array { return $this->db->query('SELECT id, slug, name, driver, base_url, default_model, enabled, updated_at FROM ai_providers ORDER BY id')->fetchAll(); }
    public function provider(string $slug): ?array { $statement = $this->db->prepare('SELECT * FROM ai_providers WHERE slug = ? AND enabled = 1 LIMIT 1'); $statement->execute([$slug]); return $statement->fetch() ?: null; }
    public function dashboard(): array { return ['providers' => (int) $this->db->query('SELECT COUNT(*) FROM ai_providers WHERE enabled = 1')->fetchColumn(), 'knowledge' => (int) $this->db->query("SELECT COUNT(*) FROM ai_knowledge_documents WHERE status = 'published'")->fetchColumn(), 'open_chats' => (int) $this->db->query("SELECT COUNT(*) FROM ai_conversations WHERE status IN ('open','queued','assigned')")->fetchColumn()]; }

    public function context(string $message, string $locale): array
    {
        $terms = array_slice(array_filter(preg_split('/[^[:alnum:]]+/u', mb_strtolower($message)) ?: [], static fn(string $term): bool => mb_strlen($term) > 3), 0, 8);
        if (!$terms) return [];
        $where = implode(' OR ', array_fill(0, count($terms), 'content LIKE ?'));
        $statement = $this->db->prepare("SELECT content FROM ai_knowledge_chunks WHERE ({$where}) AND (document_id IN (SELECT id FROM ai_knowledge_documents WHERE status = 'published' AND (locale = ? OR locale IS NULL))) ORDER BY id DESC LIMIT 5");
        $statement->execute(array_merge(array_map(static fn(string $term): string => '%' . $term . '%', $terms), [$locale]));
        return array_column($statement->fetchAll(), 'content');
    }

    public function conversation(string $id, string $locale): void { $statement = $this->db->prepare('INSERT IGNORE INTO ai_conversations (id,locale,channel,status,created_at,updated_at) VALUES (?, ?, "ai", "open", NOW(), NOW())'); $statement->execute([$id, $locale]); }
    public function setVisitorName(string $conversation, string $name): void { $this->db->prepare('UPDATE ai_conversations SET visitor_name = ? WHERE id = ? AND (visitor_name IS NULL OR visitor_name = "")')->execute([$name, $conversation]); }
    public function message(string $conversation, string $role, string $content, ?string $provider = null): void { $statement = $this->db->prepare('INSERT INTO ai_messages (conversation_id,role,content,provider_slug,created_at) VALUES (?,?,?,?,NOW())'); $statement->execute([$conversation, $role, $content, $provider]); $this->db->prepare('UPDATE ai_conversations SET updated_at = NOW() WHERE id = ?')->execute([$conversation]); }

    public function queueForHuman(string $conversation): bool
    {
        $statement = $this->db->prepare("UPDATE ai_conversations SET channel = 'human', status = 'queued', assigned_user_id = NULL, assigned_team_id = NULL, updated_at = NOW() WHERE id = ? AND status = 'open'");
        $statement->execute([$conversation]);
        return $statement->rowCount() === 1;
    }

    public function conversationStatus(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT channel, status, assigned_user_id, assigned_team_id FROM ai_conversations WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function conversations(int $userId): array
    {
        $sql = "SELECT c.*, u.name AS agent_name, t.name AS team_name, t.color AS team_color,
                    (SELECT content FROM ai_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM ai_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                    (SELECT COUNT(*) FROM ai_messages m WHERE m.conversation_id = c.id AND m.role = 'visitor' AND m.id > COALESCE((SELECT r.last_read_message_id FROM live_chat_reads r WHERE r.conversation_id = c.id AND r.user_id = ?), 0)) AS unread_count
                FROM ai_conversations c
                LEFT JOIN users u ON u.id = c.assigned_user_id
                LEFT JOIN live_chat_teams t ON t.id = c.assigned_team_id
                WHERE c.status <> 'closed' AND " . $this->accessSql('c') . "
                ORDER BY FIELD(c.status, 'queued', 'assigned', 'open'), unread_count DESC, c.updated_at DESC LIMIT 100";
        $statement = $this->db->prepare($sql);
        $statement->execute([$userId, $userId, $userId]);
        return $statement->fetchAll();
    }

    public function conversationWithMessages(string $id, ?int $userId = null): ?array
    {
        $sql = 'SELECT c.*, u.name AS agent_name, u.avatar_url AS agent_profile_avatar, t.name AS team_name, t.color AS team_color FROM ai_conversations c LEFT JOIN users u ON u.id = c.assigned_user_id LEFT JOIN live_chat_teams t ON t.id = c.assigned_team_id WHERE c.id = ?';
        $parameters = [$id];
        if ($userId !== null) { $sql .= ' AND ' . $this->accessSql('c'); array_push($parameters, $userId, $userId); }
        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);
        $conversation = $statement->fetch();
        if (!$conversation) return null;
        $messages = $this->db->prepare("SELECT m.id, m.role, m.content, m.created_at, COALESCE(mu.name, IF(m.role = 'agent', u.name, NULL)) AS agent_name FROM ai_messages m LEFT JOIN users mu ON mu.id = m.user_id LEFT JOIN users u ON u.id = ? WHERE m.conversation_id = ? ORDER BY m.id");
        $messages->execute([$conversation['assigned_user_id'], $id]);
        $conversation['messages'] = $messages->fetchAll();
        return $conversation;
    }

    public function queuedConversations(int $userId): array
    {
        $statement = $this->db->prepare("SELECT c.id, c.locale, c.visitor_name, c.visitor_email, c.assigned_team_id, c.assigned_user_id, c.created_at, c.updated_at, t.name AS team_name, (SELECT m.content FROM ai_messages m WHERE m.conversation_id = c.id AND m.role = 'visitor' ORDER BY m.id DESC LIMIT 1) AS preview FROM ai_conversations c LEFT JOIN live_chat_teams t ON t.id = c.assigned_team_id WHERE c.status = 'queued' AND " . $this->accessSql('c') . ' ORDER BY c.updated_at ASC LIMIT 20');
        $statement->execute([$userId, $userId]);
        return $statement->fetchAll();
    }

    public function recentAssignments(int $since, int $userId): array
    {
        $statement = $this->db->prepare("SELECT c.id, c.visitor_name, c.assigned_user_id, c.assigned_team_id, c.updated_at, u.name AS agent_name FROM ai_conversations c INNER JOIN users u ON u.id = c.assigned_user_id WHERE c.status = 'assigned' AND c.updated_at >= FROM_UNIXTIME(?) AND (c.assigned_user_id = ? OR c.assigned_team_id IS NULL OR EXISTS (SELECT 1 FROM live_chat_team_users tu WHERE tu.team_id = c.assigned_team_id AND tu.user_id = ?)) ORDER BY c.updated_at ASC LIMIT 100");
        $statement->execute([max(0, $since), $userId, $userId]);
        return $statement->fetchAll();
    }

    public function recentVisitorMessages(int $since, int $userId): array
    {
        $statement = $this->db->prepare("SELECT m.id, m.conversation_id, m.content, m.created_at, c.visitor_name FROM ai_messages m INNER JOIN ai_conversations c ON c.id = m.conversation_id WHERE m.role = 'visitor' AND c.status IN ('queued','assigned') AND m.created_at >= FROM_UNIXTIME(?) AND " . $this->accessSql('c') . ' ORDER BY m.id ASC LIMIT 100');
        $statement->execute([max(0, $since), $userId, $userId]);
        return $statement->fetchAll();
    }

    public function claimConversation(string $conversation, int $userId): bool
    {
        $statement = $this->db->prepare("UPDATE ai_conversations c SET c.channel = 'human', c.status = 'assigned', c.assigned_user_id = ?, c.updated_at = NOW() WHERE c.id = ? AND c.status = 'queued' AND (c.assigned_user_id = ? OR (c.assigned_user_id IS NULL AND (c.assigned_team_id IS NULL OR EXISTS (SELECT 1 FROM live_chat_team_users tu WHERE tu.team_id = c.assigned_team_id AND tu.user_id = ?))))");
        $statement->execute([$userId, $conversation, $userId, $userId]);
        return $statement->rowCount() === 1;
    }

    public function agentReply(string $conversation, int $userId, string $content): bool
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT c.id FROM ai_conversations c WHERE c.id = ? AND c.status <> 'closed' AND " . $this->accessSql('c') . ' FOR UPDATE');
            $lock->execute([$conversation, $userId, $userId]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return false; }
            $this->db->prepare("UPDATE ai_conversations SET channel = 'human', status = 'assigned', assigned_user_id = COALESCE(assigned_user_id, ?), updated_at = NOW() WHERE id = ?")->execute([$userId, $conversation]);
            $message = $this->db->prepare("INSERT INTO ai_messages (conversation_id, role, content, user_id, created_at) VALUES (?, 'agent', ?, ?, NOW())");
            $message->execute([$conversation, $content, $userId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function markRead(string $conversation, int $userId): bool
    {
        if (!$this->canAccessConversation($conversation, $userId)) return false;
        $maximum = $this->db->prepare("SELECT COALESCE(MAX(id), 0) FROM ai_messages WHERE conversation_id = ? AND role = 'visitor'");
        $maximum->execute([$conversation]);
        $messageId = (int) $maximum->fetchColumn();
        $statement = $this->db->prepare('INSERT INTO live_chat_reads (conversation_id,user_id,last_read_message_id,read_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),read_at=NOW()');
        $statement->execute([$conversation, $userId, $messageId]);
        return true;
    }

    public function unreadCounts(int $userId): array
    {
        $statement = $this->db->prepare("SELECT c.id, COUNT(m.id) AS unread_count FROM ai_conversations c JOIN ai_messages m ON m.conversation_id = c.id AND m.role = 'visitor' LEFT JOIN live_chat_reads r ON r.conversation_id = c.id AND r.user_id = ? WHERE c.status <> 'closed' AND m.id > COALESCE(r.last_read_message_id, 0) AND " . $this->accessSql('c') . ' GROUP BY c.id');
        $statement->execute([$userId, $userId, $userId]);
        return array_map('intval', array_column($statement->fetchAll(), 'unread_count', 'id'));
    }

    public function unreadTotal(int $userId): int { return array_sum($this->unreadCounts($userId)); }

    public function users(): array { return $this->db->query('SELECT id, name, email, avatar_url FROM users WHERE active = 1 ORDER BY name, id')->fetchAll(); }

    public function teams(bool $includeInactive = true): array
    {
        $available = " WHERE t.active = 1 AND EXISTS (SELECT 1 FROM live_chat_team_users available_team INNER JOIN users available_user ON available_user.id = available_team.user_id AND available_user.active = 1 WHERE available_team.team_id = t.id)";
        $sql = 'SELECT t.*, COUNT(tu.user_id) AS member_count FROM live_chat_teams t LEFT JOIN live_chat_team_users tu ON tu.team_id = t.id' . ($includeInactive ? '' : $available) . ' GROUP BY t.id ORDER BY t.active DESC, t.name, t.id';
        $teams = $this->db->query($sql)->fetchAll();
        $members = $this->db->query('SELECT team_id, user_id FROM live_chat_team_users ORDER BY team_id, user_id')->fetchAll();
        $byTeam = [];
        foreach ($members as $member) $byTeam[(int) $member['team_id']][] = (int) $member['user_id'];
        foreach ($teams as &$team) $team['members'] = $byTeam[(int) $team['id']] ?? [];
        unset($team);
        return $teams;
    }

    public function syncTeams(array $teams): void
    {
        $validUsers = array_fill_keys(array_map('intval', array_column($this->users(), 'id')), true);
        $this->db->beginTransaction();
        try {
            $submitted = [];
            foreach ($teams as $team) {
                $slug = (string) $team['slug'];
                $submitted[] = $slug;
                $members = array_values(array_unique(array_map('intval', $team['members'])));
                foreach ($members as $userId) if (!isset($validUsers[$userId])) throw new \RuntimeException('A selected team member is no longer an active operator.');
                if ($team['active'] && !$members) throw new \RuntimeException('Every active team must have at least one active operator.');
                $statement = $this->db->prepare('INSERT INTO live_chat_teams (name,slug,description,color,active,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),color=VALUES(color),active=VALUES(active),updated_at=NOW()');
                $statement->execute([$team['name'], $slug, $team['description'], $team['color'], $team['active'] ? 1 : 0]);
                $lookup = $this->db->prepare('SELECT id FROM live_chat_teams WHERE slug = ? LIMIT 1');
                $lookup->execute([$slug]);
                $teamId = (int) $lookup->fetchColumn();
                $this->db->prepare('DELETE FROM live_chat_team_users WHERE team_id = ?')->execute([$teamId]);
                $membership = $this->db->prepare('INSERT INTO live_chat_team_users (team_id,user_id,created_at) VALUES (?,?,NOW())');
                foreach ($members as $userId) $membership->execute([$teamId, $userId]);
            }
            if ($submitted) {
                $placeholders = implode(',', array_fill(0, count($submitted), '?'));
                $this->db->prepare("UPDATE live_chat_teams SET active = 0, updated_at = NOW() WHERE slug NOT IN ({$placeholders})")->execute($submitted);
            } else {
                $this->db->exec('UPDATE live_chat_teams SET active = 0, updated_at = NOW()');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function transferConversation(string $conversation, int $fromUserId, ?int $toUserId, ?int $toTeamId, string $note): ?array
    {
        if (($toUserId === null) === ($toTeamId === null)) return null;
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM ai_conversations c WHERE c.id = ? AND c.status <> \'closed\' AND ' . $this->accessSql('c') . ' FOR UPDATE');
            $lock->execute([$conversation, $fromUserId, $fromUserId]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return null; }
            if ($toUserId !== null) {
                $target = $this->db->prepare('SELECT id, name FROM users WHERE id = ? AND active = 1 LIMIT 1');
                $target->execute([$toUserId]);
                $row = $target->fetch();
                if (!$row) { $this->db->rollBack(); return null; }
                $this->db->prepare("UPDATE ai_conversations SET channel='human',status='queued',assigned_user_id=?,assigned_team_id=NULL,updated_at=NOW() WHERE id=?")->execute([$toUserId, $conversation]);
                $label = (string) $row['name'];
            } else {
                $target = $this->db->prepare('SELECT t.id, t.name FROM live_chat_teams t WHERE t.id = ? AND t.active = 1 AND EXISTS (SELECT 1 FROM live_chat_team_users tu INNER JOIN users u ON u.id = tu.user_id AND u.active = 1 WHERE tu.team_id = t.id) LIMIT 1');
                $target->execute([$toTeamId]);
                $row = $target->fetch();
                if (!$row) { $this->db->rollBack(); return null; }
                $this->db->prepare("UPDATE ai_conversations SET channel='human',status='queued',assigned_user_id=NULL,assigned_team_id=?,updated_at=NOW() WHERE id=?")->execute([$toTeamId, $conversation]);
                $label = (string) $row['name'];
            }
            $this->db->prepare('INSERT INTO live_chat_transfers (conversation_id,from_user_id,to_user_id,to_team_id,note,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$conversation, $fromUserId, $toUserId, $toTeamId, $note !== '' ? $note : null]);
            $this->db->commit();
            return ['label' => $label, 'type' => $toUserId !== null ? 'user' : 'team'];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function transferHistory(string $conversation): array
    {
        $statement = $this->db->prepare('SELECT tr.*, source.name AS from_name, target.name AS to_user_name, team.name AS to_team_name FROM live_chat_transfers tr LEFT JOIN users source ON source.id=tr.from_user_id LEFT JOIN users target ON target.id=tr.to_user_id LEFT JOIN live_chat_teams team ON team.id=tr.to_team_id WHERE tr.conversation_id=? ORDER BY tr.id');
        $statement->execute([$conversation]);
        return $statement->fetchAll();
    }

    public function deleteConversation(string $conversation): bool
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM ai_conversations WHERE id = ? FOR UPDATE');
            $lock->execute([$conversation]);
            if (!$lock->fetchColumn()) { $this->db->rollBack(); return false; }
            $this->db->prepare('DELETE FROM live_chat_reads WHERE conversation_id = ?')->execute([$conversation]);
            $this->db->prepare('DELETE FROM live_chat_transfers WHERE conversation_id = ?')->execute([$conversation]);
            $this->db->prepare('DELETE FROM ai_messages WHERE conversation_id = ?')->execute([$conversation]);
            $statement = $this->db->prepare("UPDATE ai_conversations SET visitor_name = NULL, visitor_email = NULL, channel = 'human', status = 'closed', assigned_user_id = NULL, assigned_team_id = NULL, updated_at = NOW() WHERE id = ?");
            $statement->execute([$conversation]);
            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function purgeClosedConversations(int $days, int $limit = 500): int
    {
        $threshold = (new \DateTimeImmutable())->modify('-' . max(1, min(365, $days)) . ' days')->format('Y-m-d H:i:s');
        $statement = $this->db->prepare('SELECT id FROM ai_conversations WHERE status = \'closed\' AND updated_at < ? ORDER BY updated_at LIMIT ?');
        $statement->bindValue(1, $threshold);
        $statement->bindValue(2, max(1, min(2000, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $ids = array_column($statement->fetchAll(), 'id');
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->beginTransaction();
        try {
            foreach (['live_chat_reads', 'live_chat_transfers', 'ai_messages'] as $table) $this->db->prepare("DELETE FROM {$table} WHERE conversation_id IN ({$placeholders})")->execute($ids);
            $delete = $this->db->prepare("DELETE FROM ai_conversations WHERE status = 'closed' AND id IN ({$placeholders})");
            $delete->execute($ids);
            $count = $delete->rowCount();
            $this->db->commit();
            return $count;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function canAccessConversation(string $conversation, int $userId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM ai_conversations c WHERE c.id = ? AND ' . $this->accessSql('c') . ' LIMIT 1');
        $statement->execute([$conversation, $userId, $userId]);
        return (bool) $statement->fetchColumn();
    }

    private function accessSql(string $alias): string
    {
        return "({$alias}.assigned_user_id = ? OR ({$alias}.assigned_user_id IS NULL AND ({$alias}.assigned_team_id IS NULL OR EXISTS (SELECT 1 FROM live_chat_team_users access_team WHERE access_team.team_id = {$alias}.assigned_team_id AND access_team.user_id = ?))))";
    }
}
