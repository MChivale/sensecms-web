<?php

declare(strict_types=1);

namespace SenseCMS\Calendar;

use RuntimeException;

final class CalendarI18n
{
    public const LOCALES = ['de', 'en', 'lo', 'pl', 'th', 'vi', 'zh'];
    private array $copy;

    public function __construct(private readonly string $root, ?string $requested, string $fallback = 'en')
    {
        $this->locale = $this->detect($requested, $fallback);
        $this->copy = $this->load($this->locale);
        if ($this->locale !== 'en') $this->copy = array_replace_recursive($this->load('en'), $this->copy);
    }

    public readonly string $locale;

    public function all(): array { return $this->copy; }

    public function t(string $key, array $replace = []): string
    {
        $value = $this->copy;
        foreach (explode('.', $key) as $part) $value = is_array($value) ? ($value[$part] ?? null) : null;
        $text = is_string($value) ? $value : $key;
        foreach ($replace as $name => $replacement) $text = str_replace('{' . $name . '}', (string) $replacement, $text);
        return $text;
    }

    public function message(string $message): string
    {
        $translated = $this->copy['errors'][$message] ?? null;
        return is_string($translated) && $translated !== '' ? $translated : $message;
    }

    private function detect(?string $requested, string $fallback): string
    {
        $candidates = [$requested, $_GET['lang'] ?? null];
        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) $candidates[] = explode(';', trim($part), 2)[0] ?? '';
        $candidates[] = $fallback;
        $candidates[] = 'en';
        foreach ($candidates as $candidate) {
            $candidate = strtolower(str_replace('_', '-', trim((string) $candidate)));
            $candidate = explode('-', $candidate, 2)[0];
            if (in_array($candidate, self::LOCALES, true)) return $candidate;
        }
        return 'en';
    }

    private function load(string $locale): array
    {
        $file = $this->root . '/lang/' . $locale . '.json';
        if (!is_file($file)) throw new RuntimeException('Calendar language file is unavailable.');
        $copy = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($copy)) throw new RuntimeException('Calendar language file is invalid.');
        return $copy;
    }
}
