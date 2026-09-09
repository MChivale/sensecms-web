<?php
declare(strict_types=1);

namespace SenseCMS\GoogleAnalytics;

final class Analytics
{
    public static function measurementId(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') return '';
        if (!str_starts_with($value, 'G-')) $value = 'G-' . $value;
        if (!preg_match('/^G-[A-Z0-9]{6,20}$/D', $value)) throw new \RuntimeException('Enter a valid GA4 measurement ID, or leave it empty to disable analytics.', 422);
        return $value;
    }

    public static function policy(string $policy): string
    {
        $directives = [];
        foreach (explode(';', $policy) as $part) {
            $parts = preg_split('/\s+/', trim($part));
            $name = array_shift($parts);
            if ($name !== '') $directives[$name] = $parts;
        }
        foreach (['script-src'=>['https://www.googletagmanager.com'], 'script-src-elem'=>['https://www.googletagmanager.com'],
            'connect-src'=>['https://*.google-analytics.com','https://*.analytics.google.com','https://www.googletagmanager.com'],
            'img-src'=>['https://*.google-analytics.com','https://www.googletagmanager.com']] as $name=>$sources) {
            $fallback = $name === 'script-src-elem' ? ($directives['script-src'] ?? null) : null;
            $existing = $directives[$name] ?? $fallback ?? $directives['default-src'] ?? ["'self'"];
            $directives[$name] = array_values(array_unique(array_merge(array_diff($existing, ["'none'"]), $sources)));
        }
        return implode('; ', array_map(static fn($name,$values)=>$name.' '.implode(' ',$values), array_keys($directives),$directives));
    }

    public static function inject(string $html, string $id): string
    {
        if (!str_contains($html, '</head>') || str_contains($html, 'data-sense-ga')) return $html;
        $id = self::measurementId($id);
        if ($id === '') return $html;
        return str_replace('</head>', '<link rel="stylesheet" href="/extension-assets/plugin/google-analytics/analytics.css?v=0.1.0"><script src="/extension-assets/plugin/google-analytics/analytics.js?v=0.1.0" data-sense-ga data-measurement-id="'.$id.'" defer></script></head>', $html);
    }
}
