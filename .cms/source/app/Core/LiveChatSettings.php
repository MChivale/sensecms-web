<?php

declare(strict_types=1);

namespace App\Core;

final class LiveChatSettings
{
    public static function defaults(): array
    {
        return [
            'general' => ['localized' => [
                'en' => ['title' => 'Live Chat', 'welcome' => 'How can we help?', 'intro' => 'Send us a message and our team will help you.', 'online' => 'Ready to help', 'offline' => 'Offline now · leave us a message'],
                'km' => ['title' => 'ការជជែកផ្ទាល់', 'welcome' => 'តើយើងអាចជួយអ្វីបាន?', 'intro' => 'ផ្ញើសារមកយើង ហើយក្រុមការងាររបស់យើងនឹងជួយអ្នក។', 'online' => 'រួចរាល់ដើម្បីជួយ', 'offline' => 'ក្រៅម៉ោងធ្វើការ · សូមទុកសារ'],
                'zh' => ['title' => '在线客服', 'welcome' => '我们能为您做什么？', 'intro' => '请发送消息，我们的团队将为您提供帮助。', 'online' => '随时为您服务', 'offline' => '当前离线 · 请留言'],
            ]],
            'availability' => [
                'enabled' => true,
                'timezone' => 'Asia/Phnom_Penh',
                'schedule' => [
                    'monday' => ['enabled' => true, 'start' => '07:30', 'end' => '17:30'],
                    'tuesday' => ['enabled' => true, 'start' => '07:30', 'end' => '17:30'],
                    'wednesday' => ['enabled' => true, 'start' => '07:30', 'end' => '17:30'],
                    'thursday' => ['enabled' => true, 'start' => '07:30', 'end' => '17:30'],
                    'friday' => ['enabled' => true, 'start' => '07:30', 'end' => '17:30'],
                    'saturday' => ['enabled' => true, 'start' => '08:00', 'end' => '12:00'],
                    'sunday' => ['enabled' => false, 'start' => '08:00', 'end' => '12:00'],
                ],
            ],
            'retention' => ['enabled' => true, 'days' => 30],
            'sounds' => [
                'incoming' => 'ring.mp3',
                'admin_message' => 'notification-05.mp3',
                'visitor_message' => 'notification-03.mp3',
            ],
        ];
    }

    public static function from(mixed $stored): array
    {
        $defaults = self::defaults();
        if (!is_array($stored)) return $defaults;
        $settings = array_replace_recursive($defaults, $stored);
        $allowedNotifications = array_map(static fn(int $number): string => sprintf('notification-%02d.mp3', $number), range(1, 5));
        $settings['sounds']['incoming'] = 'ring.mp3';
        foreach (['admin_message', 'visitor_message'] as $key) {
            if (!in_array($settings['sounds'][$key] ?? '', $allowedNotifications, true)) $settings['sounds'][$key] = $defaults['sounds'][$key];
        }
        $settings['availability']['enabled'] = (bool) ($settings['availability']['enabled'] ?? true);
        $timezone = (string) ($settings['availability']['timezone'] ?? 'Asia/Phnom_Penh');
        $settings['availability']['timezone'] = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'Asia/Phnom_Penh';
        foreach ($defaults['availability']['schedule'] as $day => $fallback) {
            $row = is_array($settings['availability']['schedule'][$day] ?? null) ? $settings['availability']['schedule'][$day] : $fallback;
            $settings['availability']['schedule'][$day] = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'start' => self::time((string) ($row['start'] ?? ''), $fallback['start']),
                'end' => self::time((string) ($row['end'] ?? ''), $fallback['end']),
            ];
        }
        $settings['retention']['enabled'] = (bool) ($settings['retention']['enabled'] ?? true);
        $settings['retention']['days'] = max(1, min(365, (int) ($settings['retention']['days'] ?? 30)));
        return $settings;
    }

    public static function isAvailable(array $settings, ?\DateTimeImmutable $at = null): bool
    {
        $settings = self::from($settings);
        if (!$settings['availability']['enabled']) return true;
        $timezone = new \DateTimeZone($settings['availability']['timezone']);
        $now = ($at ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $day = strtolower($now->format('l'));
        $hours = $settings['availability']['schedule'][$day] ?? null;
        if (!is_array($hours) || !$hours['enabled']) return false;
        $time = $now->format('H:i');
        return $hours['start'] <= $hours['end'] ? $time >= $hours['start'] && $time < $hours['end'] : $time >= $hours['start'] || $time < $hours['end'];
    }

    public static function userDefaults(): array
    {
        return ['avatar_mode' => 'default', 'custom_avatar_url' => ''];
    }

    public static function userFrom(mixed $stored): array
    {
        $settings = array_replace(self::userDefaults(), is_array($stored) ? $stored : []);
        if (!in_array($settings['avatar_mode'], ['default', 'profile', 'custom'], true)) $settings['avatar_mode'] = 'default';
        if (!is_string($settings['custom_avatar_url']) || !str_starts_with($settings['custom_avatar_url'], '/media/chat-avatars/')) $settings['custom_avatar_url'] = '';
        if ($settings['avatar_mode'] === 'custom' && $settings['custom_avatar_url'] === '') $settings['avatar_mode'] = 'default';
        return $settings;
    }

    public static function avatar(array $user, array $settings): string
    {
        return match ($settings['avatar_mode'] ?? 'default') {
            'profile' => str_starts_with((string) ($user['avatar_url'] ?? ''), '/media/avatars/') ? (string) $user['avatar_url'] : '/assets/logo.svg',
            'custom' => str_starts_with((string) ($settings['custom_avatar_url'] ?? ''), '/media/chat-avatars/') ? (string) $settings['custom_avatar_url'] : '/assets/logo.svg',
            default => '/assets/logo.svg',
        };
    }

    private static function time(string $value, string $fallback): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
    }
}
