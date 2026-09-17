<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Http\DashboardController;
$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$class=new ReflectionClass(DashboardController::class);
$defaults=$class->getMethod('defaultPopup')->invoke($class->newInstanceWithoutConstructor());
$check(!$class->hasMethod('defaultHome'),'Unused home preset removed');
$check($defaults['enabled']===false,'New popup is disabled');
foreach(['eyebrow','title','text','cta_label','cta_url','image_url','start_at','end_at']as$key)$check($defaults[$key]==='','No fabricated campaign '.$key);
$check($defaults['facts']===[]&&$defaults['localized']===[],'No injected highlights or translations');
$check([$defaults['width'],$defaults['image_width'],$defaults['height'],$defaults['frequency']] === [1040,390,645,'session'],'Dimensions and frequency preserved');
$check([$defaults['background_color'],$defaults['accent_color'],$defaults['text_color'],$defaults['icon_color']] === ['#123b4a','#e96c4a','#ffffff','#f4c95d'],'Existing design tokens preserved');
foreach([[],[['icon'=>'heart','title'=>'Saved school benefit','text'=>'Explicit editorial content']]]as$facts){
    $saved=['enabled'=>true,'title'=>'Saved admissions campaign','image_url'=>'/media/school.webp','facts'=>$facts,'localized'=>['km'=>['title'=>'រក្សាទុក','image_url'=>''],'zh'=>['title'=>'已保存']],'custom_key'=>['preserve'=>true]];
    $merged=array_replace_recursive($defaults,$saved);
    foreach($saved as$key=>$value)$check($merged[$key]===$value,'Saved value remains exact '.$key);
    $check(!isset($merged['localized']['en']),'Missing translation falls back to saved root content');
}
echo "PASS $count popup default checks; no database or network writes.\n";
