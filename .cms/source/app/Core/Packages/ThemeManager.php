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

    public function install(string $archive, array $keys, bool $activate = true): array
    {
        return $this->locked(function () use ($archive, $keys, $activate): array {
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
                if ($activate && is_file($this->runtime->root . '/themes/' . $manifest['slug'] . '/theme.json')) throw new RuntimeException('A legacy theme has the same identity. Reconcile its installation before activating this release.');
                $product = require $this->runtime->root . '/config/product.php';
                Manifest::compatible($manifest, $product['core_version'], PHP_VERSION);
                foreach (['pages.php', 'layout.php'] as $required) if (!isset($manifest['files'][$required])) throw new RuntimeException('Theme contract requires pages.php and layout.php.');
                if (($this->runtime->read('workspace')['enabled'] ?? false) === true && !isset($manifest['files']['theme.json'])) throw new RuntimeException('Workspace requires a theme with a public page contract.');
                $state = $this->runtime->read('theme');
                if (count($this->releases()) >= 80) throw new RuntimeException('Theme release retention limit reached; archive reviewed inactive releases before installing more.');
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
                if (isset($manifest['files']['theme.json'])) {
                    $theme = json_decode((string) file_get_contents($payload . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($theme) || ($theme['slug'] ?? '') !== $manifest['slug'] || ($theme['version'] ?? '') !== $manifest['version']) throw new RuntimeException('Public and Workspace theme identities must match.');
                    $theme['_path'] = $payload;
                    \App\Core\ThemeContract::validateAll([$manifest['slug'] => $theme]);
                }
                $hash = hash_file('sha256', $source);
                $directory = $manifest['slug'] . '-' . $manifest['version'] . '-' . substr($hash, 0, 16);
                $target = $base . '/' . $directory;
                if (file_exists($target) || is_link($target)) throw new RuntimeException('Theme release already staged; inspect it before retrying.');
                if (!rename($stage, $target)) throw new RuntimeException('Cannot publish theme release.');
                $active = ['directory' => $directory, 'slug' => $manifest['slug'], 'version' => $manifest['version'], 'sha256' => $hash];
                $state['releases'] = $this->releases();
                $state['releases'][$directory] = $active;
                if ($activate) { $state['previous'] = $state['active'] ?? null; $state['active'] = $active; }
                $this->runtime->write('theme', $state);
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
            $this->verifyRelease($previous, $keys);
            $state['releases'] = $this->releases();
            $state['previous'] = $state['active']; $state['active'] = $previous;
            $this->runtime->write('theme', $state);
            return $previous;
        });
    }

    public function activePath(): ?string
    {
        $active = $this->runtime->read('theme')['active'] ?? null;
        return is_array($active) ? $this->path($active) : null;
    }

    public function active(): ?array
    {
        $active = $this->runtime->read('theme')['active'] ?? null;
        if (!is_array($active)) return null;
        $this->path($active);
        return $active;
    }

    public function releases(): array
    {
        $state = $this->runtime->read('theme'); $releases = (array) ($state['releases'] ?? []);
        foreach (['previous', 'active'] as $key) if (is_array($state[$key] ?? null)) {
            $this->path($state[$key]);
            $releases[$state[$key]['directory']] = $state[$key];
        }
        foreach ($releases as $directory => $release) {
            if (!is_array($release) || ($release['directory'] ?? '') !== $directory) throw new RuntimeException('Invalid installed theme inventory.');
            $this->path($release);
        }
        return $releases;
    }

    public function activate(string $directory, array $keys): array
    {
        return $this->locked(function () use ($directory, $keys): array {
            $release = $this->releases()[$directory] ?? null;
            if ($release === null) throw new RuntimeException('Choose an installed theme release.');
            $this->verifyRelease($release, $keys);
            $state = $this->runtime->read('theme');
            if (($state['active']['directory'] ?? '') === $directory) return $release;
            $state['releases'] = $this->releases();
            $state['previous'] = $state['active'] ?? null; $state['active'] = $release;
            $this->runtime->write('theme', $state);
            return $release;
        });
    }

    private function verifyRelease(array $release, array $keys): void
    {
        if (is_file($this->runtime->root . '/themes/' . $release['slug'] . '/theme.json')) throw new RuntimeException('A legacy theme has the same identity. Reconcile its installation before activating this release.');
        $root = $this->path($release); $archive = dirname($root) . '/archive.zip';
        if (is_link($archive) || !is_file($archive) || !hash_equals($release['sha256'], hash_file('sha256', $archive))) throw new RuntimeException('Installed theme archive changed.');
        $manifest = Archive::verify($archive, $keys);
        if ($manifest['type'] !== 'theme' || $manifest['slug'] !== $release['slug'] || $manifest['version'] !== $release['version']) throw new RuntimeException('Installed theme identity changed.');
        $product = require $this->runtime->root . '/config/product.php';
        Manifest::compatible($manifest, $product['core_version'], PHP_VERSION);
        foreach ($manifest['files'] as $file => $hash) {
            $resolved = realpath($root . '/' . $file);
            if (!$resolved || !str_starts_with($resolved, realpath($root) . DIRECTORY_SEPARATOR) || is_link($root . '/' . $file) || !is_file($resolved) || !hash_equals($hash, hash_file('sha256', $resolved))) throw new RuntimeException('Installed theme payload changed.');
        }
        if (($this->runtime->read('workspace')['enabled'] ?? false) === true && !isset($manifest['files']['theme.json'])) throw new RuntimeException('Workspace requires a theme with a public page contract.');
        if (isset($manifest['files']['theme.json'])) {
            $theme = json_decode((string) file_get_contents($root . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($theme) || ($theme['slug'] ?? '') !== $manifest['slug'] || ($theme['version'] ?? '') !== $manifest['version']) throw new RuntimeException('Workspace theme identity changed.');
            $theme['_path'] = $root;
            \App\Core\ThemeContract::validateAll([$manifest['slug'] => $theme]);
        }
    }

    private function path(array $release): string
    {
        if (!is_string($release['directory'] ?? null) || !preg_match('/^[a-z0-9-]+-\d+\.\d+\.\d+-[a-f0-9]{16}$/D', $release['directory'])) throw new RuntimeException('Invalid installed theme reference.');
        if (!is_string($release['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $release['sha256']) || $release['directory'] !== ($release['slug'] ?? '') . '-' . ($release['version'] ?? '') . '-' . substr($release['sha256'], 0, 16)) throw new RuntimeException('Invalid installed theme identity.');
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
