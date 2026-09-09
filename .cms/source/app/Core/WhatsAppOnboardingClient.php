<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class WhatsAppOnboardingClient
{
    private readonly string $base;

    public function __construct(private readonly LicenseService $license, private readonly array $config, private readonly string $installationUrl)
    {
        $this->base = rtrim((string) ($config['base_url'] ?? ''), '/'); $parts = parse_url($this->base);
        if (!filter_var($this->base, FILTER_VALIDATE_URL) || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))) throw new RuntimeException('The central WhatsApp connection service is not configured securely.');
    }

    public function start(): string
    {
        $data = $this->request('/start', []); $url = (string) ($data['url'] ?? ''); $expected = parse_url($this->base); $actual = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($actual) || strtolower((string) ($actual['scheme'] ?? '')) !== 'https' || strcasecmp((string) ($actual['host'] ?? ''), (string) ($expected['host'] ?? '')) !== 0 || (int) ($actual['port'] ?? 443) !== (int) ($expected['port'] ?? 443) || (string) ($actual['path'] ?? '') !== '/system/notifications/whatsapp' || isset($actual['user']) || isset($actual['pass']) || isset($actual['fragment'])) throw new RuntimeException('The central WhatsApp service returned an invalid authorization address.');
        return $url;
    }

    public function claim(string $claim): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $claim)) throw new RuntimeException('The WhatsApp connection result is invalid.');
        $data = $this->request('/claim', ['claim' => $claim]);
        if (!preg_match('/^[0-9]{5,30}$/D', (string) ($data['phone_number_id'] ?? '')) || !preg_match('/^[0-9]{5,30}$/D', (string) ($data['waba_id'] ?? '')) || (string) ($data['api_version'] ?? '') !== 'v26.0' || strlen((string) ($data['access_token'] ?? '')) < 20 || strlen((string) ($data['access_token'] ?? '')) > 4096 || preg_match('/[\r\n]/', (string) $data['access_token'])) throw new RuntimeException('The central WhatsApp service returned incomplete credentials.');
        return $data;
    }

    private function request(string $path, array $payload): array
    {
        try { $headers = $this->license->marketplaceHeaders($this->installationUrl); }
        catch (LicenseException) { throw new RuntimeException('A valid SenseCMS license is required to connect WhatsApp.', 403); }
        $headers[] = 'Content-Type: application/json'; $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); $response = ''; $curl = curl_init($this->base . $path);
        if ($curl === false) throw new RuntimeException('The WhatsApp connection service could not be initialized.');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'SenseCMSCMS-WhatsApp-Onboarding/1.0', CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int { if (strlen($response) + strlen($chunk) > 131072) return 0; $response .= $chunk; return strlen($chunk); }]);
        $ok = curl_exec($curl); $error = curl_error($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $data = json_decode($response, true);
        if ($ok === false || $error !== '' || $status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException(is_array($data) && is_string($data['message'] ?? null) ? mb_substr($data['message'], 0, 240) : 'The central WhatsApp connection service is temporarily unavailable.', in_array($status, [403, 410, 422, 429, 503], true) ? $status : 502);
        return $data;
    }
}
