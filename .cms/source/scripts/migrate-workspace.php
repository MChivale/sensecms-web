<?php

declare(strict_types=1);

// Default: schema only. Activation is an explicit, separate deployment gate.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
try {
    $mode = $argv[1] ?? '';
    if (count($argv) > 2 || !in_array($mode, ['', '--status', '--enable', '--help'], true)) throw new RuntimeException('Usage: php scripts/migrate-workspace.php [--status|--enable|--help]');
    if ($mode === '--help') { echo "Default: migrate schema only. --status: read-only schema check. --enable: activate a prepared Workspace, preserving its identity. Back up the database and private storage first.\n"; exit; }
    $runtime = new App\Core\Runtime(dirname(__DIR__));
    $installed = $runtime->read('installed');
    if (!$installed) throw new RuntimeException('Complete the licensed Core installation first.');
    $runtime->license()->enforce($runtime->baseUrl());
    $db = App\Core\Runtime::connect($installed['database']);
    $migration = new App\Installer\WorkspaceMigration($db, $runtime->root);
    if ($mode === '--status') echo json_encode($migration->status(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    elseif ($mode === '--enable') { $migration->enable($runtime); echo "Workspace enabled; installation-local encryption identity preserved.\n"; }
    else echo 'Applied Workspace migrations: ' . $migration->apply() . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Workspace database migration failed. Inspect schema and migration state.\n" : $error->getMessage() . PHP_EOL);
    exit(1);
}
