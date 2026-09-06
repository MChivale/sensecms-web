<?php

declare(strict_types=1);

namespace App\Core\Packages;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class Archive
{
    public const MAX_FILES = 2000;
    private const MAX_ARCHIVE = 26214400;
    private const MAX_EXPANDED = 104857600;
    private const MAX_FILE = 10485760;
    private const MAX_MANIFEST = 524288;

    /** Build an immutable signed ZIP. Keys are supplied by the release operator. */
    public static function build(string $source, string $output, string $secret): array
    {
        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Invalid Ed25519 signing key.');
        if (is_link($source) || !($source = realpath($source)) || !is_dir($source)) throw new RuntimeException('Invalid package source.');
        $manifestFile = $source . '/sense-package.json';
        if (is_link($manifestFile) || !is_file($manifestFile) || filesize($manifestFile) > self::MAX_MANIFEST) throw new RuntimeException('Invalid source manifest.');
        $manifest = Manifest::validate(self::decode((string) file_get_contents($manifestFile)));
        $parent = realpath(dirname($output));
        if (!$parent || !is_dir($parent) || is_link($output) || file_exists($output)) throw new RuntimeException('Output directory must exist and release must not already exist.');
        $output = $parent . DIRECTORY_SEPARATOR . basename($output);
        $sourcePrefix = strtolower(str_replace('\\', '/', $source) . '/');
        if (str_starts_with(strtolower(str_replace('\\', '/', $output)), $sourcePrefix)) throw new RuntimeException('Release output must be outside the package source.');
        $files = []; $seen = []; $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlinks are forbidden in packages.');
            $resolved = $file->getRealPath();
            if (!$resolved || !str_starts_with(strtolower(str_replace('\\', '/', $resolved)), $sourcePrefix)) throw new RuntimeException('Package file escapes its source directory.');
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            if ($path === 'sense-package.json') continue;
            Manifest::path($path);
            if ($file->isDir()) continue;
            if (!$file->isFile()) throw new RuntimeException('Only regular package files are supported.');
            Manifest::uniquePath($path, $seen);
            $length = $file->getSize(); $size += $length;
            if ($length > self::MAX_FILE || $size > self::MAX_EXPANDED || count($files) >= self::MAX_FILES) throw new RuntimeException('Package size limit exceeded.');
            $files[$path] = $file->getPathname();
        }
        if (!$files) throw new RuntimeException('Package payload is empty.');
        ksort($files, SORT_STRING);
        $temporary = $parent . '/.sense-package-' . bin2hex(random_bytes(12));
        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create package archive.');
        try {
            $manifest['files'] = [];
            foreach ($files as $path => $file) {
                $contents = file_get_contents($file);
                if ($contents === false || strlen($contents) > self::MAX_FILE) throw new RuntimeException('Cannot read package file.');
                $manifest['files'][$path] = hash('sha256', $contents);
                self::add($zip, 'payload/' . $path, $contents);
            }
            Manifest::validate($manifest, true);
            $raw = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($raw) > self::MAX_MANIFEST) throw new RuntimeException('Manifest size limit exceeded.');
            self::add($zip, 'sense-package.json', $raw);
            self::add($zip, 'signature.ed25519', base64_encode(sodium_crypto_sign_detached($raw, $secret)));
            if (!$zip->close()) throw new RuntimeException('Cannot finalize package archive.');
            $verified = self::verify($temporary, [$manifest['publisher']['key_id'] => sodium_crypto_sign_publickey_from_secretkey($secret)]);
            // A same-filesystem hard link publishes atomically and refuses replacement.
            if (!@link($temporary, $output)) throw new RuntimeException('Cannot publish immutable release; target may already exist.');
            return ['manifest' => $verified, 'sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)];
        } finally {
            if ($zip->status === ZipArchive::ER_OK) { try { $zip->close(); } catch (\ValueError) {} }
            if (is_file($temporary)) unlink($temporary);
        }
    }

    /** Trust keys come from the installation, never from the uploaded package. */
    public static function verify(string $path, array $trustedKeys): array
    {
        if (is_link($path) || !is_file($path) || filesize($path) > self::MAX_ARCHIVE) throw new RuntimeException('Invalid archive or archive size limit exceeded.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Invalid ZIP archive.');
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_FILES + 2) throw new RuntimeException('Invalid archive file count.');
            $entries = []; $seen = []; $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (!$entry) throw new RuntimeException('Cannot inspect ZIP entry.');
                $name = $entry['name'];
                Manifest::path($name); Manifest::uniquePath($name, $seen);
                $isMetadata = in_array($name, ['sense-package.json', 'signature.ed25519'], true);
                if (!$isMetadata && !str_starts_with($name, 'payload/')) throw new RuntimeException('Unexpected archive entry.');
                if (($entry['encryption_method'] ?? 0) !== 0) throw new RuntimeException('Encrypted package entries are forbidden.');
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) throw new RuntimeException('Cannot inspect ZIP file attributes.');
                if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) !== 0100000) throw new RuntimeException('Only regular ZIP files are supported.');
                $limit = $name === 'sense-package.json' ? self::MAX_MANIFEST : ($name === 'signature.ed25519' ? 88 : self::MAX_FILE);
                $size += $entry['size'];
                if ($entry['size'] > $limit || $size > self::MAX_EXPANDED) throw new RuntimeException('Expanded package size limit exceeded.');
                $entries[$name] = $index;
            }
            if (!isset($entries['sense-package.json'], $entries['signature.ed25519'])) throw new RuntimeException('Signed metadata is missing.');
            $raw = $zip->getFromIndex($entries['sense-package.json']);
            if (!is_string($raw)) throw new RuntimeException('Cannot read manifest.');
            $manifest = Manifest::validate(self::decode($raw), true);
            $key = $trustedKeys[$manifest['publisher']['key_id']] ?? null;
            $encoded = $zip->getFromIndex($entries['signature.ed25519']);
            $signature = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($signature)
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($signature, $raw, $key)) throw new RuntimeException('Package signature is not trusted.');
            if (count($manifest['files']) !== count($entries) - 2) throw new RuntimeException('Package inventory does not match ZIP entries.');
            foreach ($manifest['files'] as $file => $hash) {
                if (!isset($entries['payload/' . $file])) throw new RuntimeException('Listed package file is missing.');
                $contents = $zip->getFromIndex($entries['payload/' . $file]);
                if (!is_string($contents) || !hash_equals($hash, hash('sha256', $contents))) throw new RuntimeException('Package checksum mismatch.');
            }
            return $manifest;
        } finally { $zip->close(); }
    }

    private static function decode(string $raw): array
    {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Manifest must be an object.');
        return $data;
    }

    private static function add(ZipArchive $zip, string $path, string $contents): void
    {
        if (!$zip->addFromString($path, $contents) || !$zip->setMtimeName($path, 315532800)
            || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) throw new RuntimeException('Cannot write ZIP entry.');
    }
}
