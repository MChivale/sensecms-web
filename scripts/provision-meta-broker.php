<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2)exit("Usage: provide private JSON on stdin, then provision-meta-broker.php <installation>\n");
umask(0077);$root=realpath($argv[1]);if(!$root||!is_file($root.'/bootstrap.php'))throw new RuntimeException('Choose an existing Sense CMS installation.');
$raw=stream_get_contents(STDIN,8193);if(!is_string($raw)||strlen($raw)>8192)throw new RuntimeException('Private Meta configuration is invalid.');$config=json_decode($raw,true,8,JSON_THROW_ON_ERROR);sodium_memzero($raw);
if(!is_array($config)||($config['app_id']??null)!=='1373530514896687'||($config['configuration_id']??null)!=='28576295405370380'||($config['api_version']??null)!=='v26.0'||!preg_match('/^[A-Za-z0-9]{32,128}$/D',(string)($config['app_secret']??''))||array_diff(array_keys($config),['app_id','app_secret','configuration_id','api_version']))throw new RuntimeException('Private Meta configuration does not match the reviewed application.');
$private=$root.'/storage/private/meta';if(is_link($private))throw new RuntimeException('Unsafe private Meta directory.');if(!is_dir($private)&&!mkdir($private,0700,true))throw new RuntimeException('Cannot create private Meta storage.');if(PHP_OS_FAMILY!=='Windows'&&!chmod($private,0700))throw new RuntimeException('Cannot protect private Meta storage.');
$keyPath=$private.'/key.bin';if(is_link($keyPath))throw new RuntimeException('Unsafe Meta key path.');$key=is_file($keyPath)?(string)file_get_contents($keyPath):random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Invalid existing Meta key.');
$write=static function(string$path,string$data):void{$tmp=$path.'.new-'.bin2hex(random_bytes(8));if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||!chmod($tmp,0600)||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cannot write protected Meta configuration.');}};
if(!is_file($keyPath))$write($keyPath,$key);$write($private.'/config.json',json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");sodium_memzero($key);unset($config);
echo"Meta broker private configuration provisioned.\n";
