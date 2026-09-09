<?php

declare(strict_types=1);

namespace App\Core\Packages;

use App\Core\LicenseClient;
use App\Core\LicenseException;

/** Resolve only trusted server inventory or an independently verified signed catalog. */
final class Entitlement
{
    public static function licenseConfig(array $product, array $cms): array
    {
        $pricing = $product['pricing'] ?? null;
        if (!in_array($pricing, ['free', 'paid'], true)) throw new LicenseException('Package licensing terms are missing or invalid.');
        $config = $cms;
        if ($pricing === 'paid') {
            $license = $product['license'] ?? null;
            if (!is_array($license)) throw new LicenseException('Paid package license identity is missing.');
            foreach (['product_name' => 150, 'product_model' => 200] as $field => $limit) {
                $value = $license[$field] ?? null;
                if (!is_string($value) || $value === '' || trim($value) !== $value || strlen($value) > $limit
                    || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new LicenseException('Paid package license identity is invalid.');
                $config[$field] = $value;
            }
            if ($config['product_name'] === $cms['product_name'] && $config['product_model'] === $cms['product_model']) throw new LicenseException('A paid package must have its own license identity.');
        }
        // Never derive this protocol field from a package or Core release version.
        $config['product_version'] = '1.0';
        return $config;
    }

    public static function headers(array $product, #[\SensitiveParameter] string $key, string $domain, array $cms): array
    {
        LicenseClient::assertKey($key);
        $domain = LicenseClient::domain($domain);
        $config = self::licenseConfig($product, $cms);
        // These headers inform legacy consumers, never authorize a server download.
        // The server must resolve the requested package in its own trusted inventory.
        return ['Authorization: Bearer ' . $key, 'X-SenseCMS-Domain: ' . $domain,
            'X-SenseCMS-Version: ' . $config['product_version'],
            'X-SenseCMS-Product-Name: ' . base64_encode($config['product_name']),
            'X-SenseCMS-Product-Model: ' . base64_encode($config['product_model']), 'Accept: application/zip'];
    }
}
