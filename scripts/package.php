<?php

declare(strict_types=1);

use App\Core\Packages\Archive;
use App\Core\Packages\Manifest;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/.cms/source/bootstrap.php';

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'build' && $argc === 5) {
        $keyFile = (string) getenv('SENSE_PACKAGE_SIGNING_KEY_FILE');
        if ($keyFile === '' || is_link($keyFile) || !is_file($keyFile) || filesize($keyFile) > 4096) throw new RuntimeException('Set SENSE_PACKAGE_SIGNING_KEY_FILE to a protected Base64 Ed25519 secret-key file.');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($keyFile) & 0077)) throw new RuntimeException('Signing key permissions must exclude group and other access.');
        $key = base64_decode(trim((string) file_get_contents($keyFile)), true);
        if (!is_string($key)) throw new RuntimeException('Invalid signing key encoding.');
        try {
            $source = realpath($argv[2]);
            $expectedRoot = realpath(dirname(__DIR__) . '/.' . $argv[4] . 's');
            if (!in_array($argv[4], Manifest::TYPES, true) || !$source || !$expectedRoot
                || !str_starts_with(strtolower(str_replace('\\', '/', $source)), strtolower(str_replace('\\', '/', $expectedRoot)) . '/')) throw new RuntimeException('Package source must be inside its project package directory.');
            $keyPath = realpath($keyFile);
            if (!$keyPath || str_starts_with(strtolower(str_replace('\\', '/', $keyPath)), strtolower(str_replace('\\', '/', $source)) . '/')) throw new RuntimeException('Signing key must remain outside the package source.');
            $manifest = json_decode((string) file_get_contents($source . '/sense-package.json'), true, 16, JSON_THROW_ON_ERROR);
            if (($manifest['type'] ?? null) !== $argv[4] || ($manifest['slug'] ?? null) !== basename($source)) throw new RuntimeException('Package type or source directory does not match its manifest.');
            $result = Archive::build($source, $argv[3], $key);
        } finally { sodium_memzero($key); }
    } elseif ($mode === 'verify' && $argc === 5) {
        if (!is_file($argv[3]) || filesize($argv[3]) > 65536) throw new RuntimeException('Invalid trust-store file.');
        $encoded = json_decode((string) file_get_contents($argv[3]), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($encoded)) throw new RuntimeException('Trust store must map key IDs to Base64 public keys.');
        $keys = [];
        foreach ($encoded as $id => $value) {
            $key = is_string($value) ? base64_decode($value, true) : false;
            if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('Invalid trusted public key.');
            $keys[$id] = $key;
        }
        $manifest = Archive::verify($argv[2], $keys);
        // Standalone validation deliberately rejects unresolved dependencies.
        Manifest::compatible($manifest, $argv[4], PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION);
        $result = ['identity' => Manifest::identity($manifest), 'version' => $manifest['version'], 'sha256' => hash_file('sha256', $argv[2]), 'verified' => true];
    } else {
        throw new RuntimeException("Usage:\n  php scripts/package.php build <source> <output.zip> <module|plugin|addon|theme>\n  php scripts/package.php verify <archive.zip> <trust-store.json> <core-version>");
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
