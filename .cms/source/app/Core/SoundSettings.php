<?php

declare(strict_types=1);

namespace App\Core;

final class SoundSettings
{
    public static function defaults(): array { return ['success' => ['enabled' => true, 'file' => 'confirmation.mp3'], 'warning' => ['enabled' => true, 'file' => 'warning.mp3'], 'error' => ['enabled' => true, 'file' => 'error.mp3'], 'license' => ['enabled' => true, 'file' => 'license.mp3'], 'login' => ['enabled' => true, 'file' => 'login-voice.mp3']]; }
    public static function from(mixed $saved): array { return is_array($saved) ? array_replace_recursive(self::defaults(), $saved) : self::defaults(); }
}
