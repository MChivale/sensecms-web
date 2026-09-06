<?php

declare(strict_types=1);

namespace App\Core\Packages;

use App\Core\Runtime;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/** Theme-only filesystem lifecycle. Does not run migrations or manage modules. */
final class ThemeManager
{
    public function __construct(private readonly Runtime $runtime) {}

    public function install(string $archive, array $keys): array
    {
        return $this->locked(function () use ($archive, $keys): array {
            if (is_link($archive) || !is_file($archive) || filesize($archive) > 26214400) throw new RuntimeException('Invalid theme archive.');
            $base = $this->runtime->root . '/storage/themes';
            if (is_link($base)) throw new RuntimeException('Invalid theme directory.');
            if (!is_dir($base) && !mkdir($base, 0700)) throw new RuntimeException('Cannot create theme directory.');
            $stage = $base . '/stage-' . bin2hex(random_bytes(12));
            if (!mkdir($stage, 0700)) throw new RuntimeException('Cannot stage theme.');
            $source = $stage . '/archive.zip';
            try {
                if (!copy($archive, $source)) throw new RuntimeException('Cannot stage theme archive.');
                $manifest = Archive::verify($source, $keys);
                if ($manifest['type'] !== 'theme') throw new RuntimeException('This lifecycle supports themes only.');
                $product = require $this->runtime->root . '/config/product.php';
                Manifest::compatible($manifest, $product['core_version'], PHP_VERSION);
                foreach (['pages.php', 'layout.php'] as $required) if (!isset($manifest['files'][$required])) throw new RuntimeException('Theme contract requires pages.php and layout.php.');
                $state = $this->runtime->read('theme');
                if (($state['active']['slug'] ?? '') === $manifest['slug'] && version_compare($manifest['version'], $state['active']['version'], '<=')) throw new RuntimeException('Use a newer version or explicit rollback.');
                $zip = new ZipArchive();
                if ($zip->open($source, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open staged archive.');
                $payload = $stage . '/payload'; mkdir($payload, 0700);
                try {
                    foreach ($manifest['files'] as $path => $hash) {
                        $contents = $zip->getFromName('payload/' . $path);
                        if (!is_string($contents) || !hash_equals($hash, hash('sha256', $contents))) throw new RuntimeException('Staged theme checksum mismatch.');
                        $file = $payload . '/' . $path;
                        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) throw new RuntimeException('Cannot create payload directory.');
                        if (file_put_contents($file, $contents, LOCK_EX) !== strlen($contents) || !chmod($file, 0600)) throw new RuntimeException('Cannot write theme payload.');
                    }
                } finally { $zip->close(); }
                $hash = hash_file('sha256', $source);
                $directory = $manifest['slug'] . '-' . $manifest['version'] . '-' . substr($hash, 0, 16);
                $target = $base . '/' . $directory;
                if (file_exists($target) || is_link($target)) throw new RuntimeException('Theme release already staged; inspect it before retrying.');
                if (!rename($stage, $target)) throw new RuntimeException('Cannot publish theme release.');
                $active = ['directory' => $directory, 'slug' => $manifest['slug'], 'version' => $manifest['version'], 'sha256' => $hash];
                $this->runtime->write('theme', ['active' => $active, 'previous' => $state['active'] ?? null]);
                return $active;
            } finally {
                // Only our unpublished staging directory is disposable; published versions remain recoverable.
                if (is_dir($stage) && !is_link($stage)) {
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                    rmdir($stage);
                }
            }
        });
    }

    public function rollback(array $keys): array
    {
        return $this->locked(function () use ($keys): array {
            $state = $this->runtime->read('theme'); $previous = $state['previous'] ?? null;
            if (!is_array($previous)) throw new RuntimeException('There is no previous theme release.');
            $root = $this->path($previous); $archive = dirname($root) . '/archive.zip';
            if (!hash_equals($previous['sha256'], hash_file('sha256', $archive))) throw new RuntimeException('Previous archive changed.');
            $manifest = Archive::verify($archive, $keys);
            $product = require $this->runtime->root . '/config/product.php';
            Manifest::compatible($manifest, $product['core_version'], PHP_VERSION);
            foreach ($manifest['files'] as $file => $hash) if (is_link($root . '/' . $file) || !is_file($root . '/' . $file) || !hash_equals($hash, hash_file('sha256', $root . '/' . $file))) throw new RuntimeException('Previous theme payload changed.');
            $this->runtime->write('theme', ['active' => $previous, 'previous' => $state['active']]);
            return $previous;
        });
    }

    public function activePath(): ?string
    {
        $active = $this->runtime->read('theme')['active'] ?? null;
        return is_array($active) ? $this->path($active) : null;
    }

    private function path(array $release): string
    {
        if (!is_string($release['directory'] ?? null) || !preg_match('/^[a-z0-9-]+-\d+\.\d+\.\d+-[a-f0-9]{16}$/D', $release['directory'])) throw new RuntimeException('Invalid installed theme reference.');
        $base = $this->runtime->root . '/storage/themes'; $path = $base . '/' . $release['directory'] . '/payload';
        if (is_link($base) || is_link(dirname($path)) || is_link($path) || !is_dir($path)) throw new RuntimeException('Installed theme is unavailable.');
        return $path;
    }

    private function locked(callable $operation): array
    {
        $lockPath = $this->runtime->root . '/storage/themes.lock';
        if (is_link($lockPath)) throw new RuntimeException('Invalid theme lock.');
        $mask = umask(0077); $lock = null;
        try {
            if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0700, true)) throw new RuntimeException('Cannot create private storage.');
            $lock = fopen($lockPath, 'c+b');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another theme operation is in progress.');
            return $operation();
        } finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } umask($mask); }
    }
}
