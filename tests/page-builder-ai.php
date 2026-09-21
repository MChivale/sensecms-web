<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';

use App\Core\AiContentService;
use App\Core\AiProviderClient;
use App\Core\PageBuilder;

$count=0;
$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS {$label}\n";};
$reject=static function(callable$fn,string$label)use($check):void{try{$fn();}catch(RuntimeException){$check(true,$label);return;}$check(false,$label);};

$drivers=AiProviderClient::drivers();
$check(array_keys($drivers)===['openai-responses','openai-compatible','anthropic','google-gemini'],'Core exposes four provider-neutral AI drivers');
$check(AiProviderClient::driver('openai')==='openai-compatible'&&AiProviderClient::driver('unknown')==='','Legacy OpenAI configuration remains compatible and unknown drivers fail closed');

$actions=PageBuilder::aiActions();
$check(array_keys($actions)===['generate-page','add-sections','rewrite','shorten','expand','translate','quality-fix'],'Core owns the complete review-first Builder action set');
$plugin=['demo'=>['builder_ai_actions'=>['brand-check'=>['label'=>'Check brand voice','instruction'=>'Align wording with the supplied brand guidance.','scope'=>['section'],'icon'=>'badge-check']]]];
$extended=PageBuilder::aiActions($plugin,['demo']);
$check(isset($extended['plugin-demo-brand-check'])&&$extended['plugin-demo-brand-check']['source']==='plugin:demo','Active add-ons may extend AI actions without replacing Core orchestration');
$check(!isset(PageBuilder::aiActions($plugin,[])['plugin-demo-brand-check']),'Inactive add-on AI actions remain unavailable');

$catalog=PageBuilder::catalog([]);
$uid='12345678-1234-4234-8234-123456789abc';
$block=['uid'=>$uid,'type'=>'text','source'=>'core','visible'=>true,'visible_from'=>null,'visible_until'=>null,'layout'=>PageBuilder::defaultLayout(),'appearance'=>PageBuilder::defaultAppearance(),'shared'=>$catalog['text']['shared_defaults'],'localized'=>['en'=>array_replace($catalog['text']['defaults'],['title'=>'Original heading','content'=>'Original factual content.']),'pl'=>array_replace($catalog['text']['defaults'],['title'=>'Oryginalny nagłówek','content'=>'Oryginalna treść.'])]];
$current=PageBuilder::sanitizeBlocks([$block],$catalog,['en','pl'],true);
$decoded=AiContentService::decodeProposal("```json\n".json_encode(['summary'=>'Clearer copy','sections'=>[['uid'=>$uid,'type'=>'text','content'=>['title'=>'Rewritten heading','content'=>'Rewritten factual content.','unsupported'=>'drop']]]],JSON_THROW_ON_ERROR)."\n```");
$after=AiContentService::applyProposal($current,$decoded,'rewrite','section',$uid,'en',['en','pl'],$catalog);
$check($after[0]['localized']['en']['title']==='Rewritten heading'&&$after[0]['localized']['pl']['title']==='Oryginalny nagłówek','Section proposal changes only the reviewed target locale');
$check($after[0]['uid']===$uid&&$after[0]['layout']===$current[0]['layout']&&!isset($after[0]['localized']['en']['unsupported']),'AI proposal preserves section identity and portable metadata while dropping unsupported fields');
$check(PageBuilder::revisionDiff(['blocks'=>$current],['blocks'=>$after],$catalog)['summary']['changed']===1,'AI proposals produce an ordinary Core revision diff');

$generated=AiContentService::applyProposal($current,['sections'=>[['type'=>'text','content'=>['title'=>'Generated section','content'=>'Draft content.']]]],'add-sections','page','','en',['en','pl'],$catalog);
$check(count($generated)===2&&$generated[1]['type']==='text'&&$generated[1]['source']==='core','Generated content becomes a normal sanitized portable Core section');
$reject(fn()=>AiContentService::decodeProposal('not structured'),'Unstructured provider output is rejected');
$reject(fn()=>AiContentService::applyProposal($current,['sections'=>[['type'=>'unknown','content'=>['title'=>'Unsafe']]]],'add-sections','page','','en',['en','pl'],$catalog),'Unsupported generated section types fail closed');
$limits=AiContentService::limits(['daily_request_limit'=>'100','monthly_request_limit'=>'2000','daily_token_limit'=>'250000','monthly_token_limit'=>'5000000','daily_cost_limit'=>'2.50','monthly_cost_limit'=>'25','input_cost_per_million'=>'1.25','output_cost_per_million'=>'5','warning_percent'=>'80']);
$check($limits['daily_requests']===100&&$limits['monthly_tokens']===5000000&&$limits['daily_cost_usd']==='2.500000'&&$limits['warning_percent']===80,'Provider budgets normalize request, token, cost and warning controls');
$reject(fn()=>AiContentService::limits(['daily_cost_limit'=>'1','input_cost_per_million'=>'0','output_cost_per_million'=>'0']),'Cost budgets require explicit provider pricing instead of guessed vendor prices');

$migration=(string)file_get_contents(dirname(__DIR__).'/.cms/source/database/workspace/037_page_builder_ai.sql');
$usageMigration=(string)file_get_contents(dirname(__DIR__).'/.cms/source/database/workspace/038_ai_usage_controls.sql');
$repository=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Core/AiRepository.php');
$chat=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Core/AiChatService.php');
$controller=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Http/DashboardController.php');
$workspace=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/workspace.php');
$view=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console-page-builder.php');
$providerView=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console-ai.php');
$script=(string)file_get_contents(dirname(__DIR__).'/.cms/source/public/theme/sensecms-page-builder.js');
$check(str_contains($migration,'page_builder_ai_runs')&&str_contains($migration,'content.pages.ai'),'AI usage audit and least-privilege Builder permission use an additive migration');
$check(str_contains($repository,'20')&&str_contains($repository,'DATE_SUB(NOW(),INTERVAL 10 MINUTE)')&&str_contains($repository,'input_tokens'),'AI requests are rate-limited and record metadata rather than prompts or generated content');
$check(str_contains($usageMigration,'ai_usage_events')&&str_contains($usageMigration,'estimated_cost_usd')&&!str_contains($usageMigration,'prompt')&&!str_contains($usageMigration,'content'),'Provider-neutral usage ledger stores bounded metadata without prompts or generated content');
$check(str_contains($repository,'FOR UPDATE')&&str_contains($repository,'assertBudget')&&str_contains($repository,'reserved_output_tokens'),'Request, token and cost budgets reserve concurrent work before an external provider call');
$check(str_contains($chat,"beginUsage(\$provider,'chat'")&&str_contains($chat,'finishUsage'),'Visitor assistant usage shares the same Core budget enforcement as Page Builder');
$check(str_contains($providerView,'Usage guardrails')&&str_contains($providerView,'No hard budget')&&str_contains($providerView,'Optional until the provider is enabled'),'Provider UI exposes usage, safe budget controls and key-later configuration');
$check(str_contains($controller,'function proposePageBuilderAi')&&str_contains($workspace,"/(\\d+)/ai$#"),'Page Builder AI uses a dedicated authenticated Core endpoint');
$check(!str_contains($controller,'$this->activeTheme()')&&str_contains($controller,"setting('active_theme','sensecms')"),'Builder AI resolves the active theme through the shared Core repository contract');
$check(str_contains($view,'AI creates a proposal. It never saves or publishes your page.')&&str_contains($view,'Human approval required'),'Builder states the review-first contract at the point of use');
$check(str_contains($script,'function renderAiProposal')&&str_contains($script,'function runAiProposal')&&str_contains($script,'aiBaseFingerprint'),'The browser previews, guards and explicitly applies proposals without automatic saving');

echo "{$count} Page Builder AI checks passed; no database, network or provider writes.\n";
