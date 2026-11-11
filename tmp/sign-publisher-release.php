<?php

declare(strict_types=1);

if ($argc !== 5) {
    throw new RuntimeException('Usage: sign-publisher-release.php <source-root> <key> <package-source> <output>');
}

require $argv[1] . '/.cms/source/bootstrap.php';

$secret = base64_decode(trim((string) file_get_contents($argv[2])), true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    throw new RuntimeException('Invalid publisher signing key.');
}

try {
    $result = App\Core\Packages\Archive::build($argv[3], $argv[4], $secret);
    echo json_encode([
        'identity' => App\Core\Packages\Manifest::identity($result['manifest']),
        'version' => $result['manifest']['version'],
        'sha256' => $result['sha256'],
        'bytes' => $result['bytes'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    sodium_memzero($secret);
}
