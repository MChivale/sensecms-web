<?php
declare(strict_types=1);

require dirname(__DIR__).'/.cms/source/bootstrap.php';
require dirname(__DIR__).'/.src/TelegramBrokerService.php';
require dirname(__DIR__).'/.src/TelegramWebhook.php';

use SenseCMS\Website\TelegramBrokerService;

$root=sys_get_temp_dir().'/sensecms-telegram-channels-'.bin2hex(random_bytes(8));if(!mkdir($root,0700))throw new RuntimeException('Cannot create private test directory.');$checks=0;$calls=[];$sendFail=false;$memberStatus='administrator';$canPost=true;$chatType='channel';$channelId='-1001234567890';
$cfg=['bot_token'=>'123456:'.str_repeat('a',35),'bot_username'=>'SenseCMSBot','webhook_secret'=>str_repeat('w',40)];
$http=static function(string$url,array$headers,string$payload)use(&$calls,&$sendFail,&$memberStatus,&$canPost,&$chatType,&$channelId):array{
    if($url==='https://www.chivale.com/license/')return[200,json_encode(['error'=>false,'data'=>['product'=>['name'=>'Sense CMS','model'=>'Sense CMS System'],'valid_from'=>null,'valid_to'=>null]])];
    $method=basename($url);$data=json_decode($payload,true,16,JSON_THROW_ON_ERROR);$calls[]=[$method,$data];
    if($method==='getChat')return[200,json_encode(['ok'=>true,'result'=>['id'=>(int)$channelId,'type'=>$chatType,'title'=>'Sense CMS News','username'=>'sensecmsnews']])];
    if($method==='getChatMember')return[200,json_encode(['ok'=>true,'result'=>['user'=>['id'=>123456,'is_bot'=>true],'status'=>$memberStatus,'can_post_messages'=>$canPost]])];
    if($method==='sendMessage'&&$sendFail)return[500,'secret-upstream-body'];
    if($method==='sendMessage')return[200,json_encode(['ok'=>true,'result'=>['message_id'=>88,'chat'=>['id'=>(int)$channelId,'type'=>'channel']]])];
    throw new RuntimeException('Unexpected fixture destination.');
};
$broker=new TelegramBrokerService($root,random_bytes(32),$cfg,$http);$server=['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('K',32),'HTTP_X_SENSECMS_DOMAIN'=>'https://www.sensecms.com'];
$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo"PASS $label\n";};
$reject=static function(callable$fn,int$code,string$label)use($check):void{try{$fn();}catch(RuntimeException$error){$check($error->getCode()===$code&&!str_contains($error->getMessage(),'secret-upstream-body'),$label);return;}throw new RuntimeException($label);};
try{
    $verified=$broker->handle('channel-verify',$server,['channel'=>'https://t.me/sensecmsnews']);$check($verified['channel_id']===$channelId&&$verified['title']==='Sense CMS News'&&$verified['username']==='sensecmsnews'&&$verified['bot_username']==='SenseCMSBot','Public channel identity and official bot permission verified');
    $edge=['HTTPS'=>'on','HTTP_HOST'=>'www.sensecms.com','REQUEST_URI'=>'/api/telegram/v1/channels/verify','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json']+$server;$response=SenseCMS\Website\TelegramWebhook::response($edge,'{"channel":"@sensecmsnews"}',$broker);$check($response[0]===200&&$response[1]['channel_id']===$channelId,'HTTP allowlist exposes the authenticated channel verification route');
    $publish=['channel_id'=>$channelId,'event_key'=>hash('sha256','delivery-1'),'text'=>"Reviewed story\n\nhttps://www.sensecms.com/en/posts/story"];$sent=$broker->handle('channel-publish',$server,$publish);$check($sent['message_id']===88&&$sent['duplicate']===false,'Channel publication confirmed by Telegram');
    $sendCount=count(array_filter($calls,static fn(array$item):bool=>$item[0]==='sendMessage'));$duplicate=$broker->handle('channel-publish',$server,$publish);$check($duplicate['duplicate']===true&&count(array_filter($calls,static fn(array$item):bool=>$item[0]==='sendMessage'))===$sendCount,'Same delivery key sends only once');
    $reject(fn()=>$broker->handle('channel-publish',$server,array_replace($publish,['text'=>'Changed content'])),409,'Same delivery key with different content refused');
    $memberStatus='member';$reject(fn()=>$broker->handle('channel-verify',$server,['channel'=>'@sensecmsnews']),422,'Bot must be a channel administrator');$memberStatus='administrator';$canPost=false;$reject(fn()=>$broker->handle('channel-verify',$server,['channel'=>'@sensecmsnews']),422,'Bot must have permission to post messages');$canPost=true;
    $chatType='group';$reject(fn()=>$broker->handle('channel-verify',$server,['channel'=>'@sensecmsnews']),422,'Groups are not accepted as channels');$chatType='channel';
    foreach(['http://t.me/sensecmsnews','https://t.me/sensecmsnews?x=1','@bad','-1001']as$unsafe)$reject(fn()=>$broker->handle('channel-verify',$server,['channel'=>$unsafe]),422,'Unsafe or malformed channel reference refused');
    $sendFail=true;$publish['event_key']=hash('sha256','delivery-ambiguous');$reject(fn()=>$broker->handle('channel-publish',$server,$publish),502,'Ambiguous Telegram delivery recorded without upstream disclosure');$sendFail=false;$reject(fn()=>$broker->handle('channel-publish',$server,$publish),409,'Ambiguous delivery is not repeated');
    $raw=implode('',array_map('file_get_contents',glob($root.'/storage/*.json')?:[]));$check(!str_contains($raw,$channelId)&&!str_contains($raw,'Sense CMS News')&&!str_contains($raw,str_repeat('K',32)),'Channel delivery state is encrypted without licence or identity plaintext');
    echo"$checks Telegram Channels broker checks passed; isolated transport only.\n";
}finally{foreach(glob($root.'/storage/*.json')?:[]as$file)unlink($file);if(is_dir($root.'/storage'))rmdir($root.'/storage');if(is_file($root.'/broker.lock'))unlink($root.'/broker.lock');rmdir($root);}
