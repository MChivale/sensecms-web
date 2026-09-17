<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class SiteChrome
{
    private const HEADER_FIELDS = [
        'utility_text' => 180,
        'utility_contact_label' => 80,
        'utility_contact_url' => 500,
        'header_cta_label' => 80,
        'header_cta_url' => 500,
    ];

    private const FOOTER_FIELDS = [
        'footer_description' => 800,
        'footer_directions_label' => 80,
        'footer_directions_url' => 500,
        'footer_explore_title' => 80,
        'footer_connect_title' => 80,
        'copyright_line_1' => 240,
        'copyright_line_2' => 240,
    ];

    public static function defaults(): array
    {
        $english = [
            'utility_text' => 'Stay connected with our team',
            'utility_contact_label' => 'Contact',
            'utility_contact_url' => 'mailto:{{email}}',
            'header_cta_label' => 'Contact us',
            'header_cta_url' => '#contact',
            'footer_description' => 'Discover our work, services and latest updates. Get in touch with our team.',
            'footer_directions_label' => 'Get directions',
            'footer_directions_url' => '{{map_url}}',
            'footer_explore_title' => 'Explore',
            'footer_connect_title' => 'Connect',
            'copyright_line_1' => '© {{year}} {{site_name}}.',
            'copyright_line_2' => 'All rights reserved.',
        ];
        return ['localized' => [
            'en' => $english,
            'km' => array_replace($english, [
                'utility_text' => 'រក្សាទំនាក់ទំនងជាមួយក្រុមការងាររបស់យើង',
                'utility_contact_label' => 'ទំនាក់ទំនង',
                'header_cta_label' => 'ទំនាក់ទំនង',
                'footer_description' => 'ស្វែងយល់អំពីការងារ សេវាកម្ម និងព័ត៌មានថ្មីៗរបស់យើង។ ទាក់ទងក្រុមការងាររបស់យើង។',
                'footer_directions_label' => 'ទទួលទិសដៅ',
                'footer_explore_title' => 'ស្វែងយល់',
                'footer_connect_title' => 'ទំនាក់ទំនង',
                'copyright_line_2' => 'រក្សាសិទ្ធិគ្រប់យ៉ាង។',
            ]),
            'zh' => array_replace($english, [
                'utility_text' => '与我们的团队保持联系',
                'utility_contact_label' => '联系我们',
                'header_cta_label' => '联系我们',
                'footer_description' => '了解我们的工作、服务和最新动态，欢迎联系我们的团队。',
                'footer_directions_label' => '获取路线',
                'footer_explore_title' => '探索',
                'footer_connect_title' => '联系',
                'copyright_line_2' => '保留所有权利。',
            ]),
        ]];
    }

    public static function fields(string $location): array
    {
        return $location === 'footer' ? self::FOOTER_FIELDS : self::HEADER_FIELDS;
    }

    public static function saveLocale(array $existing, string $locale, string $location, array $input): array
    {
        $localized = is_array($existing['localized'] ?? null) ? $existing['localized'] : [];
        $current = is_array($localized[$locale] ?? null) ? $localized[$locale] : [];
        foreach (self::fields($location) as $key => $limit) {
            $value = mb_substr(trim((string) ($input[$key] ?? '')), 0, $limit);
            if (str_ends_with($key, '_url') && $value !== '' && !self::validUrl($value)) {
                throw new RuntimeException('Enter a valid local path, anchor, e-mail, phone or HTTPS destination.');
            }
            $current[$key] = $value;
        }
        $localized[$locale] = $current;
        $existing['localized'] = $localized;
        return $existing;
    }

    public static function resolve(array $stored, string $locale, string $fallbackLocale, array $profile): array
    {
        $defaults = self::defaults()['localized'];
        $storedLocalized = is_array($stored['localized'] ?? null) ? $stored['localized'] : [];
        $resolved = array_replace(
            $defaults['en'],
            (array) ($defaults[$fallbackLocale] ?? []),
            (array) ($storedLocalized[$fallbackLocale] ?? []),
            (array) ($defaults[$locale] ?? []),
            (array) ($storedLocalized[$locale] ?? [])
        );
        $mapUrl = trim((string) ($profile['map_url'] ?? ''));
        if ($mapUrl === '') {
            $mapUrl = 'https://maps.google.com/?q=' . rawurlencode(trim((string) ($profile['address'] ?? $profile['name'] ?? '')));
        }
        $tokens = [
            '{{year}}' => date('Y'),
            '{{school}}' => trim((string) ($profile['name'] ?? 'Sense CMS')), // Legacy saved templates.
            '{{site_name}}' => trim((string) ($profile['name'] ?? 'Sense CMS')),
            '{{email}}' => trim((string) ($profile['email'] ?? '')),
            '{{map_url}}' => $mapUrl,
        ];
        foreach ($resolved as $key => $value) $resolved[$key] = strtr((string) $value, $tokens);
        return $resolved;
    }

    private static function validUrl(string $value): bool
    {
        if (preg_match('/^#[a-z][a-z0-9_-]*$/i', $value)) return true;
        if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !preg_match('/[\x00-\x1f<>]/', $value)) return true;
        if (preg_match('/^(mailto|tel):[^\s<>]+$/i', $value)) return true;
        if (in_array($value, ['{{map_url}}', 'mailto:{{email}}'], true)) return true;
        return (bool) filter_var($value, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $value);
    }
}
