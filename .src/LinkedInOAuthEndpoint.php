<?php

declare(strict_types=1);

namespace SenseCMS\Website;

use JsonException;
use RuntimeException;
use Throwable;

/** Strict public ingress for the official LinkedIn OAuth broker. */
final class LinkedInOAuthEndpoint
{
    public static function response(array$server,string$body,LinkedInBrokerService$broker): array
    {
        $headers=['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Security-Policy'=>"default-src 'none'; frame-ancestors 'none'; base-uri 'none'"];
        try{
            if(($server['HTTPS']??'off')==='off'||empty($server['HTTPS'])||($server['HTTP_HOST']??'')!=='www.sensecms.com')return[421,$headers,self::json(['ok'=>false])];
            $uri=parse_url((string)($server['REQUEST_URI']??''));$path=is_array($uri)?(string)($uri['path']??''):'';$method=(string)($server['REQUEST_METHOD']??'');if(!str_starts_with($path,'/api/social/linkedin/v1/'))return[404,$headers,self::json(['ok'=>false])];if((int)($server['CONTENT_LENGTH']??0)>65536||strlen($body)>65536)return[413,$headers,self::json(['ok'=>false])];
            if($path==='/api/social/linkedin/v1/authorize'&&$method==='GET')return self::redirect($broker->authorize((string)($_GET['request']??'')),$headers);
            if($path==='/api/social/linkedin/v1/callback'&&$method==='GET')return self::redirect($broker->callback($_GET),$headers);
            if(!in_array($path,['/api/social/linkedin/v1/start','/api/social/linkedin/v1/claim'],true))return[404,$headers,self::json(['ok'=>false])];if($method!=='POST')return[405,$headers,self::json(['ok'=>false])];self::contentType($server,'application/json');$input=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||!str_starts_with(ltrim($body),'{'))return[400,$headers,self::json(['ok'=>false])];
            $result=$path==='/api/social/linkedin/v1/start'?$broker->start($server,$input):$broker->claim($server,(string)($input['claim']??''));$headers['Content-Type']='application/json; charset=utf-8';return[200,$headers,self::json($result)];
        }catch(JsonException){return[400,$headers,self::json(['ok'=>false])];}catch(Throwable$error){$status=$error instanceof RuntimeException&&in_array($error->getCode(),[401,403,404,405,410,413,415,422,429,502,503],true)?$error->getCode():503;$headers['Content-Type']='application/json; charset=utf-8';return[$status,$headers,self::json(['ok'=>false,'message'=>$status===422?$error->getMessage():'The LinkedIn connection could not be completed.'])];}
    }
    private static function redirect(string$url,array$headers): array{$parts=parse_url($url);if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('Unsafe LinkedIn redirect.',503);$headers['Location']=$url;return[302,$headers,''];}
    private static function contentType(array$server,string$expected): void{if(strtolower(trim(explode(';',(string)($server['CONTENT_TYPE']??''),2)[0]))!==$expected)throw new RuntimeException('Unsupported request content type.',415);}
    private static function json(array$value): string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}
