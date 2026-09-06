<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Runtime
{
    public function __construct(public readonly string $root) {}

    public function read(string $name): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name)) throw new RuntimeException('Invalid configuration name.');
        $path = $this->root . '/storage/' . $name . '.json';
        if (!is_file($path)) return [];
        if (is_link($path) || filesize($path) > 65536) throw new RuntimeException('Invalid private configuration.');
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid private configuration.');
        return $data;
    }

    public function write(string $name, #[\SensitiveParameter] array $data): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $name)) throw new RuntimeException('Invalid configuration name.');
        $path = $this->root . '/storage/' . $name . '.json';
        if (is_link($path)) throw new RuntimeException('Invalid private configuration path.');
        $temp = $path . '.' . bin2hex(random_bytes(8));
        $mask = umask(0077);
        try {
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('Cannot create private storage.');
            $raw = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temp, $raw, LOCK_EX) !== strlen($raw) || !chmod($temp, 0600) || !rename($temp, $path)) throw new RuntimeException('Cannot save private configuration.');
        } finally { umask($mask); if (is_file($temp)) unlink($temp); }
    }

    public function baseUrl(): string
    {
        $config = $this->read('installed') ?: $this->read('setup');
        if (empty($config['base_url'])) throw new RuntimeException('Set the canonical installation URL using scripts/prepare.php before serving this installation.');
        return LicenseClient::domain($config['base_url']);
    }

    public function license(): LicenseService
    {
        $product = require $this->root . '/config/product.php';
        return new LicenseService($this->root . '/storage/license', new LicenseClient($product['license']));
    }

    public static function databaseInput(#[\SensitiveParameter] array $input): array
    {
        $host = trim((string) ($input['db_host'] ?? '')); $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT);
        $name = trim((string) ($input['db_name'] ?? '')); $user = trim((string) ($input['db_user'] ?? '')); $password = (string) ($input['db_password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $host) || !$port || $port < 1 || $port > 65535
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) || $user === '' || strlen($user) > 128 || preg_match('/[\x00-\x1f]/', $user)
            || strlen($password) > 512 || str_contains($password, "\0")) throw new RuntimeException('Enter valid database connection details.');
        return compact('host', 'port', 'name', 'user', 'password');
    }

    public static function connect(#[\SensitiveParameter] array $config): PDO
    {
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']), $config['user'], $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    }
}
