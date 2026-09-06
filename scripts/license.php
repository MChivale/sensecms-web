<?php

declare(strict_types=1);

use App\Core\LicenseClient;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/.cms/source/bootstrap.php';

try {
    if ($argc !== 2 && !($argc === 3 && $argv[2] === '--test-storage')) throw new RuntimeException('Usage: php scripts/license.php <canonical-https-url> [--test-storage]');
    $product = require dirname(__DIR__) . '/.cms/source/config/product.php';
    $file = dirname(__DIR__) . '/.cfg/License.txt';
    if (!is_file($file) || filesize($file) > 8192) throw new RuntimeException('License configuration is missing or invalid.');
    $cfg = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!preg_match('/^\s*([^:=]+)\s*[:=]\s*(.*)$/D', $line, $match)) throw new RuntimeException('Invalid license configuration line.');
        $name = preg_replace('/\s+/', '', $match[1]);
        if (!in_array($name, ['ProductName', 'ProductModel', 'ProductVersion', 'LicenseKey'], true) || isset($cfg[$name])) throw new RuntimeException('Invalid or duplicate license configuration field.');
        $cfg[$name] = trim($match[2]);
    }
    foreach (['ProductName' => 'product_name', 'ProductModel' => 'product_model', 'ProductVersion' => 'product_version'] as $field => $name) {
        if (($cfg[$field] ?? null) !== $product['license'][$name]) throw new RuntimeException('Configured license product differs from Core identity.');
    }
    $client = new LicenseClient($product['license']);
    $storageTest = ($argv[2] ?? '') === '--test-storage';
    if ($storageTest) {
        $temp = sys_get_temp_dir() . '/sense-live-license-' . bin2hex(random_bytes(12));
        $mask = umask(0077);
        try { if (!mkdir($temp, 0700)) throw new RuntimeException('Cannot create private test directory.'); }
        finally { umask($mask); }
        try {
            $service = new App\Core\LicenseService($temp, $client);
            $result = $service->install($cfg['LicenseKey'] ?? '', $argv[1]);
            if ($result !== $service->enforce($argv[1])) throw new RuntimeException('Stored license did not match remote validation.');
            if (str_contains((string) file_get_contents($temp . '/license.lic'), $cfg['LicenseKey'])) throw new RuntimeException('Plaintext key found in stored license.');
            // Reinstall verifies atomic replacement without changing the installed key.
            $before = hash_file('sha256', $temp . '/key.bin');
            $service->install($cfg['LicenseKey'], $argv[1]);
            if (hash_file('sha256', $temp . '/key.bin') !== $before || $service->enforce($argv[1]) !== $result) throw new RuntimeException('License replacement did not preserve installation identity.');
        } finally {
            $expected = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . basename($temp);
            if (realpath($temp) !== $expected || !preg_match('/^sense-live-license-[a-f0-9]{24}$/D', basename($temp))) throw new RuntimeException('Unsafe test cleanup target.');
            foreach (['key.bin', 'license.lic', 'license.lock'] as $name) if (is_file($temp . '/' . $name)) unlink($temp . '/' . $name);
            rmdir($temp);
        }
    } else {
        $result = $client->validate($cfg['LicenseKey'] ?? '', $argv[1]);
    }
    echo json_encode(['verified' => true, 'domain' => LicenseClient::domain($argv[1]), 'product' => $result['product'], 'expires_at' => $result['valid_until'], 'storage_round_trip' => $storageTest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    // Deliberately omit tracebacks and provider response details.
    fwrite(STDERR, $error instanceof App\Core\LicenseException || $error instanceof RuntimeException ? $error->getMessage() . PHP_EOL : "License validation failed.\n");
    exit(1);
}
