<?php

declare(strict_types=1);

use App\Core\LicenseClient;
use App\Core\LicenseException;
use App\Core\LicenseService;

require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$product = require dirname(__DIR__) . '/.cms/source/config/product.php';
$client = new LicenseClient($product['license']);
$count = 0;
$assert = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS ' . $label . PHP_EOL; $count++;
};
$reject = static function (callable $call, string $label) use ($assert): void {
    try { $call(); } catch (LicenseException) { $assert(true, $label); return; }
    $assert(false, $label);
};
$reply = ['error' => false, 'data' => ['product' => ['name' => 'Sense CMS', 'model' => 'Sense CMS System', 'version' => '1.0'], 'valid_from' => '2026-01-01 00:00:00', 'valid_to' => null, 'client_id' => 'private-provider-field']];
$encode = static fn(array $data): string => json_encode($data, JSON_THROW_ON_ERROR);
$data = $client->response(200, $encode($reply));
$assert($data['valid_until'] === null && !isset($data['client_id']), 'valid response keeps only necessary fields');
$assert(LicenseClient::domain('https://WWW.SenseCMS.com:443/') === 'https://www.sensecms.com', 'canonical domain normalization');
$assert($product['core_version'] !== $product['license']['product_version'], 'Core and licensing versions are independent');
foreach (['http://www.sensecms.com', 'https://user:password@sensecms.com', 'https://sensecms.com/path', 'https://sensecms.com?key=x', 'https://sensecms.com/#x', 'https://sensecms.com:8080', '//sensecms.com'] as $url) $reject(fn() => LicenseClient::domain($url), 'invalid canonical URL');
foreach ([301, 400, 401, 429, 500] as $status) $reject(fn() => $client->response($status, $encode($reply)), 'HTTP ' . $status . ' rejected');
$reject(fn() => $client->response(200, '<html>not JSON</html>'), 'non-JSON response');
$reject(fn() => $client->response(200, str_repeat(' ', 65537)), 'oversized response');
foreach ([true, 'false', 0, null] as $error) {
    $invalid = $reply; $invalid['error'] = $error;
    $reject(fn() => $client->response(200, $encode($invalid)), 'explicit boolean success required');
}
foreach (['name', 'model', 'version'] as $field) {
    $invalid = $reply; $invalid['data']['product'][$field] = 'wrong';
    $reject(fn() => $client->response(200, $encode($invalid)), 'product ' . $field . ' mismatch');
}
$invalid = $reply; $invalid['data']['valid_to'] = '2026-02-30 12:00:00';
$reject(fn() => $client->response(200, $encode($invalid)), 'invalid calendar date');
$invalid['data']['valid_to'] = '2026-01-02 00:00:00';
$reject(fn() => $client->response(200, $encode($invalid)), 'expired license');
$invalid = $reply; $invalid['data']['valid_from'] = '2099-01-01 00:00:00';
$reject(fn() => $client->response(200, $encode($invalid)), 'future license');
$invalid = $reply; unset($invalid['data']['valid_to']);
$reject(fn() => $client->response(200, $encode($invalid)), 'missing expiry field is not unlimited');
$reject(fn() => LicenseClient::assertKey('invalid'), 'malformed key');
$reject(fn() => LicenseClient::assertPeriod(null, 100, 100), 'exact expiry boundary');
$invalid = $data; $invalid['product']['version'] = '9.0';
$reject(fn() => $client->assertCached($invalid, time()), 'cached product mismatch');
$invalid = $data; $invalid['valid_until'] = 'never';
$reject(fn() => $client->assertCached($invalid, time()), 'cached date type mismatch');

// Authenticated test fixtures are built only here; no production validation bypass.
$temp = sys_get_temp_dir() . '/sense-license-test-' . bin2hex(random_bytes(12));
if (!mkdir($temp, 0700)) throw new RuntimeException('Cannot create test storage.');
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$record = ['key' => str_repeat('a', 32), 'domain' => 'https://www.sensecms.com', 'data' => $data, 'checked_at' => time()];
$write = static function (array $record) use ($temp, $key): void {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    file_put_contents($temp . '/license.lic', "SENSECMS-LIC-1\n" . base64_encode($nonce . sodium_crypto_secretbox(json_encode($record, JSON_THROW_ON_ERROR), $nonce, $key)));
    chmod($temp . '/license.lic', 0600);
};
try {
    file_put_contents($temp . '/key.bin', $key); chmod($temp . '/key.bin', 0600); $write($record);
    $service = new LicenseService($temp, $client);
    $assert($service->enforce('https://www.sensecms.com') === $data, 'authenticated fresh cache accepted');
    $assert(!str_contains((string) file_get_contents($temp . '/license.lic'), $record['key']), 'license not stored in plaintext');
    $reject(fn() => $service->enforce('https://sensecms.com'), 'cache domain mismatch');
    $invalid = $record; $invalid['checked_at'] = time() + 300; $write($invalid);
    $reject(fn() => $service->enforce('https://www.sensecms.com'), 'backward clock movement');
    $invalid = $record; $invalid['data']['valid_until'] = time() - 1; $write($invalid);
    $reject(fn() => $service->enforce('https://www.sensecms.com'), 'expired license rejected within cache TTL');
    $write($record); $raw = file_get_contents($temp . '/license.lic');
    $raw[30] = $raw[30] === 'A' ? 'B' : 'A'; file_put_contents($temp . '/license.lic', $raw);
    $reject(fn() => $service->enforce('https://www.sensecms.com'), 'encrypted cache tampering');
    $write($record); file_put_contents($temp . '/key.bin', random_bytes(32));
    $reject(fn() => $service->enforce('https://www.sensecms.com'), 'foreign installation encryption key');
    file_put_contents($temp . '/key.bin', $key);
    $invalid = $record; $invalid['checked_at'] = time() - 604800; $write($invalid);
    $offlineConfig = $product['license']; $offlineConfig['endpoint'] = 'https://invalid.test/';
    $offline = new LicenseService($temp, new LicenseClient($offlineConfig));
    $reject(fn() => $offline->enforce('https://www.sensecms.com'), 'stale cache must revalidate; no unlimited offline grace');
    $write($record);
    $changedConfig = $product['license']; $changedConfig['product_model'] = 'Other product';
    $changed = new LicenseService($temp, new LicenseClient($changedConfig));
    $reject(fn() => $changed->enforce('https://www.sensecms.com'), 'identity change invalidates fresh cache');
} finally {
    sodium_memzero($key);
    $expected = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . basename($temp);
    if (realpath($temp) !== $expected || !preg_match('/^sense-license-test-[a-f0-9]{24}$/D', basename($temp))) throw new RuntimeException('Unsafe test cleanup target.');
    foreach (['key.bin', 'license.lic', 'license.lock'] as $file) if (is_file($temp . '/' . $file)) unlink($temp . '/' . $file);
    rmdir($temp);
}
echo "\n{$count} licensing checks passed.\n";
