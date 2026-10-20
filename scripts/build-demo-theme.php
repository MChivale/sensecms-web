<?php

declare(strict_types=1);

use App\Core\Packages\Archive;
use App\Core\Runtime;

if (PHP_SAPI !== 'cli' || $argc !== 5 || $argv[1] !== '/home/demo.sensecms.com/web') {
    throw new RuntimeException('Usage: build-demo-theme.php /home/demo.sensecms.com/web <source> <output.zip> <private-key-file>');
}
[$script, $root, $source, $output, $keyFile] = $argv;
require $root . '/bootstrap.php';
$runtime = new Runtime($root);
if ($runtime->baseUrl() !== 'https://demo.sensecms.com' || realpath($source) === false || basename($source) !== 'sense-demo') {
    throw new RuntimeException('Demo identity or source directory does not match.');
}
if (is_link($keyFile) || !is_file($keyFile) || (fileperms($keyFile) & 0077)) {
    throw new RuntimeException('A protected publisher signing key is required.');
}
$key = base64_decode(trim((string) file_get_contents($keyFile)), true);
if (!is_string($key)) throw new RuntimeException('Invalid publisher key encoding.');
try {
    $result = Archive::build($source, $output, $key);
} finally {
    sodium_memzero($key);
}
echo 'Signed demo theme: ' . $result['sha256'] . PHP_EOL;
