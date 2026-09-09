<?php

declare(strict_types=1);

namespace App\Core;

final class CacheService
{
    public function __construct(private readonly array $settings, private readonly string $path) {}

    public function status(): array
    {
        $driver = $this->settings['driver'] ?? 'file';
        $available = match ($driver) { 'none' => true, 'file' => is_writable($this->path) || (!is_dir($this->path) && is_writable(dirname($this->path))), 'memcache' => extension_loaded('memcache'), 'memcached' => extension_loaded('memcached'), 'redis' => extension_loaded('redis'), default => false };
        return ['driver' => $driver, 'available' => $available, 'file' => true, 'memcache' => extension_loaded('memcache'), 'memcached' => extension_loaded('memcached'), 'redis' => extension_loaded('redis')];
    }

    public function test(): void
    {
        $driver = $this->settings['driver'] ?? 'file';
        if ($driver === 'none') return;
        if ($driver === 'file') { if (!is_dir($this->path) && !mkdir($this->path, 0750, true) && !is_dir($this->path)) throw new \RuntimeException('The file cache directory could not be created.'); if (!is_writable($this->path)) throw new \RuntimeException('The file cache directory is not writable.'); return; }
        if ($driver === 'memcache') { if (!extension_loaded('memcache')) throw new \RuntimeException('The Memcache PHP extension is not installed.'); $client = new \Memcache(); if (!$client->connect((string) $this->settings['host'], (int) $this->settings['port'], 2)) throw new \RuntimeException('Memcache did not accept the health check.'); if (!$client->set('sensecms:health', 'ok', 0, 15) || $client->get('sensecms:health') !== 'ok') throw new \RuntimeException('Memcache did not accept the health check.'); return; }
        if ($driver === 'memcached') { if (!extension_loaded('memcached')) throw new \RuntimeException('The Memcached PHP extension is not installed.'); $client = new \Memcached(); $client->addServer((string) $this->settings['host'], (int) $this->settings['port']); if (!$client->set('sensecms:health', 'ok', 15) || $client->get('sensecms:health') !== 'ok') throw new \RuntimeException('Memcached did not accept the health check.'); return; }
        if ($driver === 'redis') { if (!extension_loaded('redis')) throw new \RuntimeException('The Redis PHP extension is not installed.'); $client = new \Redis(); $client->connect((string) $this->settings['host'], (int) $this->settings['port'], 2.0); $client->select((int) $this->settings['database']); $client->setex('sensecms:health', 15, 'ok'); if ($client->get('sensecms:health') !== 'ok') throw new \RuntimeException('Redis did not accept the health check.'); return; }
        throw new \RuntimeException('Choose a supported cache driver.');
    }

    public function clear(): void
    {
        $this->test();
        $driver = $this->settings['driver'] ?? 'file';
        if ($driver === 'none') return;
        if ($driver === 'file') { foreach (glob($this->path . '/*') ?: [] as $file) if (is_file($file)) @unlink($file); return; }
        if ($driver === 'memcache') { $client = new \Memcache(); if (!$client->connect((string) $this->settings['host'], (int) $this->settings['port'], 2) || !$client->flush()) throw new \RuntimeException('Memcache could not be cleared.'); return; }
        if ($driver === 'memcached') { $client = new \Memcached(); $client->addServer((string) $this->settings['host'], (int) $this->settings['port']); $client->flush(); return; }
        $client = new \Redis(); $client->connect((string) $this->settings['host'], (int) $this->settings['port'], 2.0); $client->select((int) $this->settings['database']); $client->flushDB();
    }
}
