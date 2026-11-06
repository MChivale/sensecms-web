<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\BuilderDraftMerge;

function check(bool $value,string $message): void {
    if (!$value) throw new RuntimeException($message);
}
function section(string $uid): array {
    return ['uid'=>$uid,'type'=>'text','global_section_id'=>null,'global_version'=>0,
        'localized'=>['en'=>['title'=>$uid,'text'=>'Body'],'pl'=>['title'=>$uid]]];
}
$a=section('a'); $b=section('b'); $mine=$a; $live=$a;
$mine['localized']['en']['title']='My heading';
$live['localized']['en']['text']='Published body';
$merge=new BuilderDraftMerge();
$out=$merge->blocks([$a,$b],[$mine,$b],[$live,$b]);
check(!$merge->unresolved && $out[0]['localized']['en']===['title'=>'My heading','text'=>'Published body'],'Independent fields must survive');
$live['localized']['en']['title']='Their heading';
$merge=new BuilderDraftMerge(); $merge->blocks([$a],[$mine],[$live]);
check($merge->unresolved===1 && $merge->conflicts[0]['live']==='Their heading','Conflicting heading requires choice');
$key=$merge->conflicts[0]['key'];
foreach (['mine'=>'My heading','live'=>'Their heading'] as $choice=>$expected) {
    $merge=new BuilderDraftMerge([$key=>$choice]);
    $out=$merge->blocks([$a],[$mine],[$live]);
    check(!$merge->unresolved && $out[0]['localized']['en']['title']===$expected,'Choice honored');
}
$unknown=new BuilderDraftMerge();$unknown->blocks(null,[$mine],[$live,$b]);
check($unknown->unresolved>=2,'Legacy draft may not discard newer contents on an unknown base');
$deleted=new BuilderDraftMerge();$deleted->blocks([$a],[],[$live]);
check($deleted->unresolved===1 && $deleted->conflicts[0]['mine']===null,'Delete versus edit must require a decision');
$new=section('c');$added=new BuilderDraftMerge();
$out=$added->blocks([$a,$b],[$a,$b],[$new,$a,$b]);
check(!$added->unresolved && array_column($out,'uid')===['c','a','b'],'Keep newly published section at its published position');
$reordered=new BuilderDraftMerge();$reordered->blocks([$a,$b,$new],[$new,$a,$b],[$b,$a,$new]);
check($reordered->unresolved===1 && $reordered->conflicts[0]['path']===['order'],'Conflicting reorders require choice');
echo "Draft merge: independent fields, both content choices, unknown base, deletion, insertion and reordering passed.\n";
