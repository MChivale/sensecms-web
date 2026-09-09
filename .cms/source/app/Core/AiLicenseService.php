<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;

/** Keeps the AI add-on independently licensed without adding its key to the database or webroot. */
final class AiLicenseService
{
    public function __construct(private readonly string $endpoint, private readonly string $path, private readonly int $gracePeriod = 604800) {}

    public function validate(string $key, string $domain): void
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/D', $key)) throw new LicenseException('The AI Chat license key must contain exactly 32 letters or digits.');
        if (!filter_var($domain, FILTER_VALIDATE_URL)) throw new LicenseException('CMS_BASE_URL must be a complete HTTPS URL.');
        $status = $this->readStatus();
        if (($status['domain'] ?? '') === $domain && ((int) ($status['checked_at'] ?? 0) + $this->gracePeriod > time())) return;
        $payload = http_build_query(['type' => 'software', 'LicenseKey' => $key, 'DomainUrl' => $domain], '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nUser-Agent: SenseCMSAI-License/1.0\r\n", 'content' => $payload, 'timeout' => 8, 'ignore_errors' => true]]);
        $response = @file_get_contents($this->endpoint, false, $context);
        try { $json = is_string($response) ? json_decode($response, true, 16, JSON_THROW_ON_ERROR) : null; }
        catch (JsonException) { $json = null; }
        if (!is_array($json) || !empty($json['error']) || empty($json['data'])) throw new LicenseException((string) ($json['message'] ?? 'The AI Chat license could not be validated.'));
        if (!is_dir(dirname($this->path)) && !mkdir(dirname($this->path), 0700, true) && !is_dir(dirname($this->path))) throw new LicenseException('The AI license status directory could not be created.');
        if (file_put_contents($this->path, json_encode(['checked_at' => time(), 'domain' => $domain, 'license' => $json['data']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new LicenseException('The AI license status could not be stored.');
        @chmod($this->path, 0600);
    }

    private function readStatus(): array
    {
        try { return json_decode((string) @file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return []; }
    }
}
