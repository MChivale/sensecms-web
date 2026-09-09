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
foreach (['name', 'model'] as $field) {
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
$invalid = $data; $invalid['product']['model'] = 'Other package';
$reject(fn() => $client->assertCached($invalid, time()), 'cached product mismatch');
$invalid = $data; $invalid['valid_until'] = 'never';
$reject(fn() => $client->assertCached($invalid, time()), 'cached date type mismatch');

foreach (['2.0', '9.4.1', null] as $version) {
    $updated = $reply; $updated['data']['product']['version'] = $version;
    $assert($client->response(200, $encode($updated)) === $data, 'response version does not restrict entitlement');
    $updated = $data; $updated['product']['version'] = $version;
    $client->assertCached($updated, time());
    $assert(true, 'cached release version does not restrict entitlement');
}
$updated = $reply; unset($updated['data']['product']['version']);
$assert($client->response(200, $encode($updated)) === $data, 'provider version is not required for authorization');
foreach (['null', 'false', '42', '"unexpected"', '[]'] as $body) $reject(fn() => $client->response(200, $body), 'non-object license response rejected');

$cms = $product['license'];
$free = ['pricing' => 'free', 'version' => '9.0.0'];
$paid = ['pricing' => 'paid', 'version' => '2.3.4', 'license' => ['product_name' => 'Fixture Calendar', 'product_model' => 'Fixture Calendar Addon', 'product_version' => '2.3.4']];
$policy = App\Core\Packages\Entitlement::class;
$assert($policy::licenseConfig($free, $cms) === $cms, 'free package requires the CMS identity');
$free['license'] = $paid['license'];
$assert($policy::licenseConfig($free, $cms) === $cms, 'free package cannot override the CMS identity');
$paidConfig = $policy::licenseConfig($paid, $cms);
$assert($paidConfig['product_name'] === 'Fixture Calendar' && $paidConfig['product_model'] === 'Fixture Calendar Addon' && $paidConfig['product_version'] === '1.0', 'paid identity is separate with fixed protocol version');
$paidClient = new LicenseClient($paidConfig);
$reject(fn() => $paidClient->response(200, $encode($reply)), 'CMS license cannot authorize a paid package');
$paidReply = $reply; $paidReply['data']['product'] = ['name' => 'Fixture Calendar', 'model' => 'Fixture Calendar Addon', 'version' => '9.0'];
$paidData = $paidClient->response(200, $encode($paidReply));
$assert($paidData['product']['version'] === '1.0', 'paid updates use the same entitlement');
$reject(fn() => $client->response(200, $encode($paidReply)), 'paid license cannot replace a CMS license');
$other = $paid; $other['license']['product_model'] = 'Fixture Other Addon';
$otherClient = new LicenseClient($policy::licenseConfig($other, $cms));
$reject(fn() => $otherClient->response(200, $encode($paidReply)), 'license for one paid package cannot authorize another');
$expired = $paidReply; $expired['data']['valid_to'] = '2026-01-02 00:00:00';
$reject(fn() => $paidClient->response(200, $encode($expired)), 'paid license expiry still enforced');
foreach ([[], ['pricing' => 'unknown'], ['pricing' => false], ['pricing' => 'paid'], ['pricing' => 'paid', 'license' => $cms], ['pricing' => 'paid', 'license' => ['product_name' => 'x', 'product_model' => "bad\r\nheader"]]] as $invalid) {
    $reject(fn() => $policy::licenseConfig($invalid, $cms), 'undeclared or unsafe package entitlement rejected');
}
$headers = $policy::headers($paid, str_repeat('a', 32), 'https://WWW.SenseCMS.com/', $cms);
$assert(in_array('X-SenseCMS-Version: 1.0', $headers, true) && in_array('X-SenseCMS-Domain: https://www.sensecms.com', $headers, true), 'download uses fixed license version and canonical domain');
$upgraded = $paid; $upgraded['version'] = '15.0.0'; $upgraded['license']['product_version'] = '15.0';
$assert($headers === $policy::headers($upgraded, str_repeat('a', 32), 'https://www.sensecms.com', $cms), 'new package version sends the same license request');
$reject(fn() => $policy::headers($paid, "invalid\r\nheader", 'https://www.sensecms.com', $cms), 'download credential injection rejected');
$reject(fn() => $policy::headers($paid, str_repeat('a', 32), 'https://sensecms.com/path', $cms), 'download domain path rejected');

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
    $assert($service->telegramHeaders('https://WWW.SenseCMS.com/','https://www.sensecms.com/api/telegram/v1')===['Authorization: Bearer '.$record['key'],'X-SenseCMS-Domain: https://www.sensecms.com'],'Telegram exports validated canonical installation identity');
    $reject(fn()=>$service->telegramHeaders('https://www.sensecms.com','https://attacker.example'),'Telegram credential export refuses third party');
    $reject(fn()=>$service->telegramHeaders('https://other.example','https://www.sensecms.com/api/telegram/v1'),'Telegram credential export refuses foreign installation');
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
