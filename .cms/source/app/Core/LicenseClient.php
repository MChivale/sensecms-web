<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Chivale's existing software-license protocol, using the Sense CMS identity. */
final class LicenseClient
{
    public function __construct(private readonly array $config) {}

    public function validate(#[\SensitiveParameter] string $key, string $domain): array
    {
        self::assertKey($key);
        $domain = self::domain($domain);
        if (($this->config['endpoint'] ?? null) !== 'https://www.chivale.com/license/') throw new LicenseException('Unexpected licensing endpoint.');
        $payload = http_build_query(['type' => 'software', 'LicenseKey' => $key, 'DomainUrl' => $domain,
            'ProductName' => $this->config['product_name'], 'ProductModel' => $this->config['product_model'],
            'ProductVersion' => $this->config['product_version']], '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($this->config['endpoint']);
        if ($curl === false) throw new LicenseException('Cannot initialize the license connection.');
        $body = ''; $overflow = false;
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_USERAGENT => 'SenseCMS-License/' . $this->config['product_version'],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > 65536) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // No transport/body details are exposed: a server could echo credentials.
        if ($ok === false || $overflow) throw new LicenseException('The license service is unavailable or returned an oversized response.');
        return $this->response($status, $body);
    }

    public function response(int $status, string $body): array
    {
        if ($status < 200 || $status >= 300 || strlen($body) > 65536) throw new LicenseException('The license service did not accept this request.');
        try { $reply = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new LicenseException('The license service returned an invalid response.'); }
        $data = $reply['data'] ?? null;
        if (!is_array($reply) || ($reply['error'] ?? null) !== false || !is_array($data)) throw new LicenseException('The license is invalid, expired, revoked or not assigned to this installation.');
        $product = $data['product'] ?? null;
        if (!is_array($product) || ($product['name'] ?? null) !== $this->config['product_name']
            || ($product['model'] ?? null) !== $this->config['product_model']
            || ($product['version'] ?? null) !== $this->config['product_version']) throw new LicenseException('The license product does not match Sense CMS.');
        if (!array_key_exists('valid_from', $data) || !array_key_exists('valid_to', $data)) throw new LicenseException('License validity dates are missing.');
        $from = self::date($data['valid_from']); $until = self::date($data['valid_to']);
        if ($from !== null && $until !== null && $until <= $from) throw new LicenseException('Invalid license validity period.');
        self::assertPeriod($from, $until, time());
        // Persist only what Core needs, never the provider's client data or URLs.
        return ['product' => ['name' => $product['name'], 'model' => $product['model'], 'version' => $product['version']], 'valid_from' => $from, 'valid_until' => $until];
    }

    public function assertCached(array $data, int $now): void
    {
        $product = $data['product'] ?? null;
        if (!is_array($product) || ($product['name'] ?? null) !== $this->config['product_name']
            || ($product['model'] ?? null) !== $this->config['product_model']
            || ($product['version'] ?? null) !== $this->config['product_version']) throw new LicenseException('Cached license product does not match Sense CMS.');
        foreach (['valid_from', 'valid_until'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] !== null && !is_int($data[$field])) throw new LicenseException('Invalid cached license date.');
        }
        self::assertPeriod($data['valid_from'], $data['valid_until'], $now);
    }

    public static function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/D', $key)) throw new LicenseException('The license key must contain 32 letters or digits.');
    }

    public static function domain(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !filter_var($url, FILTER_VALIDATE_URL) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port']) && $parts['port'] !== 443
            || !in_array($parts['path'] ?? '', ['', '/'], true) || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $parts['host'] ?? '')) throw new LicenseException('Use the canonical HTTPS domain URL without credentials, path or query.');
        return 'https://' . strtolower($parts['host']);
    }

    public static function assertPeriod(?int $from, ?int $until, int $now): void
    {
        if ($from !== null && $from > $now || $until !== null && $until <= $now) throw new LicenseException('The license is not currently valid.');
    }

    private static function date(mixed $value): ?int
    {
        if ($value === null) return null;
        if (!is_string($value)) throw new LicenseException('Invalid license date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) throw new LicenseException('Invalid license date.');
        return $date->getTimestamp();
    }
}
