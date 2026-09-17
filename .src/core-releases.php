<?php
declare(strict_types=1);
// Official website only. The publisher private key never belongs to this endpoint.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
try{
    if(($_SERVER['HTTP_HOST']??'')!=='www.sensecms.com')throw new RuntimeException('Unexpected host.');
    if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');exit;}
    require dirname(__DIR__).'/bootstrap.php';$runtime=new App\Core\Runtime(dirname(__DIR__));
    $raw=json_encode($runtime->read('release-feed'),JSON_THROW_ON_ERROR);
    App\Core\CoreReleases::verify($raw,(string)($runtime->read('update-trust')['public_key']??''));
    if($_SERVER['REQUEST_METHOD']==='GET')echo $raw;
}catch(Throwable){http_response_code(503);echo '{"ok":false,"message":"Stable release catalogue is temporarily unavailable."}';}
