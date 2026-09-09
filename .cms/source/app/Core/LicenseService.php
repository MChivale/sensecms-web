<?php

declare(strict_types=1);

namespace App\Core;

/** Installation-specific authenticated encryption; no plaintext license files. */
final class LicenseService
{
    private const HEADER = "SENSECMS-LIC-1\n";
    private const TTL = 604800;
    private ?array $status = null;

    public function __construct(private readonly string $directory, private readonly LicenseClient $client) {}

    public function install(#[\SensitiveParameter] string $key, string $domain): array
    {
        $domain = LicenseClient::domain($domain);
        $data = $this->client->validate($key, $domain);
        $this->directory();
        $lock = $this->lock();
        try {
            $secretPath = $this->directory . '/key.bin';
            if (!is_file($secretPath)) $this->write('key.bin', random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $secret = $this->secret();
            $this->save($key, $domain, $data, $secret);
        } finally { if (isset($secret)) sodium_memzero($secret); flock($lock, LOCK_UN); fclose($lock); }
        return $data;
    }

    public function enforce(string $domain): array
    {
        $record=$this->validated($domain);
        try { return $this->status=$record['data']; }
        finally { sodium_memzero($record['key']); }
    }

    /** Export only to the fixed official broker, never a configurable third party. */
    public function telegramHeaders(string $domain, string $broker): array
    {
        if ($broker!=='https://www.sensecms.com/api/telegram/v1') throw new LicenseException('Unexpected Telegram service.');
        $record=$this->validated($domain);
        try { return ['Authorization: Bearer '.$record['key'],'X-SenseCMS-Domain: '.$record['domain']]; }
        finally { sodium_memzero($record['key']); }
    }

    private function validated(string $domain): array
    {
        $domain = LicenseClient::domain($domain);
        $this->directory();
        $lock = $this->lock();
        try {
            $secret = $this->secret();
            $raw = $this->read('license.lic', 16384);
            if (!str_starts_with($raw, self::HEADER)) throw new LicenseException('Unsupported Sense CMS license file.');
            $box = base64_decode(substr($raw, strlen(self::HEADER)), true);
            if (!is_string($box) || strlen($box) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new LicenseException('Invalid encrypted license.');
            $plain = sodium_crypto_secretbox_open(substr($box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $secret);
            if (!is_string($plain)) throw new LicenseException('License integrity check failed.');
            try { $record = json_decode($plain, true, 12, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new LicenseException('Invalid encrypted license contents.'); }
            finally { sodium_memzero($plain); }
            if (!is_array($record) || !is_string($record['key'] ?? null) || ($record['domain'] ?? null) !== $domain
                || !is_int($record['checked_at'] ?? null) || !is_array($record['data'] ?? null)) throw new LicenseException('License is not bound to this installation.');
            LicenseClient::assertKey($record['key']);
            $now = time();
            // A backward clock jump fails closed rather than extending cached validity.
            if ($record['checked_at'] > $now) throw new LicenseException('System clock precedes the license validation time.');
            $data = $record['data'];
            $this->client->assertCached($data, $record['checked_at']);
            if ($record['checked_at'] + self::TTL <= $now || $data['valid_until'] !== null && $data['valid_until'] <= $now) {
                $data = $this->client->validate($record['key'], $domain);
                $this->save($record['key'], $domain, $data, $secret);
            }
            $record['data']=$data;
            return $record;
        } finally {
            if (isset($secret)) sodium_memzero($secret);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function expiringNotice(): ?string
    {
        $until = $this->status['valid_until'] ?? null;
        return is_int($until) && $until < time() + 30 * 86400 ? 'License expires on ' . gmdate('Y-m-d', $until) . '.' : null;
    }

    private function save(#[\SensitiveParameter] string $key, string $domain, array $data, #[\SensitiveParameter] string $secret): void
    {
        $plain = json_encode(['key' => $key, 'domain' => $domain, 'data' => $data, 'checked_at' => time()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try { $this->write('license.lic', self::HEADER . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $secret))); }
        finally { sodium_memzero($plain); }
    }

    private function secret(): string
    {
        $secret = $this->read('key.bin', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (strlen($secret) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new LicenseException('Invalid installation encryption key.');
        return $secret;
    }

    private function read(string $file, int $limit): string
    {
        $path = $this->directory . '/' . $file;
        if (is_link($path) || !is_file($path) || filesize($path) > $limit) throw new LicenseException('Private license file is missing or invalid.');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077)) throw new LicenseException('License files must not be accessible to other users.');
        $data = file_get_contents($path);
        if (!is_string($data)) throw new LicenseException('Cannot read private license file.');
        return $data;
    }

    private function directory(): void
    {
        if (is_link($this->directory) || !is_dir($this->directory) && !mkdir($this->directory, 0700, true)) throw new LicenseException('Cannot prepare private license storage.');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->directory) & 0077)) throw new LicenseException('License directory must be private.');
    }

    private function lock()
    {
        $path = $this->directory . '/license.lock';
        if (is_link($path)) throw new LicenseException('Invalid license lock.');
        $mask = umask(0077);
        try { $lock = fopen($path, 'c+b'); } finally { umask($mask); }
        if (!$lock || !flock($lock, LOCK_EX)) throw new LicenseException('Cannot lock license storage.');
        return $lock;
    }

    private function write(string $file, #[\SensitiveParameter] string $contents): void
    {
        $target = $this->directory . '/' . $file;
        if (is_link($target)) throw new LicenseException('Invalid private license path.');
        $temporary = $target . '.' . bin2hex(random_bytes(8));
        $mask = umask(0077);
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !chmod($temporary, 0600)) throw new LicenseException('Cannot write private license data.');
            if (!rename($temporary, $target)) throw new LicenseException('Cannot activate private license data.');
        } finally { umask($mask); if (is_file($temporary)) unlink($temporary); }
    }
}
