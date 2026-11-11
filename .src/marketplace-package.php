<?php
declare(strict_types=1);

// Fixed official Marketplace download endpoint for a signed catalogue entry.
// It accepts the same Core entitlement as /packages/download, but never exposes
// archive paths or permits callers to select a package outside the catalogue.
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
require dirname(__DIR__) . '/bootstrap.php';
try {
    if (($_SERVER['HTTP_HOST'] ?? '') !== 'www.sensecms.com') throw new RuntimeException('Unexpected host.');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
    $id = (string) ($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Unknown package.', 404);
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D', $authorization, $match)) throw new App\Core\LicenseException('Invalid licence.');
    $domain = (string) ($_SERVER['HTTP_X_SENSECMS_DOMAIN'] ?? '');
    $root = dirname(__DIR__); $runtime = new App\Core\Runtime($root);
    $catalogPath = $root . '/storage/marketplace-catalog.json';
    if (is_link($catalogPath) || !is_file($catalogPath) || filesize($catalogPath) > 524288) throw new RuntimeException('Catalogue unavailable.');
    $catalog = App\Core\OfficialCatalog::verify((string) file_get_contents($catalogPath));
    $product = null;
    foreach ($catalog['products'] as $candidate) if (is_array($candidate) && hash_equals((string) ($candidate['id'] ?? ''), $id)) { $product = $candidate; break; }
    if (!is_array($product) || empty($product['installable'])) throw new RuntimeException('Unknown package.', 404);
    $identity = (string) $product['type'] . ':' . (string) $product['slug'];
    if (!hash_equals(substr(hash('sha256', $identity), 0, 32), $id)) throw new RuntimeException('Unknown package.', 404);
    $service = new App\Core\Packages\Distribution($runtime);
    $service->rate((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    [$entry, $bytes] = $service->download($identity, $match[1], $domain);
    if (!hash_equals((string) $product['package_checksum'], $entry['sha256']) || $product['version'] !== $entry['version']) throw new RuntimeException('Catalogue mismatch.');
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . $entry['file'] . '"');
    header('Content-Length: ' . strlen($bytes)); header('X-Package-SHA256: ' . $entry['sha256']); echo $bytes;
} catch (App\Core\LicenseException) {
    http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo '{"ok":false,"message":"The licence could not be verified."}';
} catch (Throwable $error) {
    $status = $error->getCode() === 404 ? 404 : ($error->getCode() === 429 ? 429 : 503);
    if ($status === 429) header('Retry-After: 900');
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo $status === 404 ? '{"ok":false,"message":"This package is not available."}' : '{"ok":false,"message":"Marketplace download is temporarily unavailable."}';
}
