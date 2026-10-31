<?php
declare(strict_types=1);

// Mock only the network boundary: production client, services and ledger execute normally.
namespace App\Core {
    function dns_get_record(string$host,int$type):array{return[['ip'=>'8.8.8.8']];}
    function curl_init(string$url):object{return(object)['options'=>[]];}
    function curl_setopt_array(object$handle,array$options):bool{$handle->options=$options;return true;}
    function curl_exec(object$handle):bool{$handle->options[CURLOPT_WRITEFUNCTION]($handle,json_encode($GLOBALS['ai_response'],JSON_THROW_ON_ERROR));return true;}
    function curl_getinfo(object$handle,int$option):int{return 200;}
    function curl_error(object$handle):string{return'';}
    function curl_close(object$handle):void{}
}

namespace {
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows')throw new \RuntimeException('Run on private Linux MariaDB QA.');
$root=dirname(__DIR__).'/.cms/source';require $root.'/bootstrap.php';
$connect=static fn(?string$name=null):\PDO=>new \PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4'.($name?';dbname='.$name:''),'root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
if(($argv[1]??'')==='--reserve'){
    $schema=$argv[2]??'';if(!preg_match('/^senseqa_usage_[a-f0-9]{12}$/D',$schema))exit(2);
    $repo=new \App\Core\AiRepository($connect($schema));$provider=$repo->providerById((int)($argv[3]??0));if(!$provider)exit(2);
    echo "READY\n";flush();if(trim((string)fgets(STDIN))!=='GO')exit(2);
    try{$id=$repo->beginUsage($provider,'chat',null,100,300,1);echo 'RESERVED '.$id."\n";}
    catch(\RuntimeException$error){if($error->getCode()!==429)throw$error;echo "LIMITED\n";}
    exit;
}
$db=$connect();$schema='senseqa_usage_'.bin2hex(random_bytes(6));$created=false;$checks=0;$children=[];
$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new \RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
try{
    $db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;$db->exec("USE `$schema`");
    foreach(explode(';',(string)file_get_contents($root.'/database/001_core.sql'))as$sql)if(trim($sql)!=='')$db->exec($sql);
    $db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,NOW())')->execute(['001_core',hash_file('sha256',$root.'/database/001_core.sql')]);
    (new \App\Installer\WorkspaceMigration($db,$root))->apply();
    $repo=new \App\Core\AiRepository($db);$secrets=new \App\Core\Secrets('isolated-test-only');
    $ids=[];
    foreach(['first','second']as$slug)$ids[]=$repo->saveProvider(['slug'=>$slug,'name'=>$slug,'driver'=>'openai-responses','base_url'=>'https://example.test/v1','default_model'=>'qa-model','api_key_encrypted'=>$secrets->encrypt('test-only'),'enabled'=>true,'priority'=>100,'options'=>['purposes'=>['chat','posts','builder'],'limits'=>['input_cost_per_million'=>2,'output_cost_per_million'=>12]]]);
    // Rehearse the additive upgrade with pre-existing rows in the old enum.
    $db->exec("ALTER TABLE ai_usage_events MODIFY purpose ENUM('builder','chat','verification') NOT NULL");
    foreach(['builder','chat','verification']as$purpose)$db->prepare("INSERT INTO ai_usage_events (provider_id,provider_slug,model,purpose,status,input_tokens,output_tokens,actual_cost_usd,created_at) VALUES (?,?,?,?,'completed',12,3,0.000060,NOW())")->execute([$ids[0],'first','qa-model',$purpose]);
    $before=$db->query('SELECT * FROM ai_usage_events ORDER BY id')->fetchAll();
    $db->exec((string)file_get_contents($root.'/database/workspace/044_ai_posts_usage.sql'));
    $check($db->query('SELECT * FROM ai_usage_events ORDER BY id')->fetchAll()===$before,'Adding Posts usage preserves every historical purpose, token count and cost');
    $db->exec('DELETE FROM ai_usage_events');
    foreach($ids as$id){
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--reserve',$schema,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new \RuntimeException('Cannot start concurrent QA worker');
        $children[]=[$process,$pipes];$check(trim((string)fgets($pipes[1]))==='READY','Concurrent reservation worker is ready');
    }
    foreach($children as[$process,$pipes]){fwrite($pipes[0],"GO\n");fclose($pipes[0]);}
    $results=[];foreach($children as[$process,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$check(proc_close($process)===0&&$stderr==='','Concurrent worker finishes without database errors');}$children=[];
    $check(count(array_filter($results,static fn($r)=>str_starts_with($r,'RESERVED ')))===1&&in_array('LIMITED',$results,true),'Two providers compete for one global chat slot; exactly one succeeds');
    $check((int)$db->query('SELECT COUNT(*) FROM ai_usage_events')->fetchColumn()===1,'Rejected concurrent reservation creates no usage event');
    $db->exec('DELETE FROM ai_usage_events');
    $provider=$repo->providerById($ids[0]);$service=new \App\Core\AiContentService($repo,$secrets);
    $GLOBALS['ai_response']=['output'=>[],'usage'=>['input_tokens'=>120,'output_tokens'=>35]];
    $failed=false;try{$service->verifyProvider($ids[0]);}catch(\App\Core\AiProviderException){$failed=true;}
    $last=static fn():array=>$db->query('SELECT * FROM ai_usage_events ORDER BY id DESC LIMIT 1')->fetch();$row=$last();
    $check($failed&&$row['status']==='failed'&&$row['error_code']==='empty_response','An empty response remains a failure rather than successful generation');
    $check((int)$row['input_tokens']===120&&(int)$row['output_tokens']===35&&abs((float)$row['actual_cost_usd']-.000660)<.0000001,'Failed verification preserves reported tokens and calculated cost');
    $summary=$repo->usageSummary($provider);$check($summary['today']['tokens']===155&&abs($summary['today']['cost']-.000660)<.0000001,'Failed but consumed usage counts toward provider budgets');
    $dashboard=$repo->dashboard();$check($dashboard['AI requests this month']===0&&$dashboard['AI tokens this month']===155,'Dashboard includes failed consumption without counting a successful answer');
    $limited=$provider;$limited['options']['limits']['daily_tokens']=155;$limitedOut=false;
    try{$repo->beginUsage($limited,'verification',null,4,16);}catch(\RuntimeException$error){$limitedOut=$error->getCode()===429;}
    $check($limitedOut,'Consumed tokens from an empty answer prevent overspending a token budget');
    $repo->finishUsage((int)$row['id'],true,['input'=>999,'output'=>999]);$check($last()===$row,'Settlement is idempotent and cannot erase a failure');
    $GLOBALS['ai_response']=['usage'=>[]];try{$service->verifyProvider($ids[0]);}catch(\App\Core\AiProviderException){}
    $row=$last();$check($row['input_tokens']===null&&$row['actual_cost_usd']===null,'Unreported consumption stays unknown, not a false zero');
    $GLOBALS['ai_response']=['output_text'=>'not JSON','usage'=>['input_tokens'=>80,'output_tokens'=>20]];$failed=false;
    try{$service->proposePost(0,['provider'=>'first','action'=>'generate-draft','instructions'=>'Write a factual draft.'],[['locale'=>'en','is_default'=>true]]);}catch(\RuntimeException){$failed=true;}
    $row=$last();$check($failed&&$row['status']==='failed'&&(int)$row['input_tokens']===80&&(int)$row['output_tokens']===20,'Rejected editorial output retains the usage already consumed');
    $GLOBALS['ai_response']=['output_text'=>json_encode(['fields'=>['title'=>'Reviewed draft','content'=>'<p>A factual draft for review.</p>']]),'usage'=>['input_tokens'=>85,'output_tokens'=>22]];
    $proposal=$service->proposePost(0,['provider'=>'first','action'=>'generate-draft','instructions'=>'Write a factual draft.'],[['locale'=>'en','is_default'=>true]]);$row=$last();
    $check($proposal['after']['title']==='Reviewed draft'&&$row['purpose']==='posts'&&$row['status']==='completed'&&(int)$row['output_tokens']===22,'Posts AI returns a reviewable proposal and records its usage after the migration');
    $cms=new \App\Core\CmsRepository($db,new \App\Core\EventBus());$cms->saveSetting('live_chat_settings',['assistant'=>['enabled'=>true,'daily_requests'=>1]]);
    (new \App\Core\AiKnowledgeBase($db,$root))->saveManual(0,'Pricing','The annual price is USD 360.','en',true,0);
    $GLOBALS['ai_response']=['output'=>[],'usage'=>['input_tokens'=>70,'output_tokens'=>10]];
    $chat=new \App\Core\AiChatService($repo,$secrets,$cms);$result=$chat->reply('What is the price?','en','aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');$row=$last();
    $check($result['handoff']&&$row['purpose']==='chat'&&$row['status']==='failed'&&(int)$row['input_tokens']===70,'Chat safely falls back while preserving consumption from an empty answer');
    $check($repo->usageCount('chat')===1,'A charged empty chat answer consumes its daily slot');
    $limitedOut=false;try{$repo->beginUsage($repo->providerById($ids[1]),'chat',null,4,16,1);}catch(\RuntimeException$error){$limitedOut=$error->getCode()===429;}
    $check($limitedOut,'A second provider cannot bypass the consumed daily chat cap');
    $GLOBALS['ai_response']=['output_text'=>'Sense CMS ready','usage'=>['input_tokens'=>10,'output_tokens'=>3]];
    $service->verifyProvider($ids[0]);$row=$last();
    $check($row['status']==='completed'&&(int)$row['input_tokens']===10&&(int)$row['output_tokens']===3,'Successful provider verification retains its normal settlement');
    $check(!$db->inTransaction(),'All reservation and failure paths release database transactions');
    echo "{$checks} isolated AI usage runtime checks passed.\n";
}finally{
    foreach($children as[$process,$pipes]){if(is_resource($process))proc_terminate($process);foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_close($process);}
    if($db->inTransaction())$db->rollBack();if($created)$db->exec("DROP DATABASE `$schema`");
}
}
