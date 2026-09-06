<?php

declare(strict_types=1);

namespace App\Core\Packages;

use RuntimeException;

final class Manifest
{
    public const TYPES = ['module', 'plugin', 'addon', 'theme'];
    public const VERSION = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D';

    public static function validate(array $data, bool $archive = false): array
    {
        $allowed = ['schema', 'type', 'slug', 'name', 'version', 'requires', 'publisher', 'dependencies', 'files'];
        if (array_diff(array_keys($data), $allowed)) throw new RuntimeException('Unknown package manifest field.');
        if (($data['schema'] ?? null) !== 1) throw new RuntimeException('Unsupported package schema.');
        if (!in_array($data['type'] ?? null, self::TYPES, true)) throw new RuntimeException('Unsupported package type.');
        if (!is_string($data['slug'] ?? null) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $data['slug']) || strlen($data['slug']) > 80) throw new RuntimeException('Invalid package slug.');
        if (!is_string($data['name'] ?? null) || trim($data['name']) === '' || strlen($data['name']) > 160 || preg_match('/[\x00-\x1f\x7f]/', $data['name'])) throw new RuntimeException('Invalid package name.');
        self::version($data['version'] ?? null);
        $requires = $data['requires'] ?? null;
        if (!is_array($requires) || array_diff(array_keys($requires), ['php', 'core']) || !isset($requires['php'], $requires['core'])) throw new RuntimeException('PHP and Core compatibility are required.');
        foreach ($requires as $range) self::range($range);
        if (version_compare($requires['php']['min'], '8.5.0', '<')) throw new RuntimeException('Packages must require PHP 8.5 or newer.');
        $publisher = $data['publisher'] ?? null;
        if (!is_array($publisher) || array_diff(array_keys($publisher), ['key_id', 'name']) || !is_string($publisher['key_id'] ?? null)
            || !preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/D', $publisher['key_id']) || !is_string($publisher['name'] ?? null)
            || trim($publisher['name']) === '' || strlen($publisher['name']) > 160 || preg_match('/[\x00-\x1f\x7f]/', $publisher['name'])) throw new RuntimeException('Invalid publisher identity.');
        $dependencies = $data['dependencies'] ?? [];
        if (!is_array($dependencies) || count($dependencies) > 50) throw new RuntimeException('Invalid dependencies.');
        foreach ($dependencies as $id => $range) {
            if (!is_string($id) || !preg_match('/^(module|plugin|addon|theme):[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) || strlen($id) > 88 || $id === self::identity($data)) throw new RuntimeException('Invalid package dependency.');
            self::range($range);
        }
        if ($archive) {
            if (!is_array($data['files'] ?? null) || !$data['files'] || count($data['files']) > Archive::MAX_FILES) throw new RuntimeException('Invalid package file inventory.');
            $seen = [];
            foreach ($data['files'] as $path => $hash) {
                self::path($path);
                self::uniquePath($path, $seen);
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('Invalid file checksum.');
            }
        } elseif (array_key_exists('files', $data)) {
            throw new RuntimeException('Source manifest must not contain a generated file inventory.');
        }
        return $data;
    }

    public static function compatible(array $data, string $core, string $php, array $installed = []): void
    {
        self::validate($data, true);
        foreach (['core' => $core, 'php' => $php] as $kind => $version) {
            if (!self::matches($version, $data['requires'][$kind])) throw new RuntimeException('Incompatible ' . $kind . ' version.');
        }
        foreach ($data['dependencies'] ?? [] as $id => $range) {
            if (!isset($installed[$id]) || !is_string($installed[$id]) || !self::matches($installed[$id], $range)) throw new RuntimeException('Missing or incompatible dependency: ' . $id);
        }
    }

    public static function identity(array $data): string { return $data['type'] . ':' . $data['slug']; }

    public static function path(mixed $path): void
    {
        if (!is_string($path) || strlen($path) > 220 || !preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._/-]*$#D', $path)) throw new RuntimeException('Unsafe package path.');
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part[0] === '.' || str_ends_with($part, '.') || preg_match('/^(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part)) throw new RuntimeException('Unsafe package path component.');
        }
        if (preg_match('#(^|/)(?:storage|vendor|node_modules|web\.config)(/|$)#i', $path)
            || preg_match('/\.(?:env|ini|log|bak|key|pem|p12|pfx|phar|exe|dll|so|dylib|sh|bat|cmd|ps1)$/i', $path)) throw new RuntimeException('Protected or executable system file in package.');
    }

    public static function uniquePath(string $path, array &$seen): void
    {
        $key = strtolower($path);
        if (isset($seen[$key])) throw new RuntimeException('Duplicate or case-colliding package path.');
        $parts = explode('/', $key);
        array_pop($parts);
        while ($parts) {
            if (isset($seen[implode('/', $parts)]) && $seen[implode('/', $parts)] === 'file') throw new RuntimeException('File-directory path collision.');
            $seen[implode('/', $parts)] = 'directory';
            array_pop($parts);
        }
        $seen[$key] = 'file';
    }

    private static function version(mixed $version): void
    {
        if (!is_string($version) || strlen($version) > 32 || !preg_match(self::VERSION, $version)) throw new RuntimeException('A three-part numeric version is required.');
    }

    private static function range(mixed $range): void
    {
        if (!is_array($range) || count($range) !== 2 || !isset($range['min'], $range['max_exclusive'])) throw new RuntimeException('Version range requires min and max_exclusive.');
        self::version($range['min']); self::version($range['max_exclusive']);
        if (version_compare($range['min'], $range['max_exclusive'], '>=')) throw new RuntimeException('Empty version range.');
    }

    private static function matches(string $version, array $range): bool
    {
        self::version($version);
        return version_compare($version, $range['min'], '>=') && version_compare($version, $range['max_exclusive'], '<');
    }
}
