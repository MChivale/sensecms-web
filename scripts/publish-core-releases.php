<?php
declare(strict_types=1);
// Operator-only metadata publication. Does not create/promote releases or publish archives.
if(PHP_SAPI!=='cli'||count($argv)!==4)exit("Usage: php publish-core-releases.php <installation-root> <reviewed-inventory.json> <private-signing-key>\n");
$root=realpath($argv[1]);if(!$root||!is_file($root.'/bootstrap.php'))throw new RuntimeException('Invalid installation root.');
require $root.'/bootstrap.php';
$runtime=new App\Core\Runtime($root);
if($runtime->baseUrl()!=='https://www.sensecms.com')throw new RuntimeException('Official website only.');
foreach([$argv[2],$argv[3]] as $path)if(is_link($path)||!is_file($path)||filesize($path)>65536)throw new RuntimeException('Invalid operator input.');
if(PHP_OS_FAMILY!=='Windows'&&(fileperms($argv[3])&0077))throw new RuntimeException('Private signing key permissions required.');
$inventory=json_decode((string)file_get_contents($argv[2]),true,12,JSON_THROW_ON_ERROR);
App\Core\CoreReleases::validate($inventory['releases']??null);
$payload=json_encode(['schema'=>1,'product'=>'Sense CMS','channel'=>'stable','issued_at'=>time(),'expires_at'=>time()+86400,'releases'=>$inventory['releases']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$secret=base64_decode(trim((string)file_get_contents($argv[3])),true);
if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid signing key.');
try{$envelope=['signed_payload'=>base64_encode($payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload,$secret))];}
finally{sodium_memzero($secret);}
App\Core\CoreReleases::verify(json_encode($envelope,JSON_THROW_ON_ERROR),(string)($runtime->read('update-trust')['public_key']??''));
$path=$root.'/storage/release-feed.json';if(is_link($path))throw new RuntimeException('Unsafe release feed target.');
$temp=$path.'.'.bin2hex(random_bytes(8));
try{
    $raw=json_encode($envelope,JSON_THROW_ON_ERROR);
    if(file_put_contents($temp,$raw,LOCK_EX)!==strlen($raw)||!chmod($temp,0644)||!rename($temp,$path))throw new RuntimeException('Cannot publish release metadata.');
}finally{if(is_file($temp))unlink($temp);}
echo 'Signed '.count($inventory['releases']).' reviewed Stable release entries; no archive publication.'.PHP_EOL;
