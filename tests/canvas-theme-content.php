<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
use App\Core\CanvasThemeContent as Canvas;
function check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$html='<main><section class="product-closing"><h2>Hello</h2><a href="/docs">Read <span aria-hidden="true">→</span></a><img src="/a.jpg" alt="A"><div class="sense-layout"><h2>Hello</h2></div></section></main><footer>outside</footer>';
$fields=[];$preview=Canvas::render($html,[],true,$fields);
check(count($fields)===3,'Only editorial elements allowed');
$keys=array_keys($fields);
$saved=[$keys[0]=>['text'=>'<Another>'], $keys[1]=>['url'=>'/new','text'=>'Open'], $keys[2]=>['image'=>'/b.png','alt'=>'Updated']];
$public=Canvas::render($html,$saved,false,$fields);
foreach(['&lt;Another&gt;','href="/new"','Open <span','src="/b.png" alt="Updated"','<div class="sense-layout"><h2>Hello</h2>','<footer>outside</footer>'] as $s)check(str_contains($public,$s),$s);
check(!str_contains($public,'data-canvas-'),'Public has no editing controls');
$private=[$keys[0]=>['text'=>'Private heading']];
$preview=Canvas::render($html,$saved,true,$fields,$private);
check(str_contains($preview,'>Private heading</h2>') && !str_contains($public,'Private heading'),'Only private preview includes the draft');
check(str_contains($preview,'data-canvas-revision="'.Canvas::revision($saved[$keys[0]]).'"'),'Draft still uses the published revision for conflict detection');
$scheduled=Canvas::render($html,[],false,$fields,$private);
check(str_contains($scheduled,'>Private heading</h2>') && !str_contains($scheduled,'data-canvas-'),'Scheduled private preview renders drafts without edit controls');
check(Canvas::render($html,[],false,$fields)===$html,'No overrides must return original bytes');
check(Canvas::render(str_replace('Hello','Welcome',$html),[$keys[0]=>['text'=>'Old']],false,$fields)===str_replace('Hello','Welcome',$html),'Template update must not silently retarget an edit');
foreach(['javascript:alert(1)','/\\bad.example','//bad.example',"/\t/bad.example"] as $bad){
    try{Canvas::validate(['url'=>'/old'],['url'=>$bad]);throw new LogicException('Unsafe address accepted');}catch(RuntimeException $e){check($e->getCode()===422,'Expected invalid address');}
}
echo "Canvas template editing: scope, public rendering, escaping, stale targets and URL validation passed.\n";
