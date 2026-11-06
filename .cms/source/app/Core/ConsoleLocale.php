<?php

declare(strict_types=1);

namespace App\Core;

/** Interface language is independent of the locale of the content being edited. */
final class ConsoleLocale
{
    public const SUPPORTED = ['en', 'pl'];
    public const COOKIE = 'sensecms_console_language';

    /** Only application-owned interface strings belong in this catalogue. */
    public static function catalogue(): array
    {
        if (self::resolve($_COOKIE[self::COOKIE] ?? null) !== 'pl') return [];
        static $polish;
        $file = dirname(__DIR__, 2) . '/lang/cms/interface.pl.json';
        return $polish ??= json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    }

    public static function messages(): \Closure
    {
        $messages = self::catalogue();
        return static fn(string $source, array $parameters = []): string =>
            strtr($messages[$source] ?? $source, $parameters);
    }

    public static function resolve(mixed $preference): string
    {
        return is_string($preference) && in_array($preference, self::SUPPORTED, true)
            ? $preference
            : 'en';
    }
}
