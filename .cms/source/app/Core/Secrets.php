<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Secrets
{
    private string $key;

    public function __construct(string $masterKey)
    {
        if ($masterKey === '') throw new RuntimeException('CMS_SECRET_KEY is required.');
        $this->key = hash('sha256', $masterKey, true);
    }

    public function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key));
    }

    public function decrypt(string $value): string
    {
        $payload = base64_decode($value, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Encrypted value is invalid.');
        $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if (!is_string($plain)) throw new RuntimeException('Encrypted value cannot be decrypted.');
        return $plain;
    }
}
