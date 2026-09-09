<?php

declare(strict_types=1);

namespace App\Core;

final class Translator
{
    public function __construct(private readonly string $path, private readonly string $fallback = 'en') {}

    public function get(string $key, string $locale, bool $cms = false): string
    {
        $base = $cms ? $this->path . '/cms' : $this->path;
        $translations = $this->load($base, $locale) + $this->load($base, $this->fallback);
        return $translations[$key] ?? $key;
    }

    private function load(string $path, string $locale): array
    {
        $file = $path . '/' . $locale . '.json';
        if (!is_file($file)) return [];
        try { return json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return []; }
    }
}
