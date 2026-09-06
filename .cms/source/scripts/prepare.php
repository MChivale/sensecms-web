<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
try {
    if ($argc !== 2) throw new RuntimeException('Usage: php scripts/prepare.php https://canonical-domain.example');
    $runtime = new App\Core\Runtime(dirname(__DIR__));
    if ($runtime->read('installed') || $runtime->read('installing')) throw new RuntimeException('Installation is already present or in progress.');
    $runtime->write('setup', ['base_url' => App\Core\LicenseClient::domain($argv[1])]);
    echo "Canonical domain configured. Open /install to enter the license key.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
