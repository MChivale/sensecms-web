<?php
declare(strict_types=1);

namespace App\Core;

use OpenSSLAsymmetricKey;
use RuntimeException;

final class WebPushKeyStore
{
    private string $path;

    public function __construct(private readonly string $root, private readonly array $config)
    {
        $this->path=(string)($config['key_file']??'');
        if ($this->path==='') $this->path=$root.'/storage/private/web-push-vapid.json';
    }

    public function ensure(): array
    {
        $stored=$this->read();
        if ($stored) return $stored;
        $directory=dirname($this->path);
        if (!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)) throw new RuntimeException('Private Web Push storage is not writable.');
        $lock=fopen($this->path.'.lock','c+');
        if (!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('Web Push key generation is busy.');
        try {
            if ($stored=$this->read()) return $stored;
            $options=['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1'];$opensslConfig=(string)(getenv('OPENSSL_CONF')?:'');if($opensslConfig!==''&&is_file($opensslConfig))$options['config']=$opensslConfig;
            $key=openssl_pkey_new($options);
            if (!$key||!openssl_pkey_export($key,$private,null,isset($options['config'])?['config'=>$options['config']]:null)) throw new RuntimeException('A VAPID key pair could not be generated.');
            $details=openssl_pkey_get_details($key);$ec=$details['ec']??[];
            if (!is_string($ec['x']??null)||!is_string($ec['y']??null)||strlen($ec['x'])!==32||strlen($ec['y'])!==32) throw new RuntimeException('The generated VAPID public key is invalid.');
            $subject=(string)($this->config['subject']??'mailto:info@sensecms.com');
            if (!preg_match('#^(?:mailto:[^\s@]+@[^\s@]+|https://[^\s]+)$#iD',$subject)) throw new RuntimeException('The VAPID contact subject is invalid.');
            $stored=['version'=>1,'subject'=>$subject,'public_key'=>self::b64("\x04".$ec['x'].$ec['y']),'private_key'=>$private,'created_at'=>gmdate(DATE_ATOM)];
            $temporary=$this->path.'.'.bin2hex(random_bytes(8)).'.tmp';
            if (file_put_contents($temporary,json_encode($stored,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('The VAPID key pair could not be stored.');
            @chmod($temporary,0600);
            if (!rename($temporary,$this->path)) { @unlink($temporary);throw new RuntimeException('The VAPID key pair could not be activated.'); }
            @chmod($this->path,0600);
            return $stored;
        } finally { flock($lock,LOCK_UN);fclose($lock);@unlink($this->path.'.lock'); }
    }

    public function privateKey(): OpenSSLAsymmetricKey
    {
        $key=openssl_pkey_get_private((string)$this->ensure()['private_key']);
        if (!$key) throw new RuntimeException('The VAPID private key cannot be loaded.');
        return $key;
    }

    private function read(): array
    {
        if (!is_file($this->path)) return [];
        $stored=json_decode((string)file_get_contents($this->path),true,8,JSON_THROW_ON_ERROR);
        $public=self::decode((string)($stored['public_key']??''));
        if (($stored['version']??0)!==1||strlen($public)!==65||$public[0]!=="\x04"||!openssl_pkey_get_private((string)($stored['private_key']??''))) throw new RuntimeException('The stored VAPID key pair is invalid.');
        return $stored;
    }

    public static function b64(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
    public static function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$value)) return '';
        $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
        return is_string($decoded)?$decoded:'';
    }
}
