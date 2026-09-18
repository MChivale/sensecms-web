<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2)exit("Usage: provide private JSON on stdin, then provision-pinterest-broker.php <installation>\n");
umask(0077);$root=realpath($argv[1]);if(!$root||!is_file($root.'/bootstrap.php'))throw new RuntimeException('Choose an existing Sense CMS installation.');
$raw=stream_get_contents(STDIN,8193);if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('Private Pinterest configuration is invalid.');$config=json_decode($raw,true,8,JSON_THROW_ON_ERROR);sodium_memzero($raw);
if(!is_array($config)||($config['app_id']??null)!=='1613166'||($config['client_id']??null)!=='1613166'||!preg_match('/^[^\x00-\x20]{8,512}$/D',(string)($config['client_secret']??''))||array_diff(array_keys($config),['app_id','client_id','client_secret']))throw new RuntimeException('Private Pinterest configuration does not match the reviewed application.');
$private=$root.'/storage/private/pinterest';if(is_link($private))throw new RuntimeException('Unsafe private Pinterest directory.');if(!is_dir($private)&&!mkdir($private,0700,true))throw new RuntimeException('Cannot create private Pinterest storage.');if(PHP_OS_FAMILY!=='Windows'&&!chmod($private,0700))throw new RuntimeException('Cannot protect private Pinterest storage.');
$keyPath=$private.'/key.bin';if(is_link($keyPath))throw new RuntimeException('Unsafe Pinterest key path.');$key=is_file($keyPath)?(string)file_get_contents($keyPath):random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Invalid existing Pinterest key.');
$write=static function(string$path,string$data):void{$tmp=$path.'.new-'.bin2hex(random_bytes(8));if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||!chmod($tmp,0600)||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cannot write protected Pinterest configuration.');}};
if(!is_file($keyPath))$write($keyPath,$key);$write($private.'/config.json',json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");sodium_memzero($key);unset($config);
echo"Pinterest broker private configuration provisioned.\n";
