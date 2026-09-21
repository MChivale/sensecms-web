<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';

use App\Core\AiContentService;

$count=0;$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo"PASS {$label}\n";};$reject=static function(callable$fn,string$label)use($check):void{try{$fn();}catch(RuntimeException){$check(true,$label);return;}$check(false,$label);};

$actions=AiContentService::postActions();
$check(array_keys($actions)===['generate-draft','rewrite','shorten','expand','seo','translate'],'Core owns the complete review-first Posts action set');
$check($actions['rewrite']['scopes']===['content','selection']&&$actions['seo']['scopes']===['metadata'],'Posts actions expose only compatible editorial scopes');

$current=['title'=>'Original title','excerpt'=>'Original excerpt','content'=>'<p>Original factual article.</p>','seo_title'=>'Original SEO','seo_description'=>'Original description','tags'=>['News']];
$decoded=AiContentService::decodePostProposal("```json\n".json_encode(['summary'=>'Improved copy','fields'=>['title'=>'Clear title','excerpt'=>'Clear excerpt','content'=>'<h2>Heading</h2><p>Improved factual article.</p><script>alert(1)</script>','seo_title'=>'Ignored by rewrite','unsupported'=>'drop']],JSON_THROW_ON_ERROR)."\n```");
$proposal=AiContentService::applyPostProposal($current,$decoded,'rewrite','content');
$check($proposal['after']['title']==='Clear title'&&$proposal['after']['excerpt']==='Clear excerpt','rewrite proposes reviewed editorial fields');
$check(str_contains($proposal['after']['content'],'<h2>Heading</h2>')&&!str_contains($proposal['after']['content'],'script'),'generated article HTML passes the Core sanitizer');
$check($proposal['after']['seo_title']==='Original SEO'&&!isset($proposal['after']['unsupported']),'action field allow-list preserves unrelated metadata and drops unsupported output');

$seo=AiContentService::applyPostProposal($current,['summary'=>'SEO ready','fields'=>['seo_title'=>'Focused title','seo_description'=>'Focused description','tags'=>['CMS','CMS','Editorial','<b>Safe</b>']]],'seo','metadata');
$check($seo['after']['tags']===['CMS','Editorial','Safe']&&count($seo['changes'])===3,'SEO proposal normalizes unique safe tags and exposes explicit changes');
$selection=AiContentService::applyPostProposal($current,['summary'=>'Shorter passage','selection'=>'A concise factual passage.'],'shorten','selection');
$check($selection['after']['selection']==='A concise factual passage.'&&$selection['changes'][0]['field']==='selection','selected-text proposal remains isolated from the article document');
$reject(fn()=>AiContentService::decodePostProposal('not JSON'),'unstructured Posts provider output is rejected');
$reject(fn()=>AiContentService::applyPostProposal($current,['fields'=>['seo_title'=>'Not allowed']],'rewrite','content'),'proposal without applicable changes fails closed');

$migration=(string)file_get_contents(dirname(__DIR__).'/.cms/source/database/workspace/039_posts_ai.sql');
$repository=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Core/AiRepository.php');
$service=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Core/AiContentService.php');
$controller=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Http/DashboardController.php');
$workspace=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/workspace.php');
$view=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console-content-post-form.php');
$providerView=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console-ai.php');
$script=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-post-ai.js');
$console=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console.php');
$navigation=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-content-management.js');
$check(str_contains($migration,'content.posts.ai')&&str_contains($migration,"'owner','administrator','content-manager','editor'"),'Posts AI uses an additive least-privilege Core permission');
$check(str_contains($repository,"['builder','posts','chat','verification']")&&str_contains($repository,"purpose IN ('builder','posts')"),'Posts shares provider budgets and the bounded editorial request rate');
$check(str_contains($service,"beginUsage(\$provider,'posts'")&&str_contains($service,"in_array('posts',\$purposes,true)"),'Posts uses only providers explicitly enabled for this Core purpose');
$check(str_contains($controller,'function proposePostAi')&&str_contains($workspace,"'/content/posts/ai'")&&str_contains($controller,"assert('content.posts.ai'"),'Posts AI uses a dedicated authenticated facility-scoped endpoint');
$check(str_contains($providerView,'value="posts"')&&str_contains($view,'AI only prepares a proposal. It never saves or publishes the post.'),'provider configuration and editor state the separate review-first Posts contract');
$check(str_contains($script,'fingerprint')&&str_contains($script,'data-post-ai-apply')&&str_contains($view,'Apply to draft'),'browser guards stale proposals and requires explicit draft application');
$check(str_contains($console,'sensecms-post-ai.js')&&str_contains($navigation,'sensecms-post-ai.js')&&str_contains($view,'data-post-ai-review'),'Core loads and rehydrates the responsive review interface only in the post editor');

echo"{$count} Posts AI checks passed; no database, network or provider writes.\n";
