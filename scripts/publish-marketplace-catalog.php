<?php
declare(strict_types=1);

// Operator-only. Signs a public, metadata-only Marketplace catalogue from the
// exact enabled package inventory. It never publishes archives by itself.
if (PHP_SAPI !== 'cli' || $argc !== 4) exit("Usage: php publish-marketplace-catalog.php <installation-root> <copy.php-or-json> <private-signing-key>\n");
umask(0077);
$root = realpath($argv[1]);
if (!$root || !is_file($root . '/bootstrap.php')) throw new RuntimeException('Invalid installation root.');
foreach ([$argv[2], $argv[3]] as $path) if (is_link($path) || !is_file($path) || filesize($path) > 524288) throw new RuntimeException('Invalid operator input.');
if (PHP_OS_FAMILY !== 'Windows' && (fileperms($argv[3]) & 0077)) throw new RuntimeException('Private signing key permissions required.');
require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root);
if ($runtime->baseUrl() !== 'https://www.sensecms.com') throw new RuntimeException('Official website only.');
$copy = str_ends_with($argv[2], '.php') ? require $argv[2] : json_decode((string) file_get_contents($argv[2]), true, 64, JSON_THROW_ON_ERROR);
if (!is_array($copy)) throw new RuntimeException('Invalid public package copy.');
$copyByIdentity = [];
foreach ($copy as $identity => $presentation) {
    if (is_int($identity) && is_array($presentation)) $identity = (string) ($presentation['type'] ?? '') . ':' . (string) ($presentation['slug'] ?? '');
    if (!is_string($identity) || !is_array($presentation)) throw new RuntimeException('Invalid public package copy.');
    $copyByIdentity[$identity] = $presentation;
}
$distribution = new App\Core\Packages\Distribution($runtime);
$offers = $distribution->offers(); ksort($offers, SORT_STRING);
$products = [];
foreach ($offers as $identity => $offer) {
    if (($offer['channel'] ?? '') !== 'stable') continue;
    $presentation = $copyByIdentity[$identity] ?? null;
    if (!is_array($presentation)) throw new RuntimeException('Missing public copy for ' . $identity . '.');
    foreach (['name', 'description'] as $field) if (!is_string($presentation[$field] ?? null) || trim($presentation[$field]) === '' || strlen($presentation[$field]) > 500) throw new RuntimeException('Invalid public copy.');
    $products[] = [
        'id' => substr(hash('sha256', $identity), 0, 32), 'installable' => true,
        'type' => $offer['type'], 'slug' => $offer['slug'], 'name' => $presentation['name'],
        'version' => $offer['version'], 'channel' => 'stable', 'pricing' => $offer['pricing'],
        'package_checksum' => $offer['sha256'],
        'package_url' => App\Core\OfficialCatalog::BASE . '/package/?id=' . substr(hash('sha256', $identity), 0, 32),
        'copy' => ['en' => ['description' => $presentation['description'], 'meta' => [$offer['pricing'] === 'free' ? 'Included with a valid Sense CMS Core licence' : 'Requires its own valid product licence']]],
    ];
    if ($offer['pricing'] === 'paid') $products[array_key_last($products)]['license'] = $offer['license'] ?? null;
}
$secret = base64_decode(trim((string) file_get_contents($argv[3])), true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Invalid signing key.');
try {
    $payload = json_encode(['schema'=>1, 'issued_at'=>time(), 'expires_at'=>time()+86400, 'products'=>$products], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $raw = json_encode(['signed_payload'=>base64_encode($payload), 'signature'=>base64_encode(sodium_crypto_sign_detached($payload, $secret))], JSON_THROW_ON_ERROR);
} finally { sodium_memzero($secret); }
// Verify with the deployed trust key before replacing the active catalogue.
App\Core\OfficialCatalog::verify($raw);
$path = $root . '/storage/marketplace-catalog.json';
if (is_link($path)) throw new RuntimeException('Unsafe catalog target.');
$temp = $path . '.' . bin2hex(random_bytes(8));
try {
    if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw) || !chmod($temp, 0644) || !rename($temp, $path)) throw new RuntimeException('Cannot publish Marketplace catalogue.');
} finally { if (is_file($temp)) unlink($temp); }
echo 'Signed ' . count($products) . " Stable Marketplace packages.\n";
