<?php
declare(strict_types=1);
namespace App\Core;

use RuntimeException;

final class OfficialCatalog
{
    public const BASE = 'https://www.sensecms.com/api/marketplace/v1';
    public static function key(): string
    {
        $key = (new Runtime(dirname(__DIR__, 2)))->read('distribution')['public_key'] ?? '';
        $binary = is_string($key) ? base64_decode($key, true) : false;
        if (!is_string($binary) || strlen($binary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('Sense CMS distribution trust has not been configured.');
        return $binary;
    }

    public static function verify(string $raw): array
    {
        $envelope=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        $payload=base64_decode((string)($envelope['signed_payload']??''),true);
        $signature=base64_decode((string)($envelope['signature']??''),true);
        if(!is_string($payload)||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($signature,$payload,self::key())) throw new RuntimeException('Official catalog signature verification failed.');
        $data=json_decode($payload,true,64,JSON_THROW_ON_ERROR);
        if(($data['schema']??0)!==1||($data['expires_at']??0)<time()||($data['issued_at']??0)>time()+300||!is_array($data['products']??null)||count($data['products'])>200) throw new RuntimeException('Official catalog is invalid or expired.');
        return $data;
    }

    public static function download(string $url,int $limit,array $headers=[]): string
    {
        self::key();
        if(!str_starts_with($url,self::BASE.'/')||parse_url($url,PHP_URL_HOST)!=='www.sensecms.com'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PORT)!==null) throw new RuntimeException('Unexpected distribution host.');
        $addresses=array_column(dns_get_record('www.sensecms.com',DNS_A)?:[],'ip');
        if(!$addresses) throw new RuntimeException('Distribution host is unavailable.');
        foreach($addresses as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Invalid distribution address.');
        $body='';$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>90,CURLOPT_RESOLVE=>['www.sensecms.com:443:'.$addresses[0]],CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Core-Update/1.0',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,$limit):int{if(strlen($body)+strlen($chunk)>$limit)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false||$status!==200) throw new RuntimeException($status===401||$status===403?'The installation license does not authorize this download.':'The official distribution service is unavailable.');
        return $body;
    }
}
