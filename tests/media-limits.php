<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\MediaLibrary;
$root=sys_get_temp_dir().'/sense-media-limits-'.bin2hex(random_bytes(12));mkdir($root,0700);
$db=new class extends PDO {
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {throw new RuntimeException('validated-before-database');}
};
$media=new MediaLibrary($db,$root);$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$file=static function(string $name,int $size,string $header)use($root):array{
    $path=$root.'/'.$name;$stream=fopen($path,'wb');fwrite($stream,$header);ftruncate($stream,$size);fclose($stream);clearstatcache(true,$path);
    return ['name'=>$name,'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>1]; // Never trust the caller's size.
};
$expect=static function(array $file,string $message)use($media,$check):void{
    try{$media->store($file,1,null,null,false);throw new LogicException('Unexpected store');}
    catch(RuntimeException $error){$check(str_contains($error->getMessage(),$message),$message);}
};
try {
    $mp4="\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2";
    $expect($file('video.mp4',MediaLibrary::MAX_VIDEO,$mp4),'validated-before-database');
    $expect($file('video.mp4',MediaLibrary::MAX_VIDEO+1,$mp4),'80 MiB');
    $expect($file('document.pdf',20971520,'%PDF-1.7'),'validated-before-database');
    $expect($file('document.pdf',20971521,'%PDF-1.7'),'20 MiB');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $expect($file('image.png',8388608,$png),'validated-before-database');
    $expect($file('image.png',8388609,$png),'8 MiB');
    $expect(['error'=>UPLOAD_ERR_INI_SIZE],'server upload limit');
    $expect(['error'=>UPLOAD_ERR_PARTIAL],'completely uploaded');
    $batch=$file('batch.pdf',MediaLibrary::MAX_BATCH,'%PDF-1.7');MediaLibrary::validateBatch([$batch]);$check(true,'Exact 95 MiB batch accepted');
    $batch=$file('batch.pdf',MediaLibrary::MAX_BATCH+1,'%PDF-1.7');
    try{MediaLibrary::validateBatch([$batch]);throw new LogicException('Large batch accepted');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'95 MiB'),'Oversize batch rejected before writes');}
    try{MediaLibrary::validateBatch(array_fill(0,11,['error'=>UPLOAD_ERR_NO_FILE]));throw new LogicException('Too many accepted');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'10'),'Eleven files rejected, not silently truncated');}
    $check(MediaLibrary::MAX_BATCH<MediaLibrary::MAX_REQUEST&&MediaLibrary::MAX_VIDEO<MediaLibrary::MAX_BATCH,'Multipart overhead allowance');
    $check(App\Core\SeoMeta::sanitizeGlobal([],[])['organization_type']==='Organization','Missing SEO type defaults to generic organization');
    $check(App\Core\SeoMeta::sanitizeGlobal(['organization_type'=>'School'],[])['organization_type']==='School','Explicit school identity preserved');
    $stored=['localized'=>['en'=>['copyright_line_1'=>'{{school}} / {{site_name}}','footer_description'=>'Saved school content']]];
    $resolved=App\Core\SiteChrome::resolve($stored,'en','en',['name'=>'Example Team']);
    $check($resolved['copyright_line_1']==='Example Team / Example Team'&&$resolved['footer_description']==='Saved school content','Legacy tokens and saved content preserved');
    foreach(['en','km','zh']as$locale){$r=App\Core\SiteChrome::resolve([],$locale,'en',[]);$check(!str_contains($r['copyright_line_1'],'{{')&&!str_contains($r['copyright_line_1'],'School'),'Generic footer '.$locale);}
    echo "$count media/default checks passed; validation boundary only, no database writes.\n";
} finally {
    foreach(new DirectoryIterator($root)as$item)if($item->isFile())unlink($item->getPathname());rmdir($root);
}
