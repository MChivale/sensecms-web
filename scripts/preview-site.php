<?php

declare(strict_types=1);

// Developer-only, loopback-bound theme preview. Never a production entry point.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(?::[0-9]+)?$/D', $_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(404); exit;
}
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$theme = new App\Core\PublicTheme(dirname(__DIR__) . '/.themes/sensecms', 'https://www.sensecms.com');
[$status, $headers, $body] = $theme->response((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), $_SERVER['REQUEST_METHOD'] ?? 'GET');
http_response_code($status);
foreach ($headers as $name => $value) header($name . ': ' . $value);
header('X-Robots-Tag: noindex, nofollow');
echo $body;
