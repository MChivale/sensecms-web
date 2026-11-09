<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\{BuilderDraftMerge,BuilderMediaItems,PageBuilder};

function check(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function row(int $n): array {
    return ['title'=>'Picture '.$n,'image'=>'/media/'.$n.'.jpg','alt'=>'Alt '.$n,
        '_item_id'=>sprintf('abcd0000-0000-4000-8000-%012d',$n)];
}
function block(string $type,array $rows): array {
    return ['uid'=>'abcd0000-0000-4000-8000-000000000001','type'=>$type,'global_section_id'=>null,
        'visible'=>true,'localized'=>['en'=>['title'=>'Test',BuilderMediaItems::field($type)=>$rows]]];
}
foreach (['gallery'=>'items','hero-slider'=>'slides'] as $type=>$field) {
    $rows=[row(1),row(2),row(3)];$base=block($type,$rows);$mine=$base;$live=$base;
    $mine['localized']['en'][$field][0]['alt']='Mine';
    $live['localized']['en'][$field]=[$rows[2],$rows[0],$rows[1],row(4)];
    $live['localized']['en'][$field][2]['title']='Theirs';
    $merge=new BuilderDraftMerge();
    $out=$merge->blocks([$base],[$mine],[$live])[0]['localized']['en'][$field];
    check(!$merge->unresolved && $out[1]['alt']==='Mine' && $out[2]['title']==='Theirs' && count($out)===4,'Independent edits and move');
    $live['localized']['en'][$field][1]['alt']='Conflict';
    $merge=new BuilderDraftMerge();$merge->blocks([$base],[$mine],[$live]);$diff=$merge->conflicts[0];
    check($merge->unresolved===1 && $diff['media']['number']===2 && $diff['mine']==='Mine' && $diff['live']==='Conflict','Specific moved item');
    $merge=new BuilderDraftMerge([$diff['key']=>['text'=>'Joint']]);
    check($merge->blocks([$base],[$mine],[$live])[0]['localized']['en'][$field][1]['alt']==='Joint','Custom choice applies only to that item');
    $many=array_map('row',range(1,9));$base=block($type,$many);$mine=block($type,[...$many,row(10)]);$live=block($type,[...$many,row(11)]);
    $merge=new BuilderDraftMerge();$merge->blocks([$base],[$mine],[$live]);
    check($merge->unresolved===1 && $merge->conflicts[0]['media']['reason']==='limit','Merging additions must not truncate either list');
    $legacy=array_map(fn($r)=>array_diff_key($r,['_item_id'=>true]),$rows);
    $mine=$legacy;$mine[0]['alt']='Legacy A';$live=BuilderMediaItems::identified($legacy);$live[1]['alt']='New B';
    $merge=new BuilderDraftMerge();
    $out=$merge->blocks([block($type,$legacy)],[block($type,$mine)],[block($type,$live)])[0]['localized']['en'][$field];
    check(!$merge->unresolved && $out[0]['alt']==='Legacy A' && $out[1]['alt']==='New B','Legacy unique media path');
    $base=block($type,[$legacy[0],$legacy[0]]);
    $merge=new BuilderDraftMerge();
    $merge->blocks([$base],[block($type,[$legacy[0]])],[block($type,[$live[0],array_replace($legacy[0],['title'=>'B edit'])])]);
    check($merge->unresolved===1 && $merge->conflicts[0]['media']['reason']==='identity','Indistinguishable old entries require a list choice');
    $catalog=PageBuilder::catalog([]);$sanitized=PageBuilder::sanitizeBlocks([block($type,$rows)],$catalog,['en'],true);
    check(array_column($sanitized[0]['localized']['en'][$field],'_item_id')===array_column($rows,'_item_id'),'Private snapshot retains each identifier');
    try { PageBuilder::sanitizeBlocks([block($type,[$rows[0],$rows[0]])],$catalog,['en'],true);throw new LogicException('Duplicate IDs accepted'); }
    catch (RuntimeException $e) {check(str_contains($e->getMessage(),'duplicate identifier'),'Wrong rejection reason');}
}
echo "Gallery and slider merge: independent edits, moves, item resolution, legacy matching, limit and identity validation passed.\n";
