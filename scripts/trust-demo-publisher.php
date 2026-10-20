<?php

declare(strict_types=1);

use App\Core\PackageManager;
use App\Core\Packages\Archive;
use App\Core\Runtime;

if (PHP_SAPI !== 'cli' || $argc !== 3 || $argv[1] !== '/home/demo.sensecms.com/web' || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    throw new RuntimeException('Run as the operator for the demo installation only.');
}
$root = $argv[1];
require $root . '/bootstrap.php';
$runtime = new Runtime($root);
$installed = $runtime->read('installed');
if ($runtime->baseUrl() !== 'https://demo.sensecms.com' || ($installed['database']['name'] ?? '') !== 'sensecms_demo') {
    throw new RuntimeException('Demo identity does not match.');
}
$trusted = json_decode((string) file_get_contents('/root/sensecms-private/trust.json'), true, 16, JSON_THROW_ON_ERROR);
$encoded = $trusted['sensecms-release'] ?? null;
$key = is_string($encoded) ? base64_decode($encoded, true) : false;
if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
    throw new RuntimeException('Official publisher trust is unavailable.');
}
$manifest = Archive::verify($argv[2], ['sensecms-release' => $key]);
if (($manifest['type'] ?? '') !== 'theme' || ($manifest['slug'] ?? '') !== 'sense-demo' || ($manifest['version'] ?? '') !== '1.0.1') {
    throw new RuntimeException('The archive is not the reviewed demo theme.');
}
$db = Runtime::connect($installed['database']);
if ((int) $db->query('SELECT COUNT(*) FROM extension_publishers')->fetchColumn() !== 0) {
    throw new RuntimeException('Publisher trust has changed; inspect it before continuing.');
}
$owner = (int) $db->query('SELECT id FROM users WHERE is_demo=0 AND active=1 ORDER BY id LIMIT 1')->fetchColumn();
if ($owner < 1) throw new RuntimeException('An active Owner account is required.');
$product = require $root . '/config/product.php';
(new PackageManager($db, $root, $product['core_version']))->trustPublisher('sensecms-release', 'QUANT Software House Limited', 'https://www.sensecms.com', $encoded, $owner);
echo "Official publisher trusted in the demo installation.\n";
