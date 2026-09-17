<?php

declare(strict_types=1);

namespace SenseCMS\Social;

use App\Core\Secrets;
use PDO;
use RuntimeException;
use Throwable;

final class SocialIntegrationManager
{
    private Secrets $secrets;

    public function __construct(private readonly PDO $db, private readonly string $root, string $secret)
    {
        $this->secrets = new Secrets($secret);
    }

    public function catalog(): array
    {
        $statement = $this->db->query("SELECT slug,install_path FROM extension_packages WHERE type='plugin' AND active=1 AND install_path IS NOT NULL ORDER BY name,slug");
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            try {
                [$manifest] = $this->manifest((string) $row['slug'], (string) $row['install_path']);
                $connections = $this->connections((string) $row['slug']);
                $connection = $connections[0] ?? null;
                $publisher = $manifest['social_publisher'];
                $result[] = [
                    'slug' => (string) $row['slug'],
                    'name' => mb_substr((string) ($manifest['name'] ?? $row['slug']), 0, 180),
                    'platform' => (string) $publisher['platform'],
                    'label' => mb_substr((string) ($publisher['label'] ?? $manifest['name'] ?? $row['slug']), 0, 100),
                    'icon' => (string) ($manifest['icon'] ?? 'send'),
                    'config_url' => (string) ($manifest['config_url'] ?? ''),
                    'connected' => $connections !== [],
                    'connected_count' => count($connections),
                    'connections' => $connections,
                    'enabled' => array_any($connections, static fn(array $item): bool => $item['enabled']),
                    'external_account_id' => (string) ($connection['external_account_id'] ?? ''),
                    'display_name' => (string) ($connection['display_name'] ?? ''),
                    'verified_at' => $connection['last_verified_at'] ?? null,
                    'last_error' => $connection['last_error'] ?? null,
                ];
            } catch (Throwable) {
                continue;
            }
        }
        return $result;
    }

    public function save(string $slug, string $externalId, string $displayName, array $credentials): array
    {
        [$manifest, $path] = $this->manifest($slug);
        $externalId = trim($externalId);
        $displayName = trim($displayName);
        if ($externalId === '' || strlen($externalId) > 191 || str_contains($externalId, "\0")) throw new RuntimeException('The social account identifier is invalid.');
        if ($displayName === '' || mb_strlen($displayName) > 180) throw new RuntimeException('The social account name is invalid.');
        if (!$credentials || strlen(json_encode($credentials, JSON_THROW_ON_ERROR)) > 32768) throw new RuntimeException('The social account credentials are invalid.');
        $provider = $this->provider($manifest, $path);
        if (!method_exists($provider, 'verify')) throw new RuntimeException('The social publisher cannot verify its connection.');
        $verified = $provider->verify($credentials);
        if (is_array($verified)) {
            $externalId = trim((string) ($verified['external_id'] ?? $externalId));
            $displayName = trim((string) ($verified['display_name'] ?? $displayName));
        }
        $encrypted = $this->secrets->encrypt(json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $statement = $this->db->prepare('INSERT INTO social_connections (plugin_slug,external_account_id,display_name,encrypted_credentials,enabled,last_verified_at,last_error,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NULL,NOW(),NOW()) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),encrypted_credentials=VALUES(encrypted_credentials),enabled=1,last_verified_at=NOW(),last_error=NULL,updated_at=NOW()');
        $statement->execute([$slug, $externalId, $displayName, $encrypted]);
        $find = $this->db->prepare('SELECT id FROM social_connections WHERE plugin_slug=? AND external_account_id=? LIMIT 1');
        $find->execute([$slug, $externalId]);
        $id = (int) $find->fetchColumn();
        if ($id < 1) throw new RuntimeException('The social account could not be stored.');
        return ['id'=>$id,'slug'=>$slug,'external_account_id'=>$externalId,'display_name'=>$displayName,'enabled'=>true,'verified_at'=>date('Y-m-d H:i:s')];
    }

    public function disconnect(string $slug, int $connectionId): void
    {
        $this->manifest($slug);
        if ($connectionId < 1) throw new RuntimeException('The social account identifier is invalid.');
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM social_connections WHERE id=? AND plugin_slug=?');
            $delete->execute([$connectionId, $slug]);
            if (!$delete->rowCount()) throw new RuntimeException('The selected social account is unavailable.', 404);
            $this->db->prepare('UPDATE social_post_targets SET enabled=0,updated_at=NOW() WHERE connection_id=?')->execute([$connectionId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function active(string $slug, int $connectionId): array
    {
        [$manifest, $path] = $this->manifest($slug);
        $connection = $this->connection($slug, $connectionId, true);
        if (!$connection || !$connection['enabled'] || !$connection['last_verified_at'] || $connection['last_error']) throw new RuntimeException('The selected social account is not connected.');
        return ['provider'=>$this->provider($manifest, $path),'credentials'=>$connection['credentials'],'connection'=>$connection];
    }

    private function connections(string $slug): array
    {
        $statement = $this->db->prepare('SELECT id,plugin_slug,external_account_id,display_name,enabled,last_verified_at,last_error,created_at,updated_at FROM social_connections WHERE plugin_slug=? ORDER BY display_name,id');
        $statement->execute([$slug]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['enabled'] = (bool) $row['enabled'];
        }
        unset($row);
        return $rows;
    }

    private function connection(string $slug, int $connectionId, bool $withCredentials = false): ?array
    {
        $statement = $this->db->prepare('SELECT id,plugin_slug,external_account_id,display_name,encrypted_credentials,enabled,last_verified_at,last_error,created_at,updated_at FROM social_connections WHERE id=? AND plugin_slug=? LIMIT 1');
        $statement->execute([$connectionId, $slug]);
        $row = $statement->fetch();
        if (!$row) return null;
        if ($withCredentials) {
            $decoded = json_decode($this->secrets->decrypt((string) $row['encrypted_credentials']), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('Stored social account credentials are invalid.');
            $row['credentials'] = $decoded;
        }
        unset($row['encrypted_credentials']);
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (bool) $row['enabled'];
        return $row;
    }

    private function manifest(string $slug, ?string $knownInstallPath = null): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) throw new RuntimeException('The social publisher identifier is invalid.');
        $installPath = $knownInstallPath;
        if ($installPath === null) {
            $statement = $this->db->prepare("SELECT install_path FROM extension_packages WHERE type='plugin' AND slug=? AND active=1 LIMIT 1");
            $statement->execute([$slug]);
            $installPath = $statement->fetchColumn();
        }
        $path = is_string($installPath) ? $this->path($installPath) : null;
        if (!$path || !is_file($path . '/plugin.json')) throw new RuntimeException('The social publisher is unavailable.', 404);
        $manifest = json_decode((string) file_get_contents($path . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
        $publisher = is_array($manifest['social_publisher'] ?? null) ? $manifest['social_publisher'] : null;
        if (!is_array($manifest) || !$publisher || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', (string) ($publisher['platform'] ?? ''))) throw new RuntimeException('The social publisher manifest is invalid.');
        return [$manifest, $path];
    }

    private function provider(array $manifest, string $path): object
    {
        $relative = str_replace('\\', '/', (string) ($manifest['social_publisher']['handler'] ?? ''));
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || !str_ends_with($relative, '.php')) throw new RuntimeException('The social publisher handler is invalid.');
        $file = realpath($path . '/' . $relative);
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/') . '/';
        if (!$file || !str_starts_with(str_replace('\\', '/', $file), $normalizedPath)) throw new RuntimeException('The social publisher handler is unavailable.');
        $provider = require $file;
        if (!is_object($provider) || !method_exists($provider, 'publish')) throw new RuntimeException('The social publisher handler is invalid.');
        return $provider;
    }

    private function path(string $installPath): ?string
    {
        $installPath = str_replace('\\', '/', trim($installPath, '/\\'));
        if (!preg_match('#^plugins/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $installPath)) return null;
        $base = realpath($this->root . '/plugins');
        $path = realpath($this->root . '/' . $installPath);
        if (!$base || !$path) return null;
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path . '/', $base) ? $path : null;
    }
}
