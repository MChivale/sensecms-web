<?php
declare(strict_types=1);

// Deploy in <official installation>/website, never in customer Core or a theme.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
try {
    require dirname(__DIR__).'/bootstrap.php';
    require __DIR__.'/TelegramBrokerService.php';
    require __DIR__.'/TelegramWebhook.php';
    $root=dirname(__DIR__).'/storage/private/telegram';
    foreach (['config.json','key.bin'] as $file) {
        $path=$root.'/'.$file;
        if (is_link($path)||!is_file($path)||filesize($path)>8192||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077))) throw new RuntimeException('Private service unavailable.');
    }
    $config=json_decode((string)file_get_contents($root.'/config.json'),true,8,JSON_THROW_ON_ERROR);
    $key=(string)file_get_contents($root.'/key.bin');
    $broker=new SenseCMS\Website\TelegramBrokerService($root,$key,$config);
    sodium_memzero($key);unset($config);
    $body=(string)file_get_contents('php://input',false,null,0,65537);
    [$status,$result]=SenseCMS\Website\TelegramWebhook::response($_SERVER,$body,$broker);
} catch (Throwable) { $status=503;$result=['ok'=>false]; }
if ($status===405) header('Allow: POST');
if (in_array($status,[429,503],true)) header('Retry-After: 5');
http_response_code($status);
echo json_encode($result,JSON_THROW_ON_ERROR);
