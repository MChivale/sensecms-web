<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
try {
    $mode = $argv[1] ?? '';
    if (!(($mode === 'install' && $argc === 4) || ($mode === 'rollback' && $argc === 3))) throw new RuntimeException('Usage: php scripts/theme.php install <signed.zip> <trust.json> | rollback <trust.json>');
    $runtime = new App\Core\Runtime(dirname(__DIR__));
    if (!$runtime->read('installed')) throw new RuntimeException('Complete Core installation first.');
    $runtime->license()->enforce($runtime->baseUrl());
    $trust = $argv[$argc - 1];
    if (is_link($trust) || !is_file($trust) || filesize($trust) > 65536) throw new RuntimeException('Invalid trust store.');
    $encoded = json_decode((string) file_get_contents($trust), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($encoded)) throw new RuntimeException('Invalid trust store.');
    $keys = [];
    foreach ($encoded as $id => $value) {
        $key = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Invalid publisher public key.');
        $keys[$id] = $key;
    }
    $manager = new App\Core\Packages\ThemeManager($runtime);
    $release = $mode === 'install' ? $manager->install($argv[2], $keys) : $manager->rollback($keys);
    echo 'Active theme: ' . $release['slug'] . ' ' . $release['version'] . PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
