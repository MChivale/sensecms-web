<?php
declare(strict_types=1);

require dirname(__DIR__).'/.cms/source/bootstrap.php';
require dirname(__DIR__).'/.src/TelegramBrokerService.php';
require dirname(__DIR__).'/.src/TelegramWebhook.php';

use SenseCMS\Website\TelegramBrokerService;

$root=sys_get_temp_dir().'/sensecms-telegram-test-'.bin2hex(random_bytes(8));
if (!mkdir($root,0700)) throw new RuntimeException('Cannot create private test directory.');
$key=random_bytes(32);$count=0;$sent=[];$licenseCalls=[];$fail=false;$wrongProduct=false;$expired=false;
$cfg=['bot_token'=>'123456:'.str_repeat('a',35),'bot_username'=>'SenseCMSFixtureBot','webhook_secret'=>str_repeat('w',40)];
$http=static function(string $url,array $headers,string $payload)use(&$sent,&$licenseCalls,&$fail,&$wrongProduct,&$expired):array{
    if($url==='https://www.chivale.com/license/'){
        parse_str($payload,$form);$licenseCalls[]=$form;
        if($form['LicenseKey']!==str_repeat('K',32))return[403,'{"error":true}'];
        return[200,json_encode(['error'=>false,'data'=>['product'=>['name'=>'Sense CMS','model'=>$wrongProduct?'Other product':'Sense CMS System','version'=>'99.0'],'valid_from'=>null,'valid_to'=>$expired?'2000-01-01 00:00:00':null]])];
    }
    if(!str_starts_with($url,'https://api.telegram.org/bot123456:'))throw new RuntimeException('Unexpected fixture destination.');
    $message=json_decode($payload,true);$sent[]=$message;
    if($fail)return[500,'secret-marker'];
    return[200,json_encode(['ok'=>true,'result'=>['message_id'=>count($sent),'chat'=>['id'=>(int)$message['chat_id'],'type'=>'private']]])];
};
$broker=new TelegramBrokerService($root,$key,$cfg,$http);
$server=['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('K',32),'HTTP_X_SENSECMS_DOMAIN'=>'https://One.example/'];
$other=array_replace($server,['HTTP_X_SENSECMS_DOMAIN'=>'https://two.example']);
$webhook=['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'=>$cfg['webhook_secret']];
$update=static fn(string $token,int $id=912345678):array=>['message'=>['text'=>'/start '.$token,'chat'=>['type'=>'private','id'=>$id],'from'=>['id'=>$id,'is_bot'=>false,'first_name'=>'QA Secret Name','username'=>'qa_fixture']]];
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$reject=static function(callable $fn,int $code,string $label)use($check):void{try{$fn();}catch(RuntimeException $e){$check($e->getCode()===$code&&!str_contains($e->getMessage(),'secret-marker'),$label);return;}throw new RuntimeException($label);};
try {
    $edge=['HTTPS'=>'on','HTTP_HOST'=>'www.sensecms.com','REQUEST_URI'=>'/api/telegram/v1/webhook','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json']+$webhook;
    $check(SenseCMS\Website\TelegramWebhook::response($edge,'{}',$broker)===[200,['ok'=>true]],'authenticated empty webhook accepted without message');
    foreach ([['HTTPS'=>'off'],['HTTP_HOST'=>'other.example'],['REQUEST_URI'=>'/api/telegram/v1/webhook?token=secret'],['REQUEST_METHOD'=>'GET'],['CONTENT_TYPE'=>'text/plain'],['CONTENT_LENGTH'=>65537],['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'=>'wrong']] as $idx=>$change) {
        $expected=[421,421,404,405,415,413,403][$idx];
        $check(SenseCMS\Website\TelegramWebhook::response(array_replace($edge,$change),'{}',$broker)===[ $expected,['ok'=>false]],'webhook edge rejects unsafe request '.$idx);
    }
    foreach (['null','[]','broken','{"message":'] as $idx=>$body) $check(SenseCMS\Website\TelegramWebhook::response($edge,$body,$broker)===[400,['ok'=>false]],'webhook edge rejects malformed object '.$idx);
    $check(SenseCMS\Website\TelegramWebhook::response($edge,str_repeat('x',65537),$broker)===[413,['ok'=>false]],'webhook body bounded without content length');
    $check(!$sent&&!$licenseCalls,'webhook does not invoke licensing or send unsolicited messages');
    $api=array_replace($edge,$server,['REQUEST_URI'=>'/api/telegram/v1/connect/status']);
    $check(SenseCMS\Website\TelegramWebhook::response($api,'{"user_id":7}',$broker)[0]===200,'broker HTTP route accepts valid installation licence');
    $check(SenseCMS\Website\TelegramWebhook::response(array_replace($api,['HTTP_AUTHORIZATION'=>'']),'{}',$broker)[0]===401,'broker HTTP route requires installation authentication');
    $check(SenseCMS\Website\TelegramWebhook::response(array_replace($api,['REQUEST_URI'=>'/api/telegram/v1/unknown']),'{}',$broker)[0]===404,'broker HTTP route allowlist rejects unknown operations');
    $check($broker->ready(),'configured fixture ready');
    $unconfigured=new TelegramBrokerService($root,$key,[],$http);
    $reject(fn()=>$unconfigured->handle('start',$server,['user_id'=>7]),503,'unconfigured service unavailable');
    $reject(fn()=>$broker->handle('bogus',$server,[]),404,'unknown action refused');
    $reject(fn()=>$broker->handle('status',[],['user_id'=>7]),401,'missing identity refused');
    $reject(fn()=>$broker->handle('status',array_replace($server,['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('X',32)]),['user_id'=>7]),403,'invalid CMS licence refused');
    $wrongProduct=true;$reject(fn()=>$broker->handle('status',$server,['user_id'=>7]),403,'other product refused');$wrongProduct=false;
    $expired=true;$reject(fn()=>$broker->handle('status',$server,['user_id'=>7]),403,'expired licence refused');$expired=false;
    $a=$broker->handle('start',$server,['user_id'=>7]);
    $check(strlen($a['request_token'])===43&&$a['expires_in']===600,'short-lived random connection token');
    $check(end($licenseCalls)['DomainUrl']==='https://one.example'&&end($licenseCalls)['ProductVersion']==='1.0','canonical tenant identity and fixed licensing protocol');
    $reject(fn()=>$broker->handle('status',$other,['user_id'=>7,'request_token'=>$a['request_token']]),403,'cross-installation token access refused');
    $reject(fn()=>$broker->handle('status',$server,['user_id'=>8,'request_token'=>$a['request_token']]),403,'cross-user token access refused');
    $reject(fn()=>$broker->handle('webhook',[], $update($a['request_token'])),403,'webhook secret required');
    $reject(fn()=>$broker->handle('webhook',$webhook,['message'=>'invalid']),422,'invalid webhook shape refused');
    $reject(fn()=>$broker->handle('webhook',$webhook,['message'=>['chat'=>'invalid']]),422,'invalid nested account shape refused');
    $reject(fn()=>$broker->handle('webhook',$webhook,['data'=>str_repeat('x',65537)]),413,'oversized request refused');
    $b=$broker->handle('start',$server,['user_id'=>7]);
    $broker->handle('webhook',$webhook,$update($a['request_token']));
    $check(!$broker->handle('status',$server,['user_id'=>7])['connected'],'superseded token cannot bind');
    $group=$update($b['request_token']);$group['message']['chat']['type']='group';$broker->handle('webhook',$webhook,$group);
    $check(!$broker->handle('status',$server,['user_id'=>7])['connected'],'group messages cannot bind');
    $bad=$update($b['request_token']);$bad['message']['from']['id']=1;
    $reject(fn()=>$broker->handle('webhook',$webhook,$bad),422,'private sender must match chat');
    $broker->handle('webhook',$webhook,$update($b['request_token']));
    $state=$broker->handle('status',$server,['user_id'=>7,'request_token'=>$b['request_token']]);
    $check($state['connected']&&$state['request_status']==='connected'&&!isset($state['account']['chat_id']),'connected status exposes no private chat ID');
    $check(!$sent,'binding does not send an unsolicited message');
    $check($broker->handle('recipients',$server,[])['user_ids']===[7],'recipients limited to installation');
    $check($broker->handle('recipients',$other,[])['user_ids']===[],'other installation has no recipients');
    $broker->handle('webhook',$webhook,$update($b['request_token'],999999999));
    $event=['user_id'=>7,'event_key'=>hash('sha256','event-1'),'title'=>'QA title','body'=>'QA body'];
    $check($broker->handle('deliver',$server,$event)['sent'],'confirmed delivery recorded');
    $check($sent[0]['chat_id']==='912345678','replayed token cannot rebind recipient');
    $check($broker->handle('deliver',$server,$event)['duplicate']&&count($sent)===1,'same event sent only once');
    $reject(fn()=>$broker->handle('deliver',$server,array_replace($event,['body'=>'changed'])),409,'same event with different content refused');
    $fail=true;$event['event_key']=hash('sha256','event-2');
    $reject(fn()=>$broker->handle('deliver',$server,$event),502,'ambiguous response retained as failure');$fail=false;
    $reject(fn()=>$broker->handle('deliver',$server,$event),409,'ambiguous delivery not repeated');
    $check(count($sent)===2,'no duplicate outbound request after ambiguity');
    $pending=$broker->handle('start',$server,['user_id'=>7]);$broker->handle('disconnect',$server,['user_id'=>7]);
    $broker->handle('webhook',$webhook,$update($pending['request_token']));
    $check(!$broker->handle('status',$server,['user_id'=>7])['connected'],'disconnect invalidates pending tokens');
    $reject(fn()=>$broker->handle('deliver',$server,$event),404,'disconnected recipient cannot receive');
    $files=glob($root.'/storage/*.json');$raw=implode('',array_map('file_get_contents',$files));
    $check(!str_contains($raw,'QA Secret Name')&&!str_contains($raw,'912345678')&&!str_contains($raw,$b['request_token'])&&!str_contains($raw,str_repeat('K',32)),'private state encrypted without licence or token plaintext');
    $lock=fopen($root.'/broker.lock','c+b');flock($lock,LOCK_EX);
    try{$reject(fn()=>$broker->handle('status',$server,['user_id'=>7]),503,'concurrent state operation fails safely');}finally{flock($lock,LOCK_UN);fclose($lock);}
    for($i=0;$i<12;$i++)$broker->handle('start',$server,['user_id'=>9]);
    $reject(fn()=>$broker->handle('start',$server,['user_id'=>9]),429,'per-user start rate limit enforced');
    $write=new ReflectionMethod($broker,'write');
    $write->invoke($broker,'starts','expired-fixture',['expires'=>time()-90000]);
    $write->invoke($broker,'pending','expired-fixture',['expires'=>time()-90000]);
    $write->invoke($broker,'rate','expired-fixture',['since'=>time()-90000,'count'=>12]);
    $write->invoke($broker,'starts','live-fixture',['expires'=>time()+600]);
    $write->invoke($broker,'deliveries','tombstone-fixture',['status'=>'unknown','digest'=>'fixed','created_at'=>time()-90000]);
    $protected=[];foreach(array_merge(glob($root.'/storage/bindings-*.json'),glob($root.'/storage/deliveries-*.json')) as $f)$protected[$f]=hash_file('sha256',$f);
    $sends=count($sent);$clean=$broker->cleanup();
    $check($clean['removed']===3&&$clean['invalid']===0,'cleanup removes only grace-expired temporary records');
    foreach($protected as $f=>$hash)$check(is_file($f)&&hash_file('sha256',$f)===$hash,'cleanup preserves binding or delivery tombstone');
    $check($broker->cleanup()['removed']===0,'cleanup is idempotent');
    $check(count($sent)===$sends,'cleanup never sends Telegram messages');
    echo "$count broker checks passed; isolated transport only.\n";
} finally {
    // Only files generated in this uniquely created fixture directory are removed.
    foreach(glob($root.'/storage/*.json')?:[] as $file)unlink($file);
    if(is_dir($root.'/storage'))rmdir($root.'/storage');
    if(is_file($root.'/broker.lock'))unlink($root.'/broker.lock');
    rmdir($root);
}
