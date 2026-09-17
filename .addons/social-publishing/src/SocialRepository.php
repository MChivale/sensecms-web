<?php

declare(strict_types=1);

namespace SenseCMS\Social;

use PDO;
use RuntimeException;

final class SocialRepository
{
    public function __construct(private readonly PDO $db, private readonly SocialIntegrationManager $integrations, private readonly string $baseUrl) {}

    public function overview(int $limit = 50): array
    {
        $counts = ['pending'=>0,'processing'=>0,'published'=>0,'failed'=>0];
        foreach ($this->db->query('SELECT status,COUNT(*) total FROM social_deliveries GROUP BY status')->fetchAll() as $row) $counts[(string)$row['status']] = (int)$row['total'];
        return ['providers'=>$this->integrations->catalog(),'counts'=>$counts,'deliveries'=>$this->deliveries($limit)];
    }

    public function editorState(int $postId): array
    {
        $targets = [];
        if ($postId > 0) {
            $statement = $this->db->prepare('SELECT connection_id,enabled,message,revision,last_enqueued_revision FROM social_post_targets WHERE post_id=? AND connection_id IS NOT NULL');
            $statement->execute([$postId]);
            foreach ($statement->fetchAll() as $row) $targets[(int)$row['connection_id']] = $row;
        }
        $providers = $this->integrations->catalog();
        foreach ($providers as &$provider) {
            foreach ($provider['connections'] as &$connection) {
                $target = $targets[$connection['id']] ?? null;
                $connection['selected'] = (bool) ($target['enabled'] ?? false);
                $connection['message'] = (string) ($target['message'] ?? '');
                $connection['published_before'] = $target && (int)$target['last_enqueued_revision'] > 0;
            }
            unset($connection);
        }
        unset($provider);
        return ['providers'=>$providers,'post_id'=>$postId];
    }

    public function syncPost(int $postId, array $submitted, bool $republish, int $actorId): void
    {
        if ($postId < 1) throw new RuntimeException('The post is invalid.');
        $known = [];
        foreach ($this->integrations->catalog() as $provider) {
            foreach ($provider['connections'] as $connection) $known[$connection['id']] = ['provider'=>$provider, 'connection'=>$connection];
        }
        $selected = [];
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT id,enabled,revision,last_enqueued_revision FROM social_post_targets WHERE post_id=? AND connection_id=? FOR UPDATE');
            foreach ($known as $connectionId => $destination) {
                $input = is_array($submitted[$connectionId] ?? null) ? $submitted[$connectionId] : [];
                $enabled = !empty($input['enabled']);
                if ($enabled && !$destination['connection']['enabled']) throw new RuntimeException('Connect the selected social account before publishing.');
                if ($enabled) $selected[] = $connectionId;
                $message = trim((string) ($input['message'] ?? ''));
                if (mb_strlen($message) > 5000 || str_contains($message, "\0")) throw new RuntimeException('A social message is too long or invalid.');
                $find->execute([$postId, $connectionId]);
                $current = $find->fetch();
                if ($current) {
                    $revision = (int) $current['revision'];
                    if ($republish && $enabled && (int)$current['last_enqueued_revision'] >= $revision) $revision++;
                    $this->db->prepare('UPDATE social_post_targets SET enabled=?,message=?,revision=?,updated_at=NOW() WHERE id=?')->execute([$enabled?1:0,$message?:null,$revision,$current['id']]);
                } elseif ($enabled || $message !== '') {
                    $this->db->prepare('INSERT INTO social_post_targets (post_id,plugin_slug,connection_id,enabled,message,revision,last_enqueued_revision,updated_at) VALUES (?,?,?,?,?,1,0,NOW())')->execute([$postId,$destination['provider']['slug'],$connectionId,$enabled?1:0,$message?:null]);
                }
            }
            $this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,"social.targets.changed","post",?,?,NOW())')->execute([$actorId?:null,$postId,json_encode(['connection_ids'=>$selected,'republish'=>$republish],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->queuePost($postId);
    }

    public function queueDue(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->db->prepare("SELECT DISTINCT t.post_id FROM social_post_targets t INNER JOIN social_connections c ON c.id=t.connection_id AND c.plugin_slug=t.plugin_slug AND c.enabled=1 INNER JOIN posts p ON p.id=t.post_id WHERE t.enabled=1 AND t.last_enqueued_revision<t.revision AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP())) ORDER BY t.updated_at LIMIT ?");
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        $count = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $postId) $count += $this->queuePost((int)$postId);
        return $count;
    }

    public function queuePost(int $postId): int
    {
        $payload = $this->payload($postId);
        if (!$payload) return 0;
        $statement = $this->db->prepare('SELECT t.id,t.plugin_slug,t.connection_id,t.revision,t.message,c.external_account_id,c.display_name FROM social_post_targets t INNER JOIN social_connections c ON c.id=t.connection_id AND c.plugin_slug=t.plugin_slug AND c.enabled=1 WHERE t.post_id=? AND t.enabled=1 AND t.last_enqueued_revision<t.revision');
        $statement->execute([$postId]);
        $count = 0;
        foreach ($statement->fetchAll() as $target) {
            $item = $payload;
            $item['message'] = trim((string)$target['message']) ?: $this->defaultMessage($payload);
            $json = json_encode($item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->db->beginTransaction();
            try {
                $insert = $this->db->prepare("INSERT IGNORE INTO social_deliveries (post_id,plugin_slug,connection_id,destination_external_id,destination_display_name,target_revision,payload,payload_hash,status,attempts,available_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $insert->execute([$postId,$target['plugin_slug'],(int)$target['connection_id'],$target['external_account_id'],$target['display_name'],(int)$target['revision'],$json,hash('sha256',$json)]);
                if ($insert->rowCount()) $count++;
                $this->db->prepare('UPDATE social_post_targets SET last_enqueued_revision=GREATEST(last_enqueued_revision,?),updated_at=NOW() WHERE id=?')->execute([(int)$target['revision'],$target['id']]);
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $error;
            }
        }
        return $count;
    }

    public function deliveries(int $limit = 50, ?int $postId = null): array
    {
        $limit = max(1, min(250, $limit));
        $where = $postId ? ' WHERE d.post_id=?' : '';
        $statement = $this->db->prepare("SELECT d.id,d.post_id,d.plugin_slug,d.connection_id,d.destination_external_id,d.destination_display_name,d.target_revision,d.status,d.attempts,d.available_at,d.external_id,d.external_url,d.last_error,d.created_at,d.published_at,(c.id IS NOT NULL AND c.enabled=1) connection_available,COALESCE(t.title,CONCAT('Post #',d.post_id)) post_title FROM social_deliveries d LEFT JOIN social_connections c ON c.id=d.connection_id AND c.plugin_slug=d.plugin_slug LEFT JOIN post_translations t ON t.post_id=d.post_id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1){$where} ORDER BY d.id DESC LIMIT ?");
        $position = 1;
        if ($postId) $statement->bindValue($position++, $postId, PDO::PARAM_INT);
        $statement->bindValue($position, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function retry(int $id): bool
    {
        $statement = $this->db->prepare("UPDATE social_deliveries d INNER JOIN social_connections c ON c.id=d.connection_id AND c.plugin_slug=d.plugin_slug AND c.enabled=1 SET d.status='pending',d.attempts=0,d.available_at=UTC_TIMESTAMP(),d.locked_at=NULL,d.last_error=NULL,d.updated_at=UTC_TIMESTAMP() WHERE d.id=? AND d.status='failed'");
        $statement->execute([$id]);
        return (bool)$statement->rowCount();
    }

    private function payload(int $postId): ?array
    {
        $statement = $this->db->prepare("SELECT p.id,p.status,p.published_at,t.locale,t.title,t.slug,t.excerpt,f.city_slug,f.facility_slug,f.is_primary,m.path image_path FROM posts p INNER JOIN post_translations t ON t.post_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1) INNER JOIN facilities f ON f.id=p.facility_id LEFT JOIN media m ON m.id=p.featured_media_id WHERE p.id=? AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=UTC_TIMESTAMP())) LIMIT 1");
        $statement->execute([$postId]);
        $post = $statement->fetch();
        if (!$post) return null;
        $base = rtrim($this->baseUrl, '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($base), 'https://')) throw new RuntimeException('The canonical installation URL must use HTTPS for social publishing.');
        $segments = [(string)$post['locale']];
        if (!(bool)$post['is_primary']) array_push($segments, 'facilities', (string)$post['city_slug'], (string)$post['facility_slug']);
        array_push($segments, 'posts', (string)$post['slug']);
        $url = $base . '/' . implode('/', array_map('rawurlencode', $segments));
        $image = trim((string)($post['image_path'] ?? ''));
        if ($image !== '' && !filter_var($image,FILTER_VALIDATE_URL)) $image = $base . '/' . ltrim($image,'/');
        if ($image !== '' && (!filter_var($image,FILTER_VALIDATE_URL) || !str_starts_with(strtolower($image),'https://'))) $image = '';
        return ['post_id'=>(int)$post['id'],'title'=>(string)$post['title'],'excerpt'=>(string)($post['excerpt']??''),'url'=>$url,'image_url'=>$image?:null,'locale'=>(string)$post['locale'],'published_at'=>$post['published_at']];
    }

    private function defaultMessage(array $payload): string
    {
        $message = trim((string)$payload['title']);
        $excerpt = trim(strip_tags((string)$payload['excerpt']));
        if ($excerpt !== '') $message .= "\n\n" . $excerpt;
        return mb_substr($message, 0, 5000);
    }
}
