<?php

declare(strict_types=1);

namespace App\Core;

final class CaptchaService
{
    public function __construct(private readonly string $scope = 'login') {}

    public function config(array $value = []): array
    {
        return [
            'enabled' => (bool) ($value['enabled'] ?? true),
            'letters' => (bool) ($value['letters'] ?? true),
            'numbers' => (bool) ($value['numbers'] ?? true),
            'symbols' => (bool) ($value['symbols'] ?? false),
            'length' => max(4, min(10, (int) ($value['length'] ?? 6))),
        ];
    }

    public function issue(array $value = []): array
    {
        $config = $this->config($value);
        if (!$config['enabled']) return $config;
        $pool = ($config['letters'] ? 'ABCDEFGHJKLMNPQRSTUVWXYZ' : '') . ($config['numbers'] ? '23456789' : '') . ($config['symbols'] ? '@#$%*?+' : '');
        if ($pool === '') { $config['letters'] = true; $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; }
        $code = ''; for ($i = 0; $i < $config['length']; $i++) $code .= $pool[random_int(0, strlen($pool) - 1)];
        $_SESSION[$this->key()] = ['hash' => hash('sha256', $code), 'expires' => time() + 600];
        return $config;
    }

    public function valid(string $answer, array $value = []): bool
    {
        if (!$this->config($value)['enabled']) return true;
        $current = $_SESSION[$this->key()] ?? null; unset($_SESSION[$this->key()], $_SESSION[$this->key('_code')]);
        return is_array($current) && ($current['expires'] ?? 0) >= time() && hash_equals((string) ($current['hash'] ?? ''), hash('sha256', strtoupper(trim($answer))));
    }

    public function image(): never
    {
        $code = (string) ($_SESSION[$this->key('_code')] ?? '');
        if ($code === '' || !isset($_SESSION[$this->key()])) { http_response_code(404); exit; }
        if (!function_exists('imagecreatetruecolor')) { header('Content-Type: image/svg+xml'); echo '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="52"><rect width="100%" height="100%" rx="8" fill="#edf3ff"/><text x="18" y="34" fill="#17324a" font-family="monospace" font-size="24">' . htmlspecialchars($code, ENT_XML1) . '</text></svg>'; exit; }
        $image = imagecreatetruecolor(180, 52); $bg = imagecolorallocate($image, 238, 244, 255); imagefill($image, 0, 0, $bg);
        for ($i = 0; $i < 8; $i++) imagesetthickness($image, 1); imageline($image, random_int(0, 180), random_int(0, 52), random_int(0, 180), random_int(0, 52), imagecolorallocate($image, random_int(160, 210), random_int(175, 220), random_int(190, 240)));
        for ($i = 0; $i < 45; $i++) imagesetpixel($image, random_int(0, 179), random_int(0, 51), imagecolorallocate($image, random_int(120, 190), random_int(135, 205), random_int(155, 225)));
        for ($i = 0; $i < strlen($code); $i++) imagestring($image, 5, 16 + $i * 24, random_int(15, 22), $code[$i], imagecolorallocate($image, random_int(15, 55), random_int(45, 90), random_int(90, 145)));
        header('Content-Type: image/png'); header('Cache-Control: no-store, private'); imagepng($image); imagedestroy($image); exit;
    }

    public function saveCodeForImage(array $config): void
    {
        if (!$config['enabled']) { unset($_SESSION[$this->key('_code')]); return; }
        // The image copy is kept only for the short-lived graphic; validation uses the hash above.
        $pool = ($config['letters'] ? 'ABCDEFGHJKLMNPQRSTUVWXYZ' : '') . ($config['numbers'] ? '23456789' : '') . ($config['symbols'] ? '@#$%*?+' : ''); if ($pool === '') $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = ''; for ($i=0;$i<$config['length'];$i++) $code .= $pool[random_int(0, strlen($pool)-1)];
        $_SESSION[$this->key()] = ['hash'=>hash('sha256',$code),'expires'=>time()+600]; $_SESSION[$this->key('_code')]=$code;
    }

    private function key(string $suffix = ''): string
    {
        $scope = preg_replace('/[^a-z0-9_-]/i', '', $this->scope) ?: 'default';
        return 'sensecms_captcha_' . $scope . $suffix;
    }
}
