<?php

declare(strict_types=1);

namespace SenseCMS\Website;

use RuntimeException;
use SensitiveParameter;

final class AtprotoCrypto
{
    public static function generate(): array
    {
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if($key===false||!openssl_pkey_export($key,$private))throw new RuntimeException('Cannot generate AT Protocol signing key.');$jwk=self::publicJwk($private);return[$private,$jwk];
    }

    public static function publicJwk(#[SensitiveParameter]string$private): array
    {
        $key=openssl_pkey_get_private($private);$details=$key!==false?openssl_pkey_get_details($key):false;$ec=is_array($details)?($details['ec']??null):null;
        if(!is_array($ec)||!is_string($ec['x']??null)||!is_string($ec['y']??null)||strlen($ec['x'])<1||strlen($ec['x'])>32||strlen($ec['y'])<1||strlen($ec['y'])>32)throw new RuntimeException('Invalid AT Protocol signing key.');
        return['kty'=>'EC','crv'=>'P-256','x'=>self::b64(str_pad($ec['x'],32,"\0",STR_PAD_LEFT)),'y'=>self::b64(str_pad($ec['y'],32,"\0",STR_PAD_LEFT))];
    }

    public static function kid(array$jwk): string{return self::b64(hash('sha256',json_encode(['crv'=>'P-256','kty'=>'EC','x'=>$jwk['x'],'y'=>$jwk['y']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),true));}

    public static function proof(string$method,string$url,#[SensitiveParameter]string$private,array$jwk,?string$nonce=null,#[SensitiveParameter]?string$access=null): string
    {
        $header=['typ'=>'dpop+jwt','alg'=>'ES256','jwk'=>$jwk];$payload=['jti'=>self::b64(random_bytes(24)),'htm'=>strtoupper($method),'htu'=>self::htu($url),'iat'=>time()];if($nonce!==null&&$nonce!=='')$payload['nonce']=$nonce;if($access!==null)$payload['ath']=self::b64(hash('sha256',$access,true));return self::jwt($header,$payload,$private);
    }

    public static function assertion(string$issuer,#[SensitiveParameter]string$private,array$jwk): string
    {
        $now=time();return self::jwt(['typ'=>'JWT','alg'=>'ES256','kid'=>self::kid($jwk)],['iss'=>BlueskyBrokerService::CLIENT_ID,'sub'=>BlueskyBrokerService::CLIENT_ID,'aud'=>$issuer,'jti'=>self::b64(random_bytes(24)),'iat'=>$now,'exp'=>$now+60],$private);
    }

    public static function b64(string$value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}

    private static function jwt(array$header,array$payload,#[SensitiveParameter]string$private): string
    {
        $input=self::b64(json_encode($header,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)).'.'.self::b64(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));$key=openssl_pkey_get_private($private);if($key===false||!openssl_sign($input,$der,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('AT Protocol signing failed.');return$input.'.'.self::b64(self::der($der));
    }

    private static function htu(string$url): string
    {
        $parts=parse_url($url);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Invalid AT Protocol proof address.');$origin='https://'.strtolower((string)$parts['host']);$port=(int)($parts['port']??443);if($port!==443)$origin.=':'.$port;return$origin.(string)($parts['path']??'/');
    }

    private static function der(string$der): string
    {
        $offset=0;if(ord($der[$offset++]??"\0")!==0x30)throw new RuntimeException('Invalid AT Protocol signature.');self::length($der,$offset);if(ord($der[$offset++]??"\0")!==0x02)throw new RuntimeException('Invalid AT Protocol signature.');$length=self::length($der,$offset);$r=substr($der,$offset,$length);$offset+=$length;if(ord($der[$offset++]??"\0")!==0x02)throw new RuntimeException('Invalid AT Protocol signature.');$s=substr($der,$offset,self::length($der,$offset));$r=str_pad(ltrim($r,"\0"),32,"\0",STR_PAD_LEFT);$s=str_pad(ltrim($s,"\0"),32,"\0",STR_PAD_LEFT);if(strlen($r)!==32||strlen($s)!==32)throw new RuntimeException('Invalid AT Protocol signature.');return$r.$s;
    }

    private static function length(string$data,int&$offset): int{$first=ord($data[$offset++]??"\0");if($first<0x80)return$first;$bytes=$first&0x7f;if($bytes<1||$bytes>2)throw new RuntimeException('Invalid AT Protocol signature.');$value=0;for($i=0;$i<$bytes;$i++)$value=($value<<8)|ord($data[$offset++]??"\0");return$value;}
}
