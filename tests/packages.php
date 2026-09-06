<?php

declare(strict_types=1);

use App\Core\Packages\Archive;
use App\Core\Packages\Manifest;
use App\Core\Packages\Plan;

require dirname(__DIR__) . '/.cms/source/bootstrap.php';

$temp = sys_get_temp_dir() . '/sense-package-test-' . bin2hex(random_bytes(12));
if (!mkdir($temp, 0700)) throw new RuntimeException('Cannot create isolated test directory.');
$count = 0;
$assert = static function (bool $ok, string $name) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $count++; echo 'PASS ' . $name . PHP_EOL;
};
$reject = static function (callable $call, string $name) use ($assert): void {
    try { $call(); } catch (RuntimeException | JsonException $e) { $assert(true, $name); return; }
    $assert(false, $name);
};
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$keys = ['test-key' => $public];
$manifest = ['schema' => 1, 'type' => 'module', 'slug' => 'test-package', 'name' => 'Isolated test fixture', 'version' => '0.1.0',
    'requires' => ['php' => ['min' => '8.5.0', 'max_exclusive' => '9.0.0'], 'core' => ['min' => '0.1.0', 'max_exclusive' => '1.0.0']],
    'publisher' => ['key_id' => 'test-key', 'name' => 'Test runner'], 'dependencies' => []];
$writeManifest = static function (array $data) use ($temp): void {
    file_put_contents($temp . '/source/sense-package.json', json_encode($data, JSON_THROW_ON_ERROR));
};
$modify = static function (string $base, string $target, callable $change): void {
    if (!copy($base, $target)) throw new RuntimeException('Cannot copy test archive.');
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) throw new RuntimeException('Cannot open test archive.');
    $change($zip);
    if (!$zip->close()) throw new RuntimeException('Cannot close test archive.');
};
try {
    mkdir($temp . '/source'); mkdir($temp . '/source/assets');
    file_put_contents($temp . '/source/assets/test.css', ':root{--color:blue}');
    foreach (Manifest::TYPES as $type) {
        $data = $manifest; $data['type'] = $type; $writeManifest($data);
        $result = Archive::build($temp . '/source', $temp . '/' . $type . '.zip', $secret);
        $verified = Archive::verify($temp . '/' . $type . '.zip', $keys);
        Manifest::compatible($verified, '0.1.0', '8.5.5');
        $assert($result['manifest']['type'] === $type && $verified === $result['manifest'], 'signed ' . $type . ' round-trip');
    }
    $writeManifest($manifest);
    $base = $temp . '/module.zip';
    $valid = Archive::verify($base, $keys);
    $reject(fn() => Archive::verify($base, []), 'unknown publisher');
    $reject(fn() => Archive::verify($base, ['test-key' => random_bytes(32)]), 'wrong public key');
    $reject(fn() => Manifest::compatible($valid, '1.0.0', '8.5.5'), 'exclusive Core version upper bound');
    $reject(fn() => Manifest::compatible($valid, '0.1.0', '8.4.0'), 'PHP below requirement');
    $required = $valid; $required['dependencies'] = ['plugin:example' => ['min' => '1.0.0', 'max_exclusive' => '2.0.0']];
    $reject(fn() => Manifest::compatible($required, '0.1.0', '8.5.5'), 'missing dependency');
    Manifest::compatible($required, '0.1.0', '8.5.5', ['plugin:example' => '1.1.0']);
    $assert(true, 'satisfied dependency');
    $required['dependencies'] = ['module:test-package' => ['min' => '0.1.0', 'max_exclusive' => '1.0.0']];
    $reject(fn() => Manifest::validate($required, true), 'self dependency');
    $before = hash_file('sha256', $base);
    $reject(fn() => Archive::build($temp . '/source', $base, $secret), 'immutable release');
    $assert(hash_file('sha256', $base) === $before, 'existing archive preserved');
    $reject(fn() => Archive::build($temp . '/source', $temp . '/source/release.zip', $secret), 'output inside source');
    Archive::build($temp . '/source', $temp . '/repeated.zip', $secret);
    $assert(hash_file('sha256', $temp . '/repeated.zip') === $before, 'reproducible archive');
    $modify($base, $temp . '/tampered.zip', static fn(ZipArchive $zip) => $zip->addFromString('payload/assets/test.css', 'tampered'));
    $reject(fn() => Archive::verify($temp . '/tampered.zip', $keys), 'tampered payload');
    $modify($base, $temp . '/metadata.zip', static fn(ZipArchive $zip) => $zip->addFromString('sense-package.json', str_replace('0.1.0', '0.2.0', (string) $zip->getFromName('sense-package.json'))));
    $reject(fn() => Archive::verify($temp . '/metadata.zip', $keys), 'tampered manifest');
    $modify($base, $temp . '/extra.zip', static fn(ZipArchive $zip) => $zip->addFromString('payload/extra.php', '<?php return true;'));
    $reject(fn() => Archive::verify($temp . '/extra.zip', $keys), 'unlisted payload');
    $modify($base, $temp . '/missing.zip', static fn(ZipArchive $zip) => $zip->deleteName('payload/assets/test.css'));
    $reject(fn() => Archive::verify($temp . '/missing.zip', $keys), 'missing payload');
    $modify($base, $temp . '/unsigned.zip', static fn(ZipArchive $zip) => $zip->deleteName('signature.ed25519'));
    $reject(fn() => Archive::verify($temp . '/unsigned.zip', $keys), 'missing signature');
    foreach (['../escape.php', '/absolute.php', 'C:/drive.php', '.env', '.cfg/SSH.txt', 'data/.hidden', 'data/../escape', 'test\\file', 'file:stream', 'NUL.txt', 'assets/COM1', 'trailing.', 'nested//file', 'storage/license/keypair.bin', 'secret.pem', 'web.config'] as $path) {
        $reject(fn() => Manifest::path($path), 'unsafe path ' . $path);
    }
    $modify($base, $temp . '/traversal.zip', static fn(ZipArchive $zip) => $zip->addFromString('payload/../escape.php', 'unsafe'));
    $reject(fn() => Archive::verify($temp . '/traversal.zip', $keys), 'ZIP traversal');
    $modify($base, $temp . '/collision.zip', static fn(ZipArchive $zip) => $zip->addFromString('payload/assets/TEST.css', 'collision'));
    $reject(fn() => Archive::verify($temp . '/collision.zip', $keys), 'case-colliding ZIP');
    $modify($base, $temp . '/symlink.zip', static function (ZipArchive $zip): void {
        $zip->setExternalAttributesName('payload/assets/test.css', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    });
    $reject(fn() => Archive::verify($temp . '/symlink.zip', $keys), 'ZIP symlink');
    $modify($base, $temp . '/large.zip', static fn(ZipArchive $zip) => $zip->addFromString('payload/large.txt', str_repeat('A', 10485761)));
    $reject(fn() => Archive::verify($temp . '/large.zip', $keys), 'oversized compressed payload');
    $seen = []; Manifest::uniquePath('asset', $seen);
    $reject(static function () use (&$seen): void { Manifest::uniquePath('asset/file', $seen); }, 'file-directory collision');
    file_put_contents($temp . '/source/.env', 'test-only');
    $reject(fn() => Archive::build($temp . '/source', $temp . '/secret.zip', $secret), 'source secret excluded by rejection');
    unlink($temp . '/source/.env');
    $data = $manifest; $data['schema'] = 2;
    $reject(fn() => Manifest::validate($data), 'unsupported schema');
    $data = $manifest; $data['unknown'] = true;
    $reject(fn() => Manifest::validate($data), 'unknown manifest field');
    $data = $manifest; $data['version'] = '1.0.0-beta';
    $reject(fn() => Manifest::validate($data), 'unsupported version syntax');
    $data = $manifest; $data['type'] = 'core';
    $reject(fn() => Manifest::validate($data), 'Core is not an extension package');
    $dependency = $valid; $dependency['slug'] = 'base';
    $dependant = $valid; $dependant['slug'] = 'feature';
    $dependant['dependencies'] = ['module:base' => ['min' => '0.1.0', 'max_exclusive' => '0.2.0']];
    $assert(Plan::resolve([$dependant, $dependency], [], '0.1.0', '8.5.5') === ['module:base', 'module:feature'], 'dependency-first plan');
    $assert(Plan::resolve([$dependant], [$dependency], '0.1.0', '8.5.5') === ['module:feature'], 'installed dependency reused');
    $reject(fn() => Plan::resolve([$dependant], [], '0.1.0', '8.5.5'), 'missing planned dependency');
    $reject(fn() => Plan::resolve([$dependency, $dependency], [], '0.1.0', '8.5.5'), 'duplicate plan identity');
    $dependency['dependencies'] = ['module:feature' => ['min' => '0.1.0', 'max_exclusive' => '1.0.0']];
    $reject(fn() => Plan::resolve([$dependant, $dependency], [], '0.1.0', '8.5.5'), 'cyclic dependency');
    $dependency['dependencies'] = [];
    $upgrade = $dependency; $upgrade['version'] = '0.2.0';
    $reject(fn() => Plan::resolve([$upgrade], [$dependency, $dependant], '0.1.0', '8.5.5'), 'upgrade breaks existing dependant');
    $reject(fn() => Plan::resolve([$dependency], [$dependency], '0.1.0', '8.5.5'), 'same version reinstall');
    $reject(fn() => Plan::resolve([$dependency], [$upgrade], '0.1.0', '8.5.5'), 'downgrade refused');
    $upgrade['version'] = '0.1.1';
    $assert(Plan::resolve([$upgrade], [$dependency, $dependant], '0.1.0', '8.5.5') === ['module:base'], 'compatible upgrade');
    echo "\n{$count} package checks passed.\n";
} finally {
    sodium_memzero($secret); sodium_memzero($pair);
    // Only the unique directory created by this test is eligible for cleanup.
    $expected = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . basename($temp);
    if (realpath($temp) !== $expected || !preg_match('/^sense-package-test-[a-f0-9]{24}$/D', basename($temp))) throw new RuntimeException('Unsafe test cleanup target.');
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($temp);
}
