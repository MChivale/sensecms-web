<?php
declare(strict_types=1);

use App\Core\Packages\Archive;
use App\Core\Packages\Manifest;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
umask(0077);

// Operator-only artifact preparation. This does not publish downloads or set prices.
$stage = null; $secret = null; $lock = null; $status = 0;
try {
    if ($argc !== 2) throw new RuntimeException('Usage: php scripts/build-packages.php <new-release-directory>');
    $parent = realpath(dirname($argv[1])); $name = basename($argv[1]);
    if (!$parent || !preg_match('/^[a-z0-9][a-z0-9.-]{0,99}$/D', $name)) throw new RuntimeException('Choose an existing parent and a safe release directory name.');
    $output = $parent . '/' . $name;
    $root = dirname(__DIR__);
    $sources = [];
    foreach (Manifest::TYPES as $type) {
        $base = $root . '/.' . $type . 's';
        if (!is_dir($base)) continue;
        if (is_link($base)) throw new RuntimeException('Package roots cannot be symlinks.');
        foreach (new DirectoryIterator($base) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isLink() || !$entry->isDir()) throw new RuntimeException('Package roots must contain only package directories.');
            $source = $entry->getPathname();
            $manifest = Manifest::validate(json_decode((string) file_get_contents($source . '/sense-package.json'), true, 16, JSON_THROW_ON_ERROR));
            if ($manifest['type'] !== $type || $manifest['slug'] !== $entry->getFilename()) throw new RuntimeException('Source directory and package identity differ.');
            $prefix = strtolower(str_replace('\\', '/', realpath($source))) . '/';
            if (str_starts_with(strtolower(str_replace('\\', '/', $output)) . '/', $prefix)) throw new RuntimeException('Release output cannot be inside package source.');
            $sources[Manifest::identity($manifest)] = [$source, $manifest];
        }
    }
    if (!$sources) throw new RuntimeException('No package sources found.');
    ksort($sources);
    $keyFile = (string) getenv('SENSE_PACKAGE_SIGNING_KEY_FILE');
    if (is_link($keyFile) || !is_file($keyFile) || filesize($keyFile) > 4096) throw new RuntimeException('Configure a protected signing key file.');
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($keyFile) & 0077)) throw new RuntimeException('Signing key must be private.');
    $keyPath = strtolower(str_replace('\\', '/', realpath($keyFile)));
    foreach ($sources as [$source]) if (str_starts_with($keyPath, strtolower(str_replace('\\', '/', realpath($source))) . '/')) throw new RuntimeException('Signing key must be outside package sources.');
    $secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
    if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Invalid signing key.');
    $public = sodium_crypto_sign_publickey_from_secretkey($secret);
    $lockPath = $parent . '/.sense-packages.lock';
    if (is_link($lockPath)) throw new RuntimeException('Unsafe release lock.');
    $lock = fopen($lockPath, 'c+b');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another release build is running.');
    if (file_exists($output) || is_link($output)) throw new RuntimeException('Release directory already exists; releases are immutable.');
    $stage = $parent . '/.sense-release-' . bin2hex(random_bytes(12));
    if (!mkdir($stage, 0700)) throw new RuntimeException('Cannot create private release staging.');
    $products = []; $checksums = []; $trust = [];
    foreach ($sources as $identity => [$source, $manifest]) {
        $file = $manifest['type'] . '-' . $manifest['slug'] . '-' . $manifest['version'] . '.zip';
        $built = Archive::build($source, $stage . '/' . $file, $secret);
        $trust[$manifest['publisher']['key_id']] = base64_encode($public);
        $products[] = ['identity'=>$identity, 'name'=>$manifest['name'], 'version'=>$manifest['version'],
            'file'=>$file, 'sha256'=>$built['sha256'], 'bytes'=>$built['bytes'], 'requires'=>$manifest['requires'],
            'publisher'=>$manifest['publisher'], 'channel'=>'development', 'distribution'=>'unpublished'];
        $checksums[] = $built['sha256'] . '  ' . $file;
    }
    $write = static function (string $file, string $data) use ($stage): void {
        if (file_put_contents($stage . '/' . $file, $data, LOCK_EX) !== strlen($data)) throw new RuntimeException('Cannot write release metadata.');
    };
    $inventory = json_encode(['schema'=>1, 'products'=>$products], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $write('release-set.json', $inventory);
    $write('release-set.sig', base64_encode(sodium_crypto_sign_detached($inventory, $secret)) . "\n");
    // Informational key copy, NOT an independent trust root for an untrusted download.
    $write('publisher-public.json', json_encode($trust, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    $write('SHA256SUMS', implode("\n", $checksums) . "\n");
    if (!rename($stage, $output)) throw new RuntimeException('Cannot publish the complete private release set.');
    $stage = null;
    echo json_encode(['directory'=>$output, 'packages'=>$products], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Package release failed: ' . $error->getMessage() . "\n");
    $status = 1;
} finally {
    if (is_string($secret)) sodium_memzero($secret);
    if ($stage !== null && is_dir($stage)) {
        // Only this invocation's random staging directory, containing flat build outputs.
        foreach (new DirectoryIterator($stage) as $file) if (!$file->isDot() && $file->isFile()) unlink($file->getPathname());
        rmdir($stage);
    }
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
exit($status);
