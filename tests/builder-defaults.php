<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\PageBuilder;
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$reject=static function(callable $fn,string $label)use($check):void{try{$fn();}catch(RuntimeException){$check(true,$label);return;}$check(false,$label);};
$catalog=PageBuilder::catalog([]);
$check(array_keys($catalog)===['hero','hero-slider','gallery','admissions','contact-form','custom-html','story','values','programs','statistics','motion','news','cta','text','image-text'],'All 15 legacy component identities and order retained');
$check($catalog['admissions']['label']==='Process steps'&&$catalog['programs']['label']==='Services','Generic labels preserve legacy keys');
$fill=static function(array $data,array $fields)use(&$fill):array{
    foreach($fields as$field){$key=$field['key'];if($field['type']==='repeater'){$data[$key]=array_map(fn($row)=>$fill($row,$field['fields']),$data[$key]??[]);continue;}
        if(in_array($field['type'],['image','video'],true)&&($data[$key]??'')==='')$data[$key]='/media/legacy/school.'.($field['type']==='video'?'mp4':'webp');
    }return$data;
};
foreach($catalog as$type=>$definition){
    $check($definition['source']==='core','Core-owned definition '.$type);
    foreach(['en','km','zh']as$locale){
        $defaults=$locale==='en'?$definition['defaults']:($definition['localized_defaults'][$locale]??[]);
        $check((bool)$defaults,'Localized preset '.$type.'/'.$locale);
        $text=json_encode($defaults,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $check(!preg_match('/school|student|learner|admission|curriculum|learning|trilingual|学校|学生|学习|校园|招生|សាលា|សិស្ស|សិក្សា/iu',$text),'No inherited school copy '.$type.'/'.$locale);
        $check(!str_contains($text,'/theme-assets/'),'No dependency on old theme media '.$type.'/'.$locale);
    }
    $block=['uid'=>'12345678-1234-4234-8234-123456789abc','type'=>$type,'visible'=>true,'shared'=>$definition['shared_defaults'],'localized'=>[]];
    foreach(['en','km','zh']as$locale){
        $data=$fill($locale==='en'?$definition['defaults']:$definition['localized_defaults'][$locale],$definition['fields']);
        // Education remains a valid user choice, not a forced product default.
        if(isset($data['title']))$data['title']='Saved school admissions — '.$locale;
        $block['localized'][$locale]=$data;
    }
    $saved=PageBuilder::sanitizeBlocks([$block],$catalog,['en','km','zh'])[0];
    $check($saved['localized']===$block['localized']&&$saved['shared']===$block['shared'],'Saved values and media paths round-trip unchanged '.$type);
}
$check($catalog['gallery']['defaults']['items'][0]['image']==='','New galleries require the users own image');
foreach(['hero','hero-slider','gallery']as$type){
    $block=['uid'=>'12345678-1234-4234-8234-123456789abc','type'=>$type,'shared'=>$catalog[$type]['shared_defaults'],'localized'=>['en'=>$catalog[$type]['defaults']]];
    $reject(fn()=>PageBuilder::sanitizeBlocks([$block],$catalog,['en']),'Missing required media still rejected '.$type);
}
$empty=PageBuilder::catalog(['builder'=>['blocks'=>['qa-fields'=>['fields'=>[['key'=>'items','type'=>'repeater','min'=>1,'max'=>2,'fields'=>[['key'=>'image','type'=>'image','required'=>true]]]],'defaults'=>['items'=>[['image'=>'']]]]]]]);
$check(isset($empty['qa-fields']),'Blank nested required field permitted only in preset catalogue');
$reject(fn()=>PageBuilder::sanitizeBlocks([['uid'=>'12345678-1234-4234-8234-123456789abc','type'=>'qa-fields','localized'=>['en'=>['items'=>[['image'=>'']]]]]],$empty,['en']),'Theme-defined required fields remain enforced on save');
$reject(fn()=>PageBuilder::catalog(['builder'=>['blocks'=>['qa-bad'=>['fields'=>[['key'=>'image','type'=>'image']],'defaults'=>['image'=>'javascript:alert(1)']]]]]),'Unsafe preset URL still rejected');
$theme=json_decode(file_get_contents(dirname(__DIR__).'/.themes/sensecms/theme.json'),true,512,JSON_THROW_ON_ERROR);
$check(array_keys(PageBuilder::catalog($theme))===['contact-form','custom-html','text'],'Official theme supported-block boundary preserved');
echo "$count builder preset/compatibility checks passed; no database or network writes.\n";
