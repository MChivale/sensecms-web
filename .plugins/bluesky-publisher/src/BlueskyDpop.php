<?php

declare(strict_types=1);

namespace SenseCMS\Bluesky;

use RuntimeException;
use SensitiveParameter;

final class BlueskyDpop
{
    public static function proof(string$method,string$url,#[SensitiveParameter]string$private,string$x,string$y,?string$nonce=null,#[SensitiveParameter]?string$access=null): string
    {
        $header=['typ'=>'dpop+jwt','alg'=>'ES256','jwk'=>['kty'=>'EC','crv'=>'P-256','x'=>$x,'y'=>$y]];$payload=['jti'=>self::b64(random_bytes(24)),'htm'=>strtoupper($method),'htu'=>self::htu($url),'iat'=>time()];
        if($nonce!==null&&$nonce!=='')$payload['nonce']=$nonce;if($access!==null)$payload['ath']=self::b64(hash('sha256',$access,true));
        return self::jwt($header,$payload,$private);
    }

    public static function jwt(array$header,array$payload,#[SensitiveParameter]string$private): string
    {
        $input=self::b64(json_encode($header,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)).'.'.self::b64(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));$key=openssl_pkey_get_private($private);
        if($key===false||!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Bluesky proof signing failed.');
        return$input.'.'.self::b64(self::der($der));
    }

    public static function b64(string$value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}

    private static function htu(string$url): string
    {
        $parts=parse_url($url);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('The Bluesky proof address is invalid.');
        $origin='https://'.strtolower((string)$parts['host']);$port=(int)($parts['port']??443);if($port!==443)$origin.=':'.$port;return$origin.(string)($parts['path']??'/');
    }

    private static function der(string$der): string
    {
        $offset=0;if((ord($der[$offset++]??"\0"))!==0x30)throw new RuntimeException('Invalid Bluesky signature.');self::length($der,$offset);if((ord($der[$offset++]??"\0"))!==0x02)throw new RuntimeException('Invalid Bluesky signature.');$length=self::length($der,$offset);$r=substr($der,$offset,$length);$offset+=$length;if((ord($der[$offset++]??"\0"))!==0x02)throw new RuntimeException('Invalid Bluesky signature.');$length=self::length($der,$offset);$s=substr($der,$offset,$length);$r=str_pad(ltrim($r,"\0"),32,"\0",STR_PAD_LEFT);$s=str_pad(ltrim($s,"\0"),32,"\0",STR_PAD_LEFT);if(strlen($r)!==32||strlen($s)!==32)throw new RuntimeException('Invalid Bluesky signature.');return$r.$s;
    }

    private static function length(string$data,int&$offset): int
    {
        $first=ord($data[$offset++]??"\0");if($first<0x80)return$first;$bytes=$first&0x7f;if($bytes<1||$bytes>2)throw new RuntimeException('Invalid Bluesky signature.');$value=0;for($i=0;$i<$bytes;$i++)$value=($value<<8)|ord($data[$offset++]??"\0");return$value;
    }
}
