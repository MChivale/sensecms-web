<?php

declare(strict_types=1);

use App\Core\Database;
use SenseCMS\Calendar\CalendarDispatcher;

if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = rtrim((string) ($argv[1] ?? dirname(__DIR__, 3)), '/\\');
$lock = fopen(sys_get_temp_dir() . '/sensecms-calendar-' . substr(hash('sha256', $root), 0, 16) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Calendar worker is already running.\n");
spl_autoload_register(static function (string $class) use ($root): void { if (str_starts_with($class, 'App\\')) { $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php'; if (is_file($file)) require $file; } });
require_once dirname(__DIR__) . '/src/CalendarIntegrationManager.php';
require_once dirname(__DIR__) . '/src/CalendarDispatcher.php';
$config = require $root . '/config/app.php';
$db = (new Database($config['database']))->connection();
$result = (new CalendarDispatcher($db, $config, $root))->run((int) ($argv[2] ?? 50));
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
