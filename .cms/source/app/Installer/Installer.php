<?php

declare(strict_types=1);

namespace App\Installer;

use App\Core\Runtime;
use PDO;
use RuntimeException;

final class Installer
{
    public function __construct(private readonly Runtime $runtime) {}

    public function requirements(): array
    {
        $checks = ['PHP 8.5+' => PHP_VERSION_ID >= 80500];
        foreach (['curl', 'pdo_mysql', 'sodium', 'zip', 'mbstring', 'session'] as $extension) $checks['PHP ' . $extension] = extension_loaded($extension);
        $checks['Argon2id password hashing'] = in_array('argon2id', password_algos(), true);
        $checks['Writable private storage'] = is_writable($this->runtime->root . '/storage');
        $checks['Memory limit 128 MB+'] = ini_get('memory_limit') === '-1' || ini_parse_quantity(ini_get('memory_limit')) >= 134217728;
        return $checks;
    }

    public static function administrator(#[\SensitiveParameter] array $input): array
    {
        $name = trim((string) ($input['admin_name'] ?? '')); $email = strtolower(trim((string) ($input['admin_email'] ?? '')));
        $password = (string) ($input['admin_password'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f]/', $name) || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid owner name and email.');
        if (strlen($password) < 14 || strlen($password) > 200 || str_contains($password, "\0") || $password !== ($input['admin_confirm'] ?? '')) throw new RuntimeException('Use matching owner passwords of 14–200 characters.');
        return ['name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_ARGON2ID)];
    }

    public function install(#[\SensitiveParameter] array $input): void
    {
        $dbConfig = Runtime::databaseInput($input); $admin = self::administrator($input);
        $siteName = trim((string) ($input['site_name'] ?? ''));
        if ($siteName === '' || mb_strlen($siteName) > 120) throw new RuntimeException('Enter a site name up to 120 characters.');
        if (in_array(false, $this->requirements(), true)) throw new RuntimeException('Server requirements are not satisfied.');
        $lockPath = $this->runtime->root . '/storage/install.lock';
        if (is_link($lockPath)) throw new RuntimeException('Invalid installer lock.');
        $mask = umask(0077); $lock = fopen($lockPath, 'c+b'); umask($mask);
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another installation is in progress.');
        $db = null; $dbLock = null;
        try {
            if ($this->runtime->read('installed')) throw new RuntimeException('Sense CMS is already installed.');
            $baseUrl = $this->runtime->baseUrl(); $this->runtime->license()->enforce($baseUrl);
            $db = Runtime::connect($dbConfig);
            $version = (string) $db->query('SELECT VERSION()')->fetchColumn();
            preg_match('/\d+\.\d+\.\d+/', $version, $match);
            if (!$match || version_compare($match[0], str_contains(strtolower($version), 'mariadb') ? '10.11.0' : '8.0.29', '<')) throw new RuntimeException('MySQL 8.0.29+ or MariaDB 10.11+ is required.');
            $dbLock = 'sense-install-' . substr(hash('sha256', $dbConfig['name']), 0, 48);
            $stmt = $db->prepare('SELECT GET_LOCK(?, 0)'); $stmt->execute([$dbLock]);
            if ((int) $stmt->fetchColumn() !== 1) { $dbLock = null; throw new RuntimeException('Database installation is already locked.'); }
            $fingerprint = hash('sha256', json_encode([$dbConfig['host'], $dbConfig['port'], $dbConfig['name'], $dbConfig['user'], $admin['email'], $baseUrl], JSON_THROW_ON_ERROR));
            $progress = $this->runtime->read('installing');
            if ($progress && ($progress['fingerprint'] ?? '') !== $fingerprint) throw new RuntimeException('An unfinished installation belongs to different connection or owner details.');
            // Preserve resumability of the earlier initial-Core installer; new installations include Workspace.
            $fullWorkspace = !$progress || isset($progress['workspace_schema']);
            $migration = new WorkspaceMigration($db, $this->runtime->root);
            $plan = $fullWorkspace ? $migration->plan() : ['files'=>[], 'tables'=>[]];
            if ($fullWorkspace && !str_contains(strtolower($version), 'mariadb')) throw new RuntimeException('This Workspace candidate requires MariaDB 10.11+. MySQL migration support is not yet verified.');
            if ($progress && $fullWorkspace && $progress['workspace_schema'] !== $plan['files']) throw new RuntimeException('Workspace schema changed during an unfinished installation. Restore the original migration files.');
            $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (!$progress && $tables) throw new RuntimeException('Use a new empty database. Existing data will not be overwritten.');
            if (array_diff($tables, array_merge(['migrations','users','roles','user_roles','settings','activity_log','login_attempts'], $plan['tables']))) throw new RuntimeException('Unexpected tables in the installation database.');
            $schema = (string) file_get_contents($this->runtime->root . '/database/001_core.sql'); $checksum = hash('sha256', $schema);
            if ($progress && ($progress['schema'] ?? '') !== $checksum) throw new RuntimeException('Schema changed during an unfinished installation.');
            if (!$progress) $this->runtime->write('installing', ['fingerprint' => $fingerprint, 'schema' => $checksum, 'workspace_schema'=>$plan['files']]);
            // DDL is not transactional in MySQL; each statement is idempotent and
            // resumption is restricted to the same owned, initially empty database.
            foreach (explode(';', $schema) as $statement) if (trim($statement) !== '') $db->exec($statement);
            $db->beginTransaction();
            $db->exec("INSERT IGNORE INTO roles (slug,name) VALUES ('owner','Owner')");
            $stmt = $db->prepare('INSERT IGNORE INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP())'); $stmt->execute(array_values($admin));
            $stmt = $db->prepare('SELECT id,password FROM users WHERE email=?'); $stmt->execute([$admin['email']]); $owner = $stmt->fetch();
            if (!$owner || !password_verify($input['admin_password'], $owner['password'])) throw new RuntimeException('Resume using the original owner password.');
            $stmt = $db->prepare("INSERT IGNORE INTO user_roles SELECT ?,id FROM roles WHERE slug='owner'"); $stmt->execute([$owner['id']]);
            $stmt = $db->prepare("INSERT IGNORE INTO settings (`key`,value) VALUES ('site_name',?)"); $stmt->execute([$siteName]);
            $stmt = $db->prepare("INSERT IGNORE INTO migrations (name,checksum,applied_at) VALUES ('001_core',?,UTC_TIMESTAMP())"); $stmt->execute([$checksum]);
            if (!(int) $db->query("SELECT COUNT(*) FROM activity_log WHERE event='core.installed'")->fetchColumn()) $db->prepare("INSERT INTO activity_log (user_id,event,created_at) VALUES (?,'core.installed',UTC_TIMESTAMP())")->execute([$owner['id']]);
            $db->commit();
            if ($fullWorkspace) {
                $migration->apply();
                $workspace = $this->runtime->read('workspace');
                if ($workspace && (!is_string($workspace['secret'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $workspace['secret']))) throw new RuntimeException('Invalid Workspace encryption identity. Do not overwrite its private configuration.');
                // Public routing also requires installed.json, written only after all migrations succeed.
                $this->runtime->write('workspace', array_replace($workspace, ['enabled'=>true, 'secret'=>$workspace['secret'] ?? bin2hex(random_bytes(32))]));
            }
            $this->runtime->write('installed', ['base_url' => $baseUrl, 'database' => $dbConfig, 'installed_at' => gmdate(DATE_ATOM), 'core_version' => '0.1.0']);
            unlink($this->runtime->root . '/storage/installing.json');
        } finally {
            if ($db?->inTransaction()) $db->rollBack();
            if ($db && $dbLock) $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$dbLock]);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
