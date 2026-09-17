<?php
declare(strict_types=1);
namespace App\Core;

/** Offline country lookup. Never trusts client-supplied forwarding/country headers. */
final class IpCountry
{
    public static function ip(mixed $value): ?string
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP)) return null;
        $packed = inet_pton($value);
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $packed = substr($packed, 12);
        return inet_ntop($packed);
    }

    public static function lookup(?string $ip, ?string $file = null): ?string
    {
        if ($ip === null || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        $handle = @fopen($file ?? dirname(__DIR__, 2) . '/storage/geoip/country.bin', 'rb');
        if (!$handle) return null;
        try {
            $header = fread($handle, 16);
            if (strlen($header) !== 16 || substr($header, 0, 8) !== 'SENSEIP1') return null;
            $counts = unpack('Nv4/Nv6', substr($header, 8)); $ip = inet_pton($ip); $length = strlen($ip);
            if (fstat($handle)['size'] !== 16 + $counts['v4'] * 10 + $counts['v6'] * 34) return null;
            $size = $length * 2 + 2; $base = 16 + ($length === 16 ? $counts['v4'] * 10 : 0);
            $low = 0; $high = $counts[$length === 4 ? 'v4' : 'v6'] - 1;
            while ($low <= $high) {
                $mid = intdiv($low + $high, 2);
                if (fseek($handle, $base + $mid * $size) !== 0) return null;
                $row = fread($handle, $size); if (strlen($row) !== $size) return null;
                if (strcmp($ip, substr($row, 0, $length)) < 0) $high = $mid - 1;
                elseif (strcmp($ip, substr($row, $length, $length)) > 0) $low = $mid + 1;
                else { $country = substr($row, -2); return preg_match('/^[A-Z]{2}$/D', $country) && !in_array($country, ['ZZ','XX'], true) ? $country : null; }
            }
            return null;
        } finally { fclose($handle); }
    }

    public static function name(?string $country): string
    {
        if (!$country || !preg_match('/^[A-Z]{2}$/D', $country)) return 'Unknown';
        return class_exists(\Locale::class) ? (\Locale::getDisplayRegion('und_' . $country, 'en') ?: $country) : $country;
    }
}
