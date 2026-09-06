<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80500) throw new RuntimeException('Sense CMS requires PHP 8.5 or newer.');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $relative = substr($class, 4);
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z][A-Za-z0-9_]*)*$/D', $relative)) return;
    $file = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});
