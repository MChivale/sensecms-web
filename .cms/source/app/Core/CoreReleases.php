<?php
declare(strict_types=1);
namespace App\Core;

use RuntimeException;

/** Public release metadata only: never downloads or executes a Core archive. */
final class CoreReleases
{
    public const URL = 'https://www.sensecms.com/api/updates/v1/catalog';

    public static function verify(string $raw, string $publicKey, ?int $now = null): array
    {
        $now ??= time();
        if (strlen($raw) > 65536) throw new RuntimeException('Release catalogue exceeds its limit.');
        $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $key = base64_decode($publicKey, true);
        $payload = base64_decode((string)($envelope['signed_payload'] ?? ''), true);
        $signature = base64_decode((string)($envelope['signature'] ?? ''), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($payload) || !is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $payload, $key)) throw new RuntimeException('Release signature could not be verified.');
        $data = json_decode($payload, true, 12, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 1 || ($data['product'] ?? '') !== 'Sense CMS' || ($data['channel'] ?? '') !== 'stable'
            || !is_int($data['issued_at'] ?? null) || !is_int($data['expires_at'] ?? null)
            || $data['issued_at'] > $now + 300 || $data['issued_at'] < $now - 172800
            || $data['expires_at'] <= $now || $data['expires_at'] <= $data['issued_at']
            || $data['expires_at'] > $data['issued_at'] + 172800) throw new RuntimeException('Release catalogue is invalid or expired.');
        self::validate($data['releases'] ?? null);
        usort($data['releases'], static fn(array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $data;
    }

    public static function validate(mixed $releases): void
    {
        if (!is_array($releases) || !array_is_list($releases) || count($releases) > 50) throw new RuntimeException('Invalid release list.');
        $seen = [];
        foreach ($releases as $release) {
            if (!is_array($release) || array_diff(array_keys($release), ['version','released_at','php_min','notes','url'])
                || !preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', (string)($release['version'] ?? ''))
                || isset($seen[$release['version']]) || !is_int($release['released_at'] ?? null)
                || $release['released_at'] < 1 || $release['released_at'] > time() + 300
                || !preg_match('/^\d+\.\d+\.\d+$/D', (string)($release['php_min'] ?? ''))
                || ($release['url'] ?? '') !== 'https://www.sensecms.com/update'
                || !is_array($release['notes'] ?? null) || !array_is_list($release['notes'])
                || count($release['notes']) < 1 || count($release['notes']) > 12) throw new RuntimeException('Invalid Stable release metadata.');
            foreach ($release['notes'] as $note) if (!is_string($note) || trim($note) === '' || strlen($note) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $note)) throw new RuntimeException('Invalid release note.');
            $seen[$release['version']] = true;
        }
    }

    public static function download(): string
    {
        $addresses = array_column(dns_get_record('www.sensecms.com', DNS_A) ?: [], 'ip');
        if (!$addresses) throw new RuntimeException('Release service DNS is unavailable.');
        foreach ($addresses as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Invalid release service address.');
        $body = ''; $curl = curl_init(self::URL);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>12, CURLOPT_RESOLVE=>['www.sensecms.com:443:'.$addresses[0]],
            CURLOPT_USERAGENT=>'SenseCMS-Release-Check/1.0', CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($ok === false || $status !== 200) throw new RuntimeException('The release service is unavailable.');
        return $body;
    }
}
