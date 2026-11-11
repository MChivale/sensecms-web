<?php
declare(strict_types=1);

// Official website endpoint. It serves only an already signed public catalogue;
// private package inventory and publisher secrets remain outside the web root.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
try {
    if (($_SERVER['HTTP_HOST'] ?? '') !== 'www.sensecms.com') throw new RuntimeException('Unexpected host.');
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
    require dirname(__DIR__) . '/bootstrap.php';
    $runtime = new App\Core\Runtime(dirname(__DIR__));
    $path = dirname(__DIR__) . '/storage/marketplace-catalog.json';
    if (is_link($path) || !is_file($path) || filesize($path) > 524288) throw new RuntimeException('Catalogue unavailable.');
    $raw = (string) file_get_contents($path);
    App\Core\OfficialCatalog::verify($raw);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') echo $raw;
} catch (Throwable) {
    http_response_code(503); echo '{"ok":false,"message":"Marketplace catalogue is temporarily unavailable."}';
}
