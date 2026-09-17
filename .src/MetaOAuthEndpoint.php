<?php

declare(strict_types=1);

namespace SenseCMS\Website;

use JsonException;
use RuntimeException;
use Throwable;

/** Strict public ingress for the official Meta broker. */
final class MetaOAuthEndpoint
{
    /** @return array{0:int,1:array<string,string>,2:string} */
    public static function response(array $server,string $body,MetaBrokerService $broker):array
    {
        $headers=['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"];
        try {
            if(($server['HTTPS']??'off')==='off'||empty($server['HTTPS'])||($server['HTTP_HOST']??'')!=='www.sensecms.com')return[421,$headers,self::json(['ok'=>false])];
            $uri=parse_url((string)($server['REQUEST_URI']??''));$path=is_array($uri)?(string)($uri['path']??''):'';$method=(string)($server['REQUEST_METHOD']??'');
            if(!str_starts_with($path,'/api/social/meta/v1/'))return[404,$headers,self::json(['ok'=>false])];
            if((int)($server['CONTENT_LENGTH']??0)>65536||strlen($body)>65536)return[413,$headers,self::json(['ok'=>false])];
            if($path==='/api/social/meta/v1/authorize'&&$method==='GET')return self::redirect($broker->authorize((string)($_GET['request']??'')),$headers);
            if($path==='/api/social/meta/v1/callback'&&$method==='GET'){
                $result=$broker->callback($_GET);
                if(isset($result['redirect']))return self::redirect((string)$result['redirect'],$headers);
                $selection=$result['selection']??null;if(!is_array($selection))throw new RuntimeException('Invalid Meta selection state.',503);
                $headers['Content-Type']='text/html; charset=utf-8';return[200,$headers,self::selection($selection)];
            }
            if($path==='/api/social/meta/v1/select'&&$method==='POST'){
                self::contentType($server,'application/x-www-form-urlencoded');parse_str($body,$input);
                return self::redirect($broker->select((string)($input['selection']??''),(string)($input['page_id']??'')),$headers);
            }
            if($path==='/api/social/meta/v1/data-deletion'&&$method==='POST'){
                self::contentType($server,'application/x-www-form-urlencoded');parse_str($body,$input);$result=$broker->deleteData((string)($input['signed_request']??''));unset($result['removed']);
                $headers['Content-Type']='application/json; charset=utf-8';return[200,$headers,self::json($result)];
            }
            if(!in_array($path,['/api/social/meta/v1/start','/api/social/meta/v1/claim'],true))return[404,$headers,self::json(['ok'=>false])];
            if($method!=='POST')return[405,$headers,self::json(['ok'=>false])];
            self::contentType($server,'application/json');$input=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($input)||!str_starts_with(ltrim($body),'{'))return[400,$headers,self::json(['ok'=>false])];
            $result=$path==='/api/social/meta/v1/start'?$broker->start($server,$input):$broker->claim($server,(string)($input['claim']??''));
            $headers['Content-Type']='application/json; charset=utf-8';return[200,$headers,self::json($result)];
        }catch(JsonException){return[400,$headers,self::json(['ok'=>false])];}
        catch(Throwable$error){$status=$error instanceof RuntimeException&&in_array($error->getCode(),[401,403,404,405,410,413,415,422,429,502,503],true)?$error->getCode():503;$headers['Content-Type']='application/json; charset=utf-8';return[$status,$headers,self::json(['ok'=>false,'message'=>$status===422?$error->getMessage():'The Meta connection could not be completed.'])];}
    }

    private static function redirect(string$url,array$headers):array
    {
        $parts=parse_url($url);if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('Unsafe Meta redirect.',503);
        $headers['Location']=$url;return[302,$headers,''];
    }

    private static function contentType(array$server,string$expected):void
    {
        if(strtolower(trim(explode(';',(string)($server['CONTENT_TYPE']??''),2)[0]))!==$expected)throw new RuntimeException('Unsupported request content type.',415);
    }

    private static function selection(array$selection):string
    {
        $e=static fn(string$value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$items='';
        foreach((array)($selection['pages']??[])as$index=>$page)$items.='<label><input required type="radio" name="page_id" value="'.$e((string)$page['id']).'"'.($index===0?' checked':'').'><span><strong>'.$e((string)$page['name']).'</strong><small>Page ID '.$e((string)$page['id']).'</small></span></label>';
        return'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Select Facebook Page · Sense CMS</title><style>body{margin:0;background:#f4f7fb;color:#13233b;font:16px system-ui,sans-serif}.card{max-width:620px;margin:8vh auto;padding:32px;background:#fff;border:1px solid #dce5f0;border-radius:20px;box-shadow:0 18px 50px #17335a18}h1{font-size:28px}p,small{color:#60708a}label{display:flex;gap:14px;align-items:center;margin:12px 0;padding:14px;border:1px solid #dce5f0;border-radius:12px}label span,label strong,label small{display:block}button{margin-top:18px;padding:12px 18px;border:0;border-radius:10px;background:#1763d6;color:#fff;font-weight:700;cursor:pointer}</style><main class="card"><h1>Select the Facebook Page</h1><p>Choose the Page that Sense CMS may publish reviewed posts to.</p><form method="post" action="/api/social/meta/v1/select"><input type="hidden" name="selection" value="'.$e((string)($selection['token']??'')).'">'.$items.'<button type="submit">Connect selected Page</button></form></main></html>';
    }

    private static function json(array$value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
}
