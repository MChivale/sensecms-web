<?php

declare(strict_types=1);

namespace App\Installer;

use PDO;
use RuntimeException;
use App\Core\Runtime;
use App\Core\Secrets;

/** Additive upgrade from the initial Sense Core; no imported owners or site seeds. */
final class WorkspaceMigration
{
    public function __construct(private readonly PDO $db, private readonly string $root) {}

    public function plan(): array
    {
        $directory = $this->root . '/database/workspace';
        if (is_link($directory) || !is_dir($directory)) throw new RuntimeException('Workspace migration directory is unavailable.');
        $files = glob($directory . '/*.sql') ?: []; sort($files, SORT_STRING);
        if (!$files) throw new RuntimeException('Workspace migration files are missing.');
        $hashes = []; $tables = ['workspace_migrations'];
        foreach ($files as $file) {
            if (is_link($file) || !is_file($file)) throw new RuntimeException('Invalid Workspace migration file.');
            $sql = (string) file_get_contents($file);
            $hashes[basename($file)] = hash('sha256', $sql);
            preg_match_all('/\bCREATE TABLE IF NOT EXISTS\s+`?([a-z][a-z0-9_]*)`?\s*\(/i', $sql, $matches);
            $tables = array_merge($tables, $matches[1]);
        }
        return ['files'=>$hashes, 'tables'=>array_values(array_unique($tables))];
    }

    /** Read-only schema check; never creates the migration journal. */
    public function status(): array
    {
        return $this->inspect($this->plan());
    }

    private function inspect(array $plan): array
    {
        $version = (string) $this->db->query('SELECT VERSION()')->fetchColumn();
        if (!str_contains($version, 'MariaDB') || !preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) || version_compare($match[1], '10.11.0', '<')) throw new RuntimeException('Workspace requires MariaDB 10.11 or newer.');
        $tables = $this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $initial = ['migrations','users','roles','user_roles','settings','activity_log','login_attempts'];
        if (array_diff($initial, $tables)) throw new RuntimeException('The initial Sense Core schema is incomplete.');
        $checksum = $this->db->query("SELECT checksum FROM migrations WHERE name='001_core'")->fetchColumn();
        if ($checksum !== hash_file('sha256', $this->root . '/database/001_core.sql')) throw new RuntimeException('Unexpected Sense Core schema identity.');
        $applied = [];
        if (in_array('workspace_migrations', $tables, true)) {
            $applied = $this->db->query('SELECT name,checksum FROM workspace_migrations ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($applied as $name => $hash) {
                if (!isset($plan['files'][$name]) || !hash_equals($plan['files'][$name], $hash)) throw new RuntimeException('Unexpected or modified Workspace migration: ' . $name);
            }
            if (array_keys($applied) !== array_slice(array_keys($plan['files']), 0, count($applied))) throw new RuntimeException('Workspace migration history is not a completed prefix.');
        } elseif (array_diff($tables, $initial)) throw new RuntimeException('Only a verified initial Sense CMS schema can be upgraded by this migration.');
        $pending = array_keys(array_diff_key($plan['files'], $applied));
        $missing = array_values(array_diff($plan['tables'], $tables));
        return ['applied'=>count($applied), 'pending'=>$pending, 'missing_tables'=>$missing, 'ready'=>!$pending && !$missing];
    }

    private function locked(callable $action): mixed
    {
        $schema = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($schema === '') throw new RuntimeException('Select the installation database.');
        $lock = 'sense-workspace-' . substr(hash('sha256', $schema), 0, 40);
        $statement = $this->db->prepare('SELECT GET_LOCK(?,0)'); $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Workspace migration is already running.');
        try { return $action(); }
        finally { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }

    public function apply(): int
    {
        return $this->locked(function (): int {
            $plan = $this->plan();
            $status = $this->inspect($plan); // Validate the entire journal before any DDL.
            $this->db->exec('CREATE TABLE IF NOT EXISTS workspace_migrations (name VARCHAR(191) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)');
            $applied = 0;
            foreach ($status['pending'] as $name) {
                $checksum = $plan['files'][$name];
                $file = $this->root . '/database/workspace/' . $name;
                $sql = (string) file_get_contents($file);
                if (!hash_equals($checksum, hash('sha256', $sql))) throw new RuntimeException('Workspace migration changed during execution.');
                // These reviewed schema files contain no routines or quoted semicolons.
                foreach (explode(';', $sql) as $part) if (trim($part) !== '') $this->db->exec($part);
                $statement = $this->db->prepare('INSERT INTO workspace_migrations (name,checksum,applied_at) VALUES (?,?,UTC_TIMESTAMP())');
                $statement->execute([$name, $checksum]); $applied++;
            }
            if (!$this->status()['ready']) throw new RuntimeException('Workspace tables are missing despite completed migration history. Restore the matching database backup.');
            return $applied;
        });
    }

    /** Explicit activation after schema preparation; never rotates an existing key. */
    public function enable(Runtime $runtime): void
    {
        if ($runtime->root !== $this->root || !$runtime->read('installed')) throw new RuntimeException('Complete the licensed Core installation first.');
        $this->locked(function () use ($runtime): void {
            if (!$this->status()['ready']) throw new RuntimeException('Complete the Workspace migrations before enabling the panel.');
            $path = $this->root . '/storage/workspace.json';
            $workspace = $runtime->read('workspace');
            $exists = file_exists($path) || is_link($path);
            if ($exists && (!is_file($path) || is_link($path) || !is_string($workspace['secret'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $workspace['secret']))) throw new RuntimeException('Restore the original Workspace encryption identity; it cannot be replaced.');
            $secrets = $exists ? new Secrets($workspace['secret']) : null;
            foreach ($this->ciphertexts() as $cipher) {
                if (!$secrets) throw new RuntimeException('Encrypted Workspace data exists. Restore its original encryption identity before activation.');
                try { $secrets->decrypt($cipher); }
                catch (\Throwable) { throw new RuntimeException('Workspace encryption identity cannot read existing data. Restore matching database and private storage backups.'); }
            }
            if (!$exists) $workspace['secret'] = bin2hex(random_bytes(32));
            if (($workspace['enabled'] ?? false) === true) return;
            $workspace['enabled'] = true;
            $runtime->write('workspace', $workspace);
        });
    }

    private function ciphertexts(): \Generator
    {
        foreach (['ai_providers'=>'api_key_encrypted', 'notification_channel_settings'=>'encrypted_settings', 'web_push_subscriptions'=>'encrypted_subscription'] as $table => $column) {
            $buffered = $this->db->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY);
            $this->db->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
            $statement = null;
            try {
                $statement = $this->db->query("SELECT `$column` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> ''");
                while (($value = $statement->fetchColumn()) !== false) yield (string) $value;
            } finally {
                $statement?->closeCursor();
                $this->db->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }
        $raw = $this->db->query("SELECT value FROM settings WHERE `key`='email_system_settings'")->fetchColumn();
        if ($raw !== false) {
            $settings = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($settings)) throw new RuntimeException('Invalid saved e-mail settings.');
            $cipher = $settings['server']['password_cipher'] ?? '';
            if (!is_string($cipher)) throw new RuntimeException('Invalid saved e-mail encryption data.');
            if ($cipher !== '') yield $cipher;
        }
    }
}
