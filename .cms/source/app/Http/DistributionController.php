<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\LicenseException;
use App\Core\Packages\Distribution;
use App\Core\Runtime;

final class DistributionController
{
    public static function run(Runtime $runtime,bool $local): never
    {
        $json=static function(array $data,int $status=200):never {
            http_response_code($status);header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);exit;
        };
        try {
            $service=new Distribution($runtime);
            $runtime->license()->enforce($runtime->baseUrl());
            $method=$_SERVER['REQUEST_METHOD']??'GET';
            if ($method==='HEAD') { http_response_code(405);header('Allow: GET, POST');exit; }
            if ($_GET) $json(['ok'=>false,'message'=>'Use the request body, not query parameters.'],400);
            $mask=umask(0077);
            try {
                $sessions=$runtime->root.'/storage/distribution/sessions';
                if (is_link(dirname($sessions)) || is_link($sessions) || !is_dir($sessions) && !mkdir($sessions,0700,true)) throw new \RuntimeException('Cannot initialise protected downloads.',503);
            } finally {umask($mask);}
            ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
            session_save_path($sessions);session_name('sensecms_download');
            session_set_cookie_params(['httponly'=>true,'secure'=>!$local,'samesite'=>'Strict','path'=>'/packages/download']);session_start();
            if ($method==='GET') {
                if (($_SESSION['expires']??0)<time()) $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'expires'=>time()+900];
                $csrf=$_SESSION['csrf'];session_write_close();
                $json(['ok'=>true,'csrf'=>$csrf,'products'=>$service->offers()]);
            }
            $csrf=$_POST['csrf']??null;
            $origin=$_SERVER['HTTP_ORIGIN']??'';
            $expected=$local?'http://'.($_SERVER['HTTP_HOST']??''):$runtime->baseUrl();
            if (!is_string($csrf) || !hash_equals($_SESSION['csrf']??'',$csrf) || ($_SESSION['expires']??0)<time()
                || $origin!=='' && $origin!==$expected || ($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin')==='cross-site') $json(['ok'=>false,'message'=>'Your download session expired. Close this window and try again.'],419);
            session_write_close();
            $service->rate((string)($_SERVER['REMOTE_ADDR']??''));
            foreach (['product','license_key','domain'] as $field) if (!is_string($_POST[$field]??null)) $json(['ok'=>false,'message'=>'Enter a package, licence key and installation domain.'],422);
            $key=trim($_POST['license_key']);unset($_POST['license_key']);
            try {[$entry,$bytes]=$service->download($_POST['product'],$key,$_POST['domain']);}
            finally {sodium_memzero($key);}
            header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$entry['file'].'"');
            header('Content-Length: '.strlen($bytes));header('X-Package-SHA256: '.$entry['sha256']);echo $bytes;exit;
        } catch (LicenseException) {
            $json(['ok'=>false,'message'=>'The licence could not be verified. Check the product key, validity and installation domain, or try again later.'],403);
        } catch (\Throwable $error) {
            $status=in_array($error->getCode(),[404,429],true)?$error->getCode():503;
            if ($status===429) header('Retry-After: 900');
            $json(['ok'=>false,'message'=>match($status){404=>'This package is not available to download.',429=>'Too many attempts. Try again in 15 minutes.',default=>'Package download is temporarily unavailable.'}],$status);
        }
    }
}
