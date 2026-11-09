<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AiSemanticSearch
{
    public const MODEL = 'text-embedding-3-large';
    public const DIMENSIONS = 1536;
    private const READY = "d.status='published' AND d.index_status='ready'";
    private const HASH = "encode(sha256(convert_to(c.content,'UTF8')),'hex')";

    private function __construct(private readonly PDO $db, private readonly Secrets $secrets,
        private readonly string $providerSlug, private readonly AiProviderClient $client) {}

    public static function configured(Runtime $runtime, PDO $db, Secrets $secrets): ?self
    {
        $settings = $runtime->read('semantic-search');
        if (($settings['model'] ?? null) !== self::MODEL || empty($settings['provider_slug'])
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') return null;
        return new self($db, $secrets, $settings['provider_slug'], new AiProviderClient());
    }

    private function embed(array $texts, string $purpose): array
    {
        $provider = (new AiRepository($this->db))->provider($this->providerSlug);
        if (!$provider || $provider['driver'] !== 'openai-responses' || empty($provider['api_key_encrypted'])
            || !in_array(rtrim((string)($provider['base_url'] ?? ''), '/'), ['', 'https://api.openai.com/v1'], true))
            throw new RuntimeException('The configured OpenAI embedding provider is unavailable.');
        $reserve = $this->db->prepare('INSERT INTO ai_semantic_usage (day,purpose,requests) VALUES (CURRENT_DATE,?,1)
            ON CONFLICT (day,purpose) DO UPDATE SET requests=ai_semantic_usage.requests+1
            WHERE ai_semantic_usage.requests < ? RETURNING day');
        $reserve->execute([$purpose, $purpose === 'index' ? 150 : 300]);
        $day = $reserve->fetchColumn();
        if (!$day) throw new RuntimeException('Daily embedding request limit reached.');
        $result = $this->client->embeddings($this->secrets->decrypt($provider['api_key_encrypted']),
            $texts, $purpose === 'index' ? 30 : 6);
        $this->db->prepare('UPDATE ai_semantic_usage SET input_tokens=input_tokens+? WHERE day=? AND purpose=?')
            ->execute([$result['tokens'], $day, $purpose]);
        return $result['vectors'];
    }

    public function indexBatch(): int
    {
        if (!$this->db->query("SELECT pg_try_advisory_lock(hashtextextended(current_database() || ':semantic-index',0))")->fetchColumn()) return 0;
        try {
            $scope = self::READY;
            $hash = self::HASH;
            // Retain vectors across the nightly rebuild of logical chunks, but remove orphaned content.
            $this->db->prepare("DELETE FROM ai_semantic_cache k WHERE k.model=? AND NOT EXISTS
                (SELECT 1 FROM ai_knowledge_chunks c JOIN ai_knowledge_documents d ON d.id=c.document_id
                 WHERE {$scope} AND {$hash}=k.content_hash)")->execute([self::MODEL]);
            $missing = $this->db->prepare("SELECT DISTINCT {$hash} AS digest,c.content FROM ai_knowledge_chunks c
                JOIN ai_knowledge_documents d ON d.id=c.document_id WHERE {$scope}
                AND NOT EXISTS (SELECT 1 FROM ai_semantic_cache k WHERE k.model=? AND k.content_hash={$hash})
                ORDER BY digest LIMIT 16");
            $missing->execute([self::MODEL]);
            $rows = $missing->fetchAll();
            $vectors = $rows ? $this->embed(array_column($rows,'content'), 'index') : [];
            $this->db->beginTransaction();
            try {
                $save = $this->db->prepare("INSERT INTO ai_semantic_cache (model,content_hash,embedding)
                    SELECT ?,?,CAST(? AS vector) WHERE EXISTS
                    (SELECT 1 FROM ai_knowledge_chunks c JOIN ai_knowledge_documents d ON d.id=c.document_id
                     WHERE {$scope} AND {$hash}=?) ON CONFLICT DO NOTHING");
                foreach ($rows as $i=>$row) $save->execute([self::MODEL,$row['digest'],$vectors[$i],$row['digest']]);
                $this->db->prepare("UPDATE ai_knowledge_chunks c SET embedding=k.embedding,embedding_model=k.model
                    FROM ai_semantic_cache k,ai_knowledge_documents d WHERE c.document_id=d.id AND {$scope}
                    AND (c.embedding IS NULL OR c.embedding_model IS DISTINCT FROM k.model)
                    AND k.model=? AND k.content_hash={$hash}")->execute([self::MODEL]);
                $this->db->commit();
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
            return count($rows);
        } finally {
            $this->db->query("SELECT pg_advisory_unlock(hashtextextended(current_database() || ':semantic-index',0))");
        }
    }

    public function matches(string $question, string $locale): array
    {
        $scope = self::READY . ' AND (d.locale=? OR d.locale IS NULL) AND c.embedding IS NOT NULL AND c.embedding_model=?';
        $from = ' FROM ai_knowledge_chunks c JOIN ai_knowledge_documents d ON d.id=c.document_id WHERE '.$scope;
        $available = $this->db->prepare('SELECT 1'.$from.' LIMIT 1');
        $available->execute([$locale,self::MODEL]);
        if (!$available->fetchColumn()) return [];
        $vector = $this->embed([$question],'query')[0];
        $statement = $this->db->prepare("SELECT document_id,title,url,content FROM (
            SELECT d.id document_id,d.title,COALESCE(d.source_url,'') AS url,c.content,c.id,c.embedding <=> CAST(? AS vector) AS distance
            {$from}) AS ranked WHERE distance<=0.70 ORDER BY distance,id LIMIT 8");
        $statement->execute([$vector,$locale,self::MODEL]);
        return $statement->fetchAll();
    }

    public static function combine(array $keyword, array $semantic): array
    {
        if (!$semantic) return $keyword;
        $rows = $scores = [];
        foreach ([$semantic,$keyword] as $list) {
            foreach ($list as $rank=>$row) {
                $id = hash('sha256', ($row['document_id']??'')."\0".$row['url']."\0".$row['title']."\0".$row['content']);
                $rows[$id] = $row;
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (10 + $rank);
            }
        }
        arsort($scores);
        return array_map(static fn($id) => $rows[$id], array_slice(array_keys($scores),0,8));
    }
}
