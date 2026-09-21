<?php

declare(strict_types=1);

namespace SenseCMS\Website;

use JsonException;
use RuntimeException;
use Throwable;

final class YouTubeOAuthEndpoint
{
    public static function response(array$server,string$body,YouTubeBrokerService$broker):array
    {
        $headers=['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Security-Policy'=>"default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"];
        try{if(($server['HTTPS']??'off')==='off'||empty($server['HTTPS'])||($server['HTTP_HOST']??'')!=='www.sensecms.com')return[421,$headers,self::json(['ok'=>false])];$uri=parse_url((string)($server['REQUEST_URI']??''));$path=is_array($uri)?(string)($uri['path']??''):'';$method=(string)($server['REQUEST_METHOD']??'');if(!str_starts_with($path,'/api/social/youtube/v1/'))return[404,$headers,self::json(['ok'=>false])];if($path==='/api/social/youtube/v1/authorize'&&$method==='GET')return self::redirect($broker->authorize((string)($_GET['request']??'')),$headers);if($path==='/api/social/youtube/v1/callback'&&$method==='GET')return self::redirect($broker->callback($_GET),$headers);if((int)($server['CONTENT_LENGTH']??0)>65536||strlen($body)>65536)return[413,$headers,self::json(['ok'=>false])];if(!in_array($path,['/api/social/youtube/v1/start','/api/social/youtube/v1/claim','/api/social/youtube/v1/refresh'],true))return[404,$headers,self::json(['ok'=>false])];if($method!=='POST')return[405,$headers,self::json(['ok'=>false])];self::contentType($server);$input=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)||!str_starts_with(ltrim($body),'{'))return[400,$headers,self::json(['ok'=>false])];$result=match($path){'/api/social/youtube/v1/start'=>$broker->start($server,$input),'/api/social/youtube/v1/claim'=>$broker->claim($server,(string)($input['claim']??'')),default=>$broker->refresh($server,(string)($input['refresh_token']??''))};$headers['Content-Type']='application/json; charset=utf-8';return[200,$headers,self::json($result)];}catch(JsonException){return[400,$headers,self::json(['ok'=>false])];}catch(Throwable$error){$status=$error instanceof RuntimeException&&in_array($error->getCode(),[401,403,404,405,410,413,415,422,429,502,503],true)?$error->getCode():503;$headers['Content-Type']='application/json; charset=utf-8';$message=$status===422?$error->getMessage():'The YouTube connection could not be completed.';return[$status,$headers,self::json(['ok'=>false,'message'=>$message])];}
    }
    private static function redirect(string$url,array$headers):array{$parts=parse_url($url);if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('Unsafe YouTube redirect.',503);$headers['Location']=$url;return[302,$headers,''];}
    private static function contentType(array$server):void{$actual=strtolower(trim(explode(';',(string)($server['CONTENT_TYPE']??''),2)[0]));if($actual!=='application/json')throw new RuntimeException('Unsupported request content type.',415);}
    private static function json(array$value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}
