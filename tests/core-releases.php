<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\CoreReleases;
use App\Core\Runtime;
use App\Core\SystemUpdate;
$checks=0;$check=static function(bool $ok,string $name)use(&$checks):void{if(!$ok)throw new RuntimeException($name);$checks++;echo "PASS $name\n";};
$reject=static function(callable $action,string $name)use($check):void{$failed=false;try{$action();}catch(Throwable){$failed=true;}$check($failed,$name);};
$pair=sodium_crypto_sign_keypair();$key=sodium_crypto_sign_secretkey($pair);$public=base64_encode(sodium_crypto_sign_publickey($pair));
$data=['schema'=>1,'product'=>'Sense CMS','channel'=>'stable','issued_at'=>time(),'expires_at'=>time()+86400,'releases'=>[]];
$sign=static function(array $data)use($key):string{$raw=json_encode($data,JSON_THROW_ON_ERROR);return json_encode(['signed_payload'=>base64_encode($raw),'signature'=>base64_encode(sodium_crypto_sign_detached($raw,$key))],JSON_THROW_ON_ERROR);};
$current=(require dirname(__DIR__).'/.cms/source/config/product.php')['core_version'];
$next=explode('.',$current);$next[2]=(string)((int)$next[2]+1);
$release=['version'=>implode('.',$next),'released_at'=>time()-3600,'php_min'=>'8.5.0','notes'=>['Isolated test release, never published.'],'url'=>'https://www.sensecms.com/update'];
$check(CoreReleases::verify($sign($data),$public)['releases']===[],'Authentic empty Stable feed is not a fabricated release');
foreach(['channel'=>'development','product'=>'Other CMS','schema'=>2,'issued_at'=>time()+301,'expires_at'=>time()-1] as $field=>$value)$reject(fn()=>CoreReleases::verify($sign(array_replace($data,[$field=>$value])),$public),'Reject invalid '.$field);
$reject(fn()=>CoreReleases::verify($sign($data),base64_encode(random_bytes(32))),'Independent wrong publisher key rejected');
$reject(fn()=>CoreReleases::verify(str_repeat('x',65537),$public),'Catalogue byte bound enforced');
$envelope=json_decode($sign($data),true);$envelope['signed_payload']=base64_encode('{}');
$reject(fn()=>CoreReleases::verify(json_encode($envelope),$public),'Signed payload tampering rejected');
foreach(['version'=>'0.2.0-beta','url'=>'https://evil.example/update','php_min'=>'8.x','notes'=>[],'released_at'=>time()+3600] as $field=>$value)$reject(fn()=>CoreReleases::verify($sign(array_replace($data,['releases'=>[array_replace($release,[$field=>$value])]])),$public),'Reject invalid release '.$field);
$reject(fn()=>CoreReleases::verify($sign(array_replace($data,['releases'=>[$release,$release]])),$public),'Duplicate versions rejected');
$root=sys_get_temp_dir().'/sense-release-'.bin2hex(random_bytes(12));mkdir($root,0700);mkdir($root.'/config');copy(dirname(__DIR__).'/.cms/source/config/product.php',$root.'/config/product.php');
try{
    $runtime=new Runtime($root);$runtime->write('update-trust',['public_key'=>$public]);
    // PDO is unused by read-only update checks; avoid any production database access.
    $db=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
    $calls=0;$raw=$sign($data);$fetch=static function()use(&$raw,&$calls):string{$calls++;return $raw;};
    $service=new SystemUpdate($db,$root,[],$fetch);
    $check(!$service->status()['verified'],'No successful check before transport');
    $service->requestCheck();$state=$service->status();
    $check($state['verified']&&!$state['available']&&!$state['latest']&&$state['checked_at']>0,'Empty signed feed is verified without an update notification');
    $service->requestCheck();$service->checkIfDue();$check($calls===1,'Manual cooldown and six-hour automatic interval avoid duplicate calls');
    $runtime->write('core-update-state',['attempted_at'=>time()-21601]);$raw=$sign(array_replace($data,['releases'=>[$release]]));
    $service->checkIfDue();$state=$service->status();
    $check($state['verified']&&$state['available']&&$state['latest']['version']===$release['version'],'Due automatic check discovers a newer Stable version');
    $check(!$state['install_supported']&&SystemUpdate::allowed('app/Core/Auth.php')===false,'Checking never enables archive replacement');
    $runtime->write('core-update-state',['attempted_at'=>time()-61]);$raw='invalid';$reject(fn()=>$service->requestCheck(),'Transport or signature failure remains failure');
    $check(!$service->status()['verified']&&!$service->status()['available']&&$service->status()['error']!==null,'Failure never claims latest or triggers a false update notification');
    $count=$calls;$service->checkIfDue();$check($calls===$count,'Failures back off for one hour');
    $runtime->write('core-update-state',['attempted_at'=>time()-61]);$raw=$sign(array_replace($data,['issued_at'=>time()-120,'releases'=>[$release]]));
    $reject(fn()=>$service->requestCheck(),'Older signed catalogue cannot roll back recorded issuance');
    foreach([fn()=>$service->requestInstall('0.2.0',1),fn()=>$service->run(),fn()=>$service->verifyArchive('/missing',[])]as$action)$reject($action,'Legacy installation still refused');
    echo "$checks Core release checks passed; isolated signing and transport only.\n";
}finally{
    if(!preg_match('#/sense-release-[a-f0-9]{24}$#',str_replace('\\','/',$root)))throw new RuntimeException('Unsafe fixture path');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($root);
}
