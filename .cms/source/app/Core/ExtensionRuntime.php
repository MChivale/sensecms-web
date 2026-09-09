<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class ExtensionRuntime
{
    public function __construct(private readonly PDO $db, private readonly string $root) {}

    public function dispatch(string $method, string $path, ExtensionContext $context): bool
    {
        foreach ($this->activePackages() as $package) {
            $directory = $this->packageDirectory((string) $package['type'], (string) $package['slug'], (string) ($package['install_path'] ?? ''));
            if ($directory === null) continue;
            $manifestName = $package['type'] === 'addon' ? 'addon.json' : 'plugin.json';
            $manifestFile = $directory . DIRECTORY_SEPARATOR . $manifestName;
            if (!is_file($manifestFile)) continue;
            $manifest = json_decode((string) file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
            $runtime = (string) ($manifest['runtime'] ?? '');
            if ($runtime === '') continue;
            $runtimeFile = $this->payloadFile($directory, $runtime);
            $handler = require $runtimeFile;
            if (!is_callable($handler)) throw new RuntimeException('Extension runtime must return a callable handler.');
            if ($handler(strtoupper($method), $path, $context) === true) return true;
        }
        return false;
    }

    private function activePackages(): array
    {
        $statement = $this->db->query("SELECT type,slug,install_path FROM extension_packages WHERE active=1 AND type IN ('addon','plugin') AND install_path IS NOT NULL ORDER BY type,slug");
        return $statement->fetchAll();
    }

    private function packageDirectory(string $type, string $slug, string $installPath): ?string
    {
        if (!in_array($type, ['addon', 'plugin'], true) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) return null;
        $expected = ($type === 'addon' ? 'addons/' : 'plugins/') . $slug;
        if (str_replace('\\', '/', trim($installPath, '/\\')) !== $expected) return null;
        $base = realpath($this->root . DIRECTORY_SEPARATOR . ($type === 'addon' ? 'addons' : 'plugins'));
        $directory = realpath($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $expected));
        if ($base === false || $directory === false || !str_starts_with($directory . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) return null;
        return $directory;
    }

    private function payloadFile(string $directory, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]{0,240}$#D', $relative) || str_contains($relative, '..') || str_starts_with($relative, '/')) throw new RuntimeException('Extension runtime path is invalid.');
        $file = realpath($directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($file === false || !is_file($file) || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)) throw new RuntimeException('Extension runtime file is unavailable.');
        return $file;
    }
}
