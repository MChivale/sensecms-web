<?php

declare(strict_types=1);

namespace App\Core;

final class PluginComponentRenderer
{
    public static function render(string $projectRoot, string $source, string $renderer, array $context): string
    {
        if (!preg_match('/^(plugin|addon):([a-z][a-z0-9-]{1,79})$/', $source, $match) || !preg_match('#^[a-z0-9/_-]+\.php$#', $renderer) || str_contains($renderer, '..')) return '';
        $directory=$match[1]==='addon'?'addons':'plugins';$base=rtrim($projectRoot,'/\\').DIRECTORY_SEPARATOR.$directory;
        $root = realpath($base . DIRECTORY_SEPARATOR . $match[2]);
        $file = realpath($base . DIRECTORY_SEPARATOR . $match[2] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $renderer));
        if (!$root || !$file || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) return '';
        $data = is_array($context['data'] ?? null) ? $context['data'] : []; $shared = is_array($context['shared'] ?? null) ? $context['shared'] : []; $locale = (string) ($context['locale'] ?? 'en'); $page = is_array($context['page'] ?? null) ? $context['page'] : [];
        ob_start();
        try { require $file; return (string) ob_get_clean(); }
        catch (\Throwable $error) { ob_end_clean(); error_log('Plugin Page Builder component failed: ' . $error->getMessage()); return ''; }
    }
}
