<?php

declare(strict_types=1);

namespace App\Http;

final class ThemeAssetController
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    public static function serve(string $root, string $slug, string $asset): never
    {
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,239}$#', $asset) || str_contains($asset, '..')) self::missing();
        $base = realpath($root . '/themes/' . $slug . '/assets');
        $file = $base ? realpath($base . '/' . $asset) : false;
        $prefix = $base ? str_replace('\\', '/', rtrim($base, '/\\')) . '/' : '';
        $normalized = $file ? str_replace('\\', '/', $file) : '';
        if (!$base || !$file || !is_file($file) || !str_starts_with($normalized, $prefix)) self::missing();
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) self::missing();
        header('Content-Type: ' . self::TYPES[$extension]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        if ($extension === 'svg') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
        readfile($file);
        exit;
    }

    private static function missing(): never
    {
        http_response_code(404);
        exit('Asset not found');
    }
}
