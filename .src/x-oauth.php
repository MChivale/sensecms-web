<?php

declare(strict_types=1);

ini_set('display_errors','0');
try{
    require dirname(__DIR__).'/bootstrap.php';require __DIR__.'/XBrokerService.php';require __DIR__.'/XOAuthEndpoint.php';$root=dirname(__DIR__).'/storage/private/x';
    foreach(['config.json','key.bin']as$file){$path=$root.'/'.$file;if(is_link($path)||!is_file($path)||filesize($path)>8192||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)))throw new RuntimeException('Private service unavailable.');}
    $config=json_decode((string)file_get_contents($root.'/config.json'),true,8,JSON_THROW_ON_ERROR);$key=(string)file_get_contents($root.'/key.bin');$broker=new SenseCMS\Website\XBrokerService($root,$key,$config);sodium_memzero($key);unset($config);$body=(string)file_get_contents('php://input',false,null,0,65537);[$status,$headers,$output]=SenseCMS\Website\XOAuthEndpoint::response($_SERVER,$body,$broker);
}catch(Throwable){$status=503;$headers=['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff'];$output='{"ok":false}';}
foreach($headers as$name=>$value)header($name.': '.$value);if($status===405)header('Allow: GET, POST');if(in_array($status,[429,503],true))header('Retry-After: 5');http_response_code($status);echo$output;
