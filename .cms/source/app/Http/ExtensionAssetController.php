<?php

declare(strict_types=1);

namespace App\Http;

final class ExtensionAssetController
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
    ];

    public static function serve(string $root, string $type, string $slug, string $asset): never
    {
        if (!in_array($type, ['addon', 'plugin'], true) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) self::missing();
        $asset = rawurldecode($asset);
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]{0,240}$#D', $asset) || str_contains($asset, '..') || str_starts_with($asset, '/')) self::missing();
        $base = realpath($root . DIRECTORY_SEPARATOR . ($type === 'addon' ? 'addons' : 'plugins') . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'assets');
        $file = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset));
        $extension = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
        if ($base === false || $file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !isset(self::TYPES[$extension])) self::missing();
        header('Content-Type: ' . self::TYPES[$extension]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400, immutable');
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
        exit;
    }

    private static function missing(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found');
    }
}
