<?php

declare(strict_types=1);

require dirname(__DIR__).'/.cms/source/bootstrap.php';
require dirname(__DIR__).'/.src/XBrokerService.php';
require dirname(__DIR__).'/.src/XOAuthEndpoint.php';

use SenseCMS\Website\XBrokerService;
use SenseCMS\Website\XOAuthEndpoint;

$root=sys_get_temp_dir().'/sensecms-x-test-'.bin2hex(random_bytes(8));if(!mkdir($root,0700))throw new RuntimeException('Cannot create private X fixture.');
$key=random_bytes(32);$access='access-'.str_repeat('a',40);$refresh='refresh-'.str_repeat('r',40);$nextAccess='access-'.str_repeat('b',40);$nextRefresh='refresh-'.str_repeat('s',40);$calls=[];$count=0;$minimalRefresh=false;
$cfg=['client_id'=>'fixture-client-id','client_secret'=>'fixture-client-secret-'.str_repeat('s',20)];
$http=static function(string$method,string$url,array$headers,string$payload)use(&$calls,&$minimalRefresh,$access,$refresh,$nextAccess,$nextRefresh):array{
    $calls[]=[$method,$url,$headers,$payload];
    if($url==='https://www.chivale.com/license/'){parse_str($payload,$form);if(($form['LicenseKey']??'')!==str_repeat('K',32))return[403,'{"error":true}'];return[200,json_encode(['error'=>false,'data'=>['product'=>['name'=>'Sense CMS','model'=>'Sense CMS System','version'=>'1.0'],'valid_from'=>null,'valid_to'=>null]])];}
    if($url==='https://api.x.com/2/oauth2/token'){parse_str($payload,$form);$rotated=($form['grant_type']??'')==='refresh_token';if($rotated&&$minimalRefresh)return[200,json_encode(['token_type'=>'bearer','expires_in'=>7200,'access_token'=>$nextAccess])];return[200,json_encode(['token_type'=>'bearer','expires_in'=>7200,'scope'=>'tweet.read tweet.write users.read offline.access','access_token'=>$rotated?$nextAccess:$access,'refresh_token'=>$rotated?$nextRefresh:$refresh])];}
    if($url==='https://api.x.com/2/users/me?user.fields=name%2Cusername')return[200,'{"data":{"id":"123456789012345","name":"Sense CMS","username":"SenseCMS"}}'];
    throw new RuntimeException('Unexpected fixture URL.');
};
$broker=new XBrokerService($root,$key,$cfg,$http);$server=['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('K',32),'HTTP_X_SENSECMS_DOMAIN'=>'https://tenant.example'];$other=array_replace($server,['HTTP_X_SENSECMS_DOMAIN'=>'https://other.example']);
$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo"PASS $label\n";};
$reject=static function(callable$fn,int$code,string$label)use($check):void{try{$fn();}catch(RuntimeException$error){$check($error->getCode()===$code&&!str_contains($error->getMessage(),'access-'),' '.$label);return;}throw new RuntimeException($label);};
$requestToken=static function(array$start):string{parse_str((string)parse_url($start['authorize_url'],PHP_URL_QUERY),$query);return(string)($query['request']??'');};
try{
    $check($broker->ready(),'configured X broker ready');$reject(fn()=>(new XBrokerService($root,$key,[],$http))->start($server,[]),503,'missing X app credentials fail closed');
    $reject(fn()=>$broker->start([],['return_url'=>'https://tenant.example/social-publishing/x/callback']),401,'installation licence required');$reject(fn()=>$broker->start($server,['return_url'=>'https://evil.example/social-publishing/x/callback']),422,'return URL bound to installation');
    $start=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/x/callback']);$request=$requestToken($start);$check(strlen($request)===43&&$start['expires_in']===600,'short-lived X authorization request');
    $authorize=$broker->authorize($request);parse_str((string)parse_url($authorize,PHP_URL_QUERY),$query);$check(str_starts_with($authorize,'https://x.com/i/oauth2/authorize?')&&$query['client_id']===$cfg['client_id']&&$query['redirect_uri']==='https://www.sensecms.com/api/social/x/v1/callback'&&$query['code_challenge_method']==='S256'&&$query['scope']==='tweet.read tweet.write users.read offline.access'&&strlen($query['code_challenge'])===43,'X authorization uses PKCE and minimum publishing scopes');
    $redirect=$broker->callback(['state'=>$request,'code'=>'fixture-code']);parse_str((string)parse_url($redirect,PHP_URL_QUERY),$claimQuery);$claim=(string)($claimQuery['claim']??'');$check(str_starts_with($redirect,'https://tenant.example/social-publishing/x/callback?claim=')&&strlen($claim)===43,'X callback returns opaque installation claim');
    $reject(fn()=>$broker->callback(['state'=>$request,'code'=>'fixture-code']),410,'X OAuth state is single-use');$reject(fn()=>$broker->claim($other,$claim),403,'X claim bound to licensed installation');$credential=$broker->claim($server,$claim);$check($credential['user_id']==='123456789012345'&&$credential['username']==='SenseCMS'&&$credential['access_token']===$access&&$credential['refresh_token']===$refresh&&$credential['expires_at']>time(),'one-time X credential claimed by correct installation');$reject(fn()=>$broker->claim($server,$claim),410,'claimed X credential is deleted');
    $tokens=$broker->refresh($server,$refresh);$check($tokens['access_token']===$nextAccess&&$tokens['refresh_token']===$nextRefresh&&$tokens['expires_at']>time(),'X refresh rotates credentials through official broker');$minimalRefresh=true;$tokens=$broker->refresh($server,$nextRefresh);$check($tokens['access_token']===$nextAccess&&$tokens['refresh_token']===$nextRefresh,'X refresh retains the current refresh token when X omits an unchanged value and scope');
    $cancel=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/x/callback']);$cancelRequest=$requestToken($cancel);$check($broker->callback(['state'=>$cancelRequest,'error'=>'access_denied'])==='https://tenant.example/social-publishing/x/callback','cancelled X login returns without claim');
    $raw='';foreach(glob($root.'/storage/*.json')?:[]as$file)$raw.=file_get_contents($file);$check(!str_contains($raw,$cfg['client_secret'])&&!str_contains($raw,$access)&&!str_contains($raw,$refresh)&&!str_contains($raw,str_repeat('K',32)),'X state encrypted without credentials or licence plaintext');
    $edge=['HTTPS'=>'on','HTTP_HOST'=>'www.sensecms.com','REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/social/x/v1/start','CONTENT_TYPE'=>'application/json']+$server;[$status,$headers,$body]=XOAuthEndpoint::response($edge,json_encode(['return_url'=>'https://tenant.example/social-publishing/x/callback']),$broker);$check($status===200&&($headers['Content-Type']??'')==='application/json; charset=utf-8'&&isset(json_decode($body,true)['authorize_url']),'X HTTP start returns broker URL');
    foreach([[array_replace($edge,['HTTPS'=>'off']),421],[array_replace($edge,['HTTP_HOST'=>'evil.example']),421],[array_replace($edge,['REQUEST_URI'=>'/api/social/x/v1/unknown']),404],[array_replace($edge,['REQUEST_METHOD'=>'GET']),405],[array_replace($edge,['CONTENT_TYPE'=>'text/plain']),415],[array_replace($edge,['CONTENT_LENGTH'=>65537]),413]]as$idx=>[$unsafe,$expected])$check(XOAuthEndpoint::response($unsafe,'{}',$broker)[0]===$expected,'X HTTP edge rejects unsafe request '.$idx);
    $check(XOAuthEndpoint::response($edge,'broken',$broker)[0]===400,'X HTTP edge rejects invalid JSON');
    echo"$count X broker checks passed; isolated transport only.\n";
}finally{$broker=null;gc_collect_cycles();foreach(glob($root.'/storage/*.json')?:[]as$file)unlink($file);if(is_file($root.'/broker.lock'))unlink($root.'/broker.lock');if(is_dir($root.'/storage'))rmdir($root.'/storage');rmdir($root);}
