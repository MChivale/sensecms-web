<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\Runtime;
use App\Core\Packages\Distribution;
use App\Core\Packages\Archive;
$root=sys_get_temp_dir().'/sense-distribution-'.bin2hex(random_bytes(10));
mkdir($root,0700);mkdir($root.'/config',0700);mkdir($root.'/storage',0700);mkdir($root.'/storage/distribution',0700);mkdir($root.'/storage/distribution/releases',0700);
copy(dirname(__DIR__).'/.cms/source/config/product.php',$root.'/config/product.php');
$runtime=new Runtime($root);$count=0;
$check=static function(bool $ok,string $name)use(&$count):void {if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";};
$reject=static function(callable $fn,string $name)use($check):void {try{$fn();}catch(Throwable){$check(true,$name);return;}$check(false,$name);};
try {
    $reject(fn()=>new Distribution($runtime),'distribution disabled by default');
    $pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);
    $archive=$root.'/storage/distribution/releases/theme-sensecms-0.3.8.zip';
    Archive::build(dirname(__DIR__).'/.themes/sensecms',$archive,$secret);sodium_memzero($secret);
    $entry=['enabled'=>true,'type'=>'theme','slug'=>'sensecms','version'=>'0.3.8','file'=>basename($archive),'sha256'=>hash_file('sha256',$archive),'bytes'=>filesize($archive),'channel'=>'development','pricing'=>'free'];
    $cfg=['enabled'=>true,'publishers'=>['sensecms-release'=>base64_encode($public)],'products'=>['theme:sensecms'=>$entry]];
    $runtime->write('distribution',$cfg);$service=new Distribution($runtime);
    $check(count($service->offers())===1 && !isset($service->offers()['theme:sensecms']['file']),'public offers omit private file configuration');
    $check(hash('sha256',$service->archive('theme:sensecms')[1])===$entry['sha256'],'exact signed archive verified');
    $reject(fn()=>$service->entry('../config/product.php'),'unknown identity rejected');
    $bad=$cfg;$bad['products']['theme:sensecms']['pricing']='paid';$runtime->write('distribution',$bad);
    $reject(fn()=>(new Distribution($runtime))->offers(),'paid product cannot fall back to CMS identity');
    $bad=$cfg;$bad['products']['theme:sensecms']['file']='../outside.zip';$runtime->write('distribution',$bad);
    $reject(fn()=>(new Distribution($runtime))->offers(),'traversal filename rejected');
    $bad=$cfg;$bad['products']['theme:sensecms']['enabled']=false;$runtime->write('distribution',$bad);
    $check((new Distribution($runtime))->offers()===[],'disabled product omitted');
    $reject(fn()=>(new Distribution($runtime))->archive('theme:sensecms'),'disabled product cannot download');
    $bad=$cfg;$bad['products']['theme:sensecms']['sha256']=str_repeat('0',64);$runtime->write('distribution',$bad);
    $reject(fn()=>(new Distribution($runtime))->archive('theme:sensecms'),'checksum mismatch rejected');
    $bad=$cfg;$bad['products']['theme:sensecms']['version']='0.3.9';$runtime->write('distribution',$bad);
    $reject(fn()=>(new Distribution($runtime))->archive('theme:sensecms'),'signed version mismatch rejected');
    $bad=$cfg;$bad['publishers']=[];$runtime->write('distribution',$bad);
    $reject(fn()=>(new Distribution($runtime))->archive('theme:sensecms'),'untrusted signature rejected');
    for($i=0;$i<10;$i++)$service->rate('192.0.2.1');
    $reject(fn()=>$service->rate('192.0.2.1'),'server rate limit survives new service instances');
    $check(!str_contains(file_get_contents($root.'/storage/distribution/rate.json'),'192.0.2.1'),'limiter does not persist client IP');
    echo "$count distribution checks passed.\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
