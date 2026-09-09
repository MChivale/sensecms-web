<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Only trusted, operator-installed PHP themes may be rendered. Not a sandbox. */
final class PublicTheme
{
    public function __construct(private readonly string $root, private readonly string $baseUrl) {}

    public function response(string $path, string $method = 'GET', array $context = []): array
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"];
        if (!in_array($method, ['GET', 'HEAD'], true)) return [405, $headers + ['Allow' => 'GET, HEAD'], 'Method not allowed.'];
        if (str_starts_with($path, '/theme-assets/')) {
            $name = substr($path, 14);
            $mime = ['css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'svg' => 'image/svg+xml',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif',
                'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];
            $type = $mime[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
            $base = realpath($this->root . '/assets');
            $file = $base === false ? false : realpath($base . '/' . $name);
            if ($type === null || !preg_match('#^[A-Za-z0-9_-]+(?:[./][A-Za-z0-9_-]+)*$#D', $name)
                || $file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) return [404, $headers, 'Not found.'];
            $headers['Content-Type'] = $type;
            $headers['Content-Length'] = (string) filesize($file);
            if ($type === 'image/svg+xml') $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
            return [200, $headers, $method === 'HEAD' ? '' : (string) file_get_contents($file)];
        }
        $pages = require $this->root . '/pages.php';
        if (!is_array($pages)) throw new RuntimeException('Invalid theme pages.');
        $baseUrl = LicenseClient::domain($this->baseUrl);
        if ($path === '/robots.txt') return [200, ['Content-Type' => 'text/plain; charset=UTF-8'], $method === 'HEAD' ? '' : "User-agent: *\nDisallow: /install\nDisallow: /login\nDisallow: /dashboard\nDisallow: /settings\nDisallow: /license\nSitemap: $baseUrl/sitemap.xml\n"];
        if ($path === '/sitemap.xml') {
            $body = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (array_keys($pages) as $route) $body .= '<url><loc>' . htmlspecialchars($baseUrl . $route, ENT_XML1, 'UTF-8') . '</loc></url>';
            return [200, ['Content-Type' => 'application/xml; charset=UTF-8'], $method === 'HEAD' ? '' : $body . '</urlset>'];
        }
        $page = $pages[$path] ?? ['title' => 'Page not found', 'description' => 'This page does not exist.', 'kind' => '404'];
        $status = isset($pages[$path]) ? 200 : 404;
        if ($method === 'HEAD') return [$status, $headers, ''];
        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $navigation = (array) ($context['navigation'] ?? []);
        $themeSettings = [];
        if (is_file($this->root . '/theme.json')) {
            $descriptor = json_decode((string) file_get_contents($this->root . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
            $themeSettings = ThemeContract::sanitize($descriptor, (array) ($context['theme_settings'] ?? []));
        }
        ob_start();
        try { require $this->root . '/layout.php'; $body = (string) ob_get_contents(); }
        finally { ob_end_clean(); }
        return [$status, $headers, $body];
    }
}
