<?php

declare(strict_types=1);

// Disposable PostgreSQL acceptance for the new-install schema. Run only as the
// local PostgreSQL administrator: SENSE_POSTGRES_QA=1 php tests/postgres-fresh-schema.php
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows' || getenv('SENSE_POSTGRES_QA') !== '1') {
    fwrite(STDERR, "Run only as the local PostgreSQL QA administrator with SENSE_POSTGRES_QA=1.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/.cms/source/bootstrap.php';

$name = 'senseqa_' . bin2hex(random_bytes(6));
if (!preg_match('/^senseqa_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Invalid disposable database name.');
$admin = new PDO('pgsql:dbname=postgres', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = null;
try {
    $admin->exec('CREATE DATABASE ' . $name);
    $db = new App\Core\PostgresConnection('pgsql:dbname=' . $name, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec("SET TIME ZONE 'UTC'");
    $db->exec('CREATE EXTENSION vector');
    App\Installer\PostgresSchema::prepare($db);
    $core = (string) file_get_contents($root . '/.cms/source/database/001_core.sql');
    foreach (App\Installer\PostgresSchema::statements($core) as $statement) $db->exec($statement);
    $checksum = hash('sha256', $core);
    $db->exec("INSERT IGNORE INTO roles (slug,name) VALUES ('owner','Owner')");
    $db->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute(['PostgreSQL QA','owner@example.test',password_hash('temporary-test-password', PASSWORD_ARGON2ID)]);
    $db->prepare("INSERT INTO settings (`key`,value) VALUES ('site_name',?)")->execute(['PostgreSQL QA']);
    $db->prepare("INSERT INTO migrations (name,checksum,applied_at) VALUES ('001_core',?,UTC_TIMESTAMP())")->execute([$checksum]);
    $applied = (new App\Installer\WorkspaceMigration($db, $root . '/.cms/source'))->apply();
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['workspace_migrations','facilities','ai_semantic_cache'] as $table) {
        if (!in_array($table, $tables, true) && $table !== 'ai_semantic_cache') throw new RuntimeException('Missing PostgreSQL table: ' . $table);
    }
    $db->exec('ALTER TABLE ai_knowledge_chunks ADD COLUMN IF NOT EXISTS embedding_model text');
    $db->exec('CREATE TABLE IF NOT EXISTS ai_semantic_cache (model text NOT NULL, content_hash text NOT NULL, embedding vector(1536) NOT NULL, PRIMARY KEY (model, content_hash))');
    $db->exec('CREATE TABLE IF NOT EXISTS ai_semantic_usage (day date NOT NULL, purpose text NOT NULL, requests integer NOT NULL, input_tokens integer NOT NULL DEFAULT 0, PRIMARY KEY (day, purpose))');
    if ((int) $db->query("SELECT COUNT(*) FROM pg_extension WHERE extname='vector'")->fetchColumn() !== 1) throw new RuntimeException('pgvector extension was not enabled.');
    echo $applied . " PostgreSQL Workspace migrations passed on a disposable database.\n";
} finally {
    $db = null;
    try { $admin->exec('DROP DATABASE IF EXISTS ' . $name); } catch (Throwable) {}
}
