<?php
declare(strict_types=1);

namespace App\Core;

use OpenSSLAsymmetricKey;
use RuntimeException;

final class WebPushCrypto
{
    public function encrypt(string $plaintext,string $clientKey,string $authSecret,?OpenSSLAsymmetricKey $senderKey=null,?string $salt=null): string
    {
        if (strlen($plaintext)>3000) throw new RuntimeException('The Web Push payload is too large.');
        $ua=WebPushKeyStore::decode($clientKey);$auth=WebPushKeyStore::decode($authSecret);
        if (strlen($ua)!==65||$ua[0]!=="\x04"||strlen($auth)!==16) throw new RuntimeException('The browser subscription keys are invalid.');
        if(!$senderKey){$options=['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1'];$opensslConfig=(string)(getenv('OPENSSL_CONF')?:'');if($opensslConfig!==''&&is_file($opensslConfig))$options['config']=$opensslConfig;$senderKey=openssl_pkey_new($options);}
        if (!$senderKey) throw new RuntimeException('The message key could not be generated.');
        $details=openssl_pkey_get_details($senderKey);$ec=$details['ec']??[];
        if (!is_string($ec['x']??null)||!is_string($ec['y']??null)) throw new RuntimeException('The message public key is invalid.');
        $server="\x04".str_pad($ec['x'],32,"\0",STR_PAD_LEFT).str_pad($ec['y'],32,"\0",STR_PAD_LEFT);
        $peer=openssl_pkey_get_public($this->publicPem($ua));
        $shared=$peer?openssl_pkey_derive($peer,$senderKey,32):false;
        if (!is_string($shared)||strlen($shared)!==32) throw new RuntimeException('The browser subscription key is not a valid P-256 point.');
        $prkKey=hash_hmac('sha256',$shared,$auth,true);
        $ikm=hash_hmac('sha256',"WebPush: info\0".$ua.$server."\x01",$prkKey,true);
        $salt??=random_bytes(16);
        if (strlen($salt)!==16) throw new RuntimeException('The Web Push salt is invalid.');
        $prk=hash_hmac('sha256',$ikm,$salt,true);
        $cek=substr(hash_hmac('sha256',"Content-Encoding: aes128gcm\0\x01",$prk,true),0,16);
        $nonce=substr(hash_hmac('sha256',"Content-Encoding: nonce\0\x01",$prk,true),0,12);
        $cipher=openssl_encrypt($plaintext."\x02",'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
        if (!is_string($cipher)||strlen($tag)!==16) throw new RuntimeException('The Web Push payload could not be encrypted.');
        return $salt.pack('N',4096).chr(65).$server.$cipher.$tag;
    }

    public function authorization(string $endpoint,WebPushKeyStore $keys): string
    {
        $parts=parse_url($endpoint);$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));$port=(int)($parts['port']??0);
        if ($scheme!=='https'||$host==='') throw new RuntimeException('The push endpoint is invalid.');
        $aud='https://'.$host.($port&&$port!==443?':'.$port:'');$stored=$keys->ensure();
        $header=$this->json64(['typ'=>'JWT','alg'=>'ES256']);$claims=$this->json64(['aud'=>$aud,'exp'=>time()+43200,'sub'=>$stored['subject']]);$input=$header.'.'.$claims;
        if (!openssl_sign($input,$der,$keys->privateKey(),OPENSSL_ALGO_SHA256)) throw new RuntimeException('The VAPID assertion could not be signed.');
        return 'vapid t='.$input.'.'.WebPushKeyStore::b64($this->derToJose($der)).', k='.$stored['public_key'];
    }

    private function publicPem(string $raw): string
    {
        $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
    }

    private function json64(array $value): string { return WebPushKeyStore::b64(json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }

    private function derToJose(string $der): string
    {
        $offset=0;if (ord($der[$offset++]??"\0")!==0x30) throw new RuntimeException('The VAPID signature is invalid.');
        $this->length($der,$offset);
        if (ord($der[$offset++]??"\0")!==0x02) throw new RuntimeException('The VAPID signature is invalid.');
        $r=substr($der,$offset,$this->length($der,$offset));$offset+=strlen($r);
        if (ord($der[$offset++]??"\0")!==0x02) throw new RuntimeException('The VAPID signature is invalid.');
        $s=substr($der,$offset,$this->length($der,$offset));
        $normalize=static function(string $integer):string{$integer=ltrim($integer,"\0");if(strlen($integer)>32)throw new RuntimeException('The VAPID signature is invalid.');return str_pad($integer,32,"\0",STR_PAD_LEFT);};
        return $normalize($r).$normalize($s);
    }

    private function length(string $der,int &$offset): int
    {
        $length=ord($der[$offset++]??"\0");if($length<0x80)return $length;$bytes=$length&0x7f;if($bytes<1||$bytes>2)throw new RuntimeException('The VAPID signature is invalid.');$length=0;while($bytes--){$length=($length<<8)|ord($der[$offset++]??"\0");}return $length;
    }
}
