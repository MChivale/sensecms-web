<?php
declare(strict_types=1);

// Verify the exact immutable Marketplace release set. No database or network use.
if (PHP_SAPI !== 'cli' || $argc !== 3) exit("Usage: php tests/marketplace-release.php <release-directory> <trusted-public-keys.json>\n");
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$release = realpath($argv[1]);
if (!$release || !is_dir($release) || is_link($release)) throw new RuntimeException('Invalid release directory.');
$encoded = json_decode((string) file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
if (!is_array($encoded)) throw new RuntimeException('Invalid publisher trust.');
$keys = [];
foreach ($encoded as $id => $value) {
    $key = is_string($value) ? base64_decode($value, true) : false;
    if (is_string($id) && is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) $keys[$id] = $key;
}
$inventoryRaw = (string) file_get_contents($release . '/release-set.json');
$signature = base64_decode(trim((string) file_get_contents($release . '/release-set.sig')), true);
$trusted = false; foreach ($keys as $key) if (is_string($signature) && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($signature, $inventoryRaw, $key)) $trusted = true;
if (!$trusted) throw new RuntimeException('Release inventory signature is not trusted.');
$inventory = json_decode($inventoryRaw, true, 16, JSON_THROW_ON_ERROR);
if (($inventory['schema'] ?? null) !== 1 || !is_array($inventory['products'] ?? null)) throw new RuntimeException('Invalid release inventory.');
$root = dirname(__DIR__); $expected = [];
foreach (['theme','addon','plugin'] as $type) {
    $base = $root . '/.' . $type . 's'; if (!is_dir($base)) continue;
    foreach (new DirectoryIterator($base) as $entry) {
        if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) continue;
        $directoryIdentity = $type . ':' . $entry->getFilename();
        if (in_array($directoryIdentity, ['theme:sensecms', 'theme:eduvixo'], true)) continue;
        if (!is_file($entry->getPathname() . '/sense-package.json')) throw new RuntimeException('Missing source manifest: ' . $directoryIdentity);
        $manifest = App\Core\Packages\Manifest::validate(json_decode((string) file_get_contents($entry->getPathname() . '/sense-package.json'), true, 16, JSON_THROW_ON_ERROR));
        $identity = App\Core\Packages\Manifest::identity($manifest);
        if (in_array($identity, ['theme:sensecms', 'theme:eduvixo'], true)) continue;
        $expected[$identity] = $manifest;
    }
}
ksort($expected, SORT_STRING); $actual = []; $count = 0;
$check = static function (bool $condition, string $label) use (&$count): void { if (!$condition) throw new RuntimeException($label); $count++; echo "PASS $label\n"; };
foreach ($inventory['products'] as $entry) {
    if (!is_array($entry)) throw new RuntimeException('Invalid release product.');
    $identity = (string) ($entry['identity'] ?? '');
    if (isset($actual[$identity]) || !isset($expected[$identity])) throw new RuntimeException('Unexpected release identity: ' . $identity);
    $file = (string) ($entry['file'] ?? ''); App\Core\Packages\Manifest::path($file);
    $path = $release . '/' . $file;
    $check(is_file($path) && !is_link($path) && hash_file('sha256', $path) === ($entry['sha256'] ?? null) && filesize($path) === ($entry['bytes'] ?? null), 'Exact archive bytes: ' . $identity);
    $manifest = App\Core\Packages\Archive::verify($path, $keys);
    $check(App\Core\Packages\Manifest::identity($manifest) === $identity && $manifest['version'] === $expected[$identity]['version'] && ($entry['channel'] ?? '') === 'stable', 'Stable signed identity: ' . $identity);
    $actual[$identity] = $manifest;
}
$check(array_keys($actual) === array_keys($expected), 'Every non-internal package source has one release archive');
$versions = array_map(static fn(array $manifest): string => $manifest['version'], $actual);
foreach ($actual as $identity => $manifest) {
    App\Core\Packages\Manifest::compatible($manifest, '1.0.0', PHP_VERSION, $versions);
    $check(true, 'Dependencies are installable: ' . $identity);
}
echo "Completed $count Marketplace release checks.\n";
