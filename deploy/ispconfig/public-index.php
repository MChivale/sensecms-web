<?php
declare(strict_types=1);

// ISPConfig owns the public web directory. Keep the CMS and its secrets in private.
$application = dirname(__DIR__) . '/private/sensecms';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_dir($application . '/website') && is_string($path)) {
    $brokers = [
        '/api/telegram/v1/' => 'telegram-webhook.php',
        '/api/social/meta/v1/' => 'meta-oauth.php',
        '/api/social/x/v1/' => 'x-oauth.php',
        '/api/social/linkedin/v1/' => 'linkedin-oauth.php',
        '/api/social/bluesky/v1/' => 'bluesky-oauth.php',
        '/api/social/pinterest/v1/' => 'pinterest-oauth.php',
        '/api/social/tiktok/v1/' => 'tiktok-oauth.php',
        '/api/social/youtube/v1/' => 'youtube-oauth.php',
    ];
    if ($path === '/api/updates/v1/catalog') {
        require $application . '/website/core-releases.php';
        return;
    }
    if ($path === '/api/marketplace/v1/catalog') {
        require $application . '/website/marketplace-catalog.php';
        return;
    }
    if ($path === '/api/marketplace/v1/package/') {
        require $application . '/website/marketplace-package.php';
        return;
    }
    foreach ($brokers as $prefix => $endpoint) {
        if (str_starts_with($path, $prefix)) {
            require $application . '/website/' . $endpoint;
            return;
        }
    }
}
require $application . '/public/index.php';
