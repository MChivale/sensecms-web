<?php

declare(strict_types=1);

require dirname(__DIR__).'/.cms/source/bootstrap.php';
require dirname(__DIR__).'/.src/LinkedInBrokerService.php';
require dirname(__DIR__).'/.src/LinkedInOAuthEndpoint.php';

use SenseCMS\Website\LinkedInBrokerService;
use SenseCMS\Website\LinkedInOAuthEndpoint;

$root=sys_get_temp_dir().'/sensecms-linkedin-test-'.bin2hex(random_bytes(8));if(!mkdir($root,0700))throw new RuntimeException('Cannot create private LinkedIn fixture.');
$key=random_bytes(32);$access='access-'.str_repeat('a',40);$calls=[];$count=0;$cfg=['client_id'=>'fixture-client-id','client_secret'=>'fixture-client-secret-'.str_repeat('s',20)];
$http=static function(string$method,string$url,array$headers,string$payload)use(&$calls,$access):array{
    $calls[]=[$method,$url,$headers,$payload];
    if($url==='https://www.chivale.com/license/'){parse_str($payload,$form);if(($form['LicenseKey']??'')!==str_repeat('K',32))return[403,'{"error":true}'];return[200,json_encode(['error'=>false,'data'=>['product'=>['name'=>'Sense CMS','model'=>'Sense CMS System','version'=>'1.0'],'valid_from'=>null,'valid_to'=>null]])];}
    if($url==='https://www.linkedin.com/oauth/v2/accessToken'){parse_str($payload,$form);if(($form['client_secret']??'')==='')throw new RuntimeException('Missing fixture secret.');return[200,json_encode(['expires_in'=>5184000,'scope'=>'openid,profile,w_member_social','access_token'=>$access])];}
    if($url==='https://api.linkedin.com/v2/userinfo')return[200,'{"sub":"member_A1","name":"Sense CMS Owner"}'];
    throw new RuntimeException('Unexpected fixture URL.');
};
$broker=new LinkedInBrokerService($root,$key,$cfg,$http);$server=['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('K',32),'HTTP_X_SENSECMS_DOMAIN'=>'https://tenant.example'];$other=array_replace($server,['HTTP_X_SENSECMS_DOMAIN'=>'https://other.example']);
$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo"PASS $label\n";};
$reject=static function(callable$fn,int$code,string$label)use($check):void{try{$fn();}catch(RuntimeException$error){$check($error->getCode()===$code&&!str_contains($error->getMessage(),'access-'),' '.$label);return;}throw new RuntimeException($label);};
$requestToken=static function(array$start):string{parse_str((string)parse_url($start['authorize_url'],PHP_URL_QUERY),$query);return(string)($query['request']??'');};
try{
    $check($broker->ready(),'configured LinkedIn broker ready');$reject(fn()=>(new LinkedInBrokerService($root,$key,[],$http))->start($server,[]),503,'missing LinkedIn app credentials fail closed');
    $reject(fn()=>$broker->start([],['return_url'=>'https://tenant.example/social-publishing/linkedin/callback']),401,'installation licence required');$reject(fn()=>$broker->start($server,['return_url'=>'https://evil.example/social-publishing/linkedin/callback']),422,'return URL bound to installation');
    $start=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/linkedin/callback']);$request=$requestToken($start);$check(strlen($request)===43&&$start['expires_in']===600,'short-lived LinkedIn authorization request');
    $authorize=$broker->authorize($request);parse_str((string)parse_url($authorize,PHP_URL_QUERY),$query);$check(str_starts_with($authorize,'https://www.linkedin.com/oauth/v2/authorization?')&&$query['client_id']===$cfg['client_id']&&$query['redirect_uri']==='https://www.sensecms.com/api/social/linkedin/v1/callback'&&$query['scope']==='openid profile w_member_social'&&$query['state']===$request,'LinkedIn authorization uses minimum self-service publishing scopes');
    $redirect=$broker->callback(['state'=>$request,'code'=>'fixture-code']);parse_str((string)parse_url($redirect,PHP_URL_QUERY),$claimQuery);$claim=(string)($claimQuery['claim']??'');$check(str_starts_with($redirect,'https://tenant.example/social-publishing/linkedin/callback?claim=')&&strlen($claim)===43,'LinkedIn callback returns opaque installation claim');
    $reject(fn()=>$broker->callback(['state'=>$request,'code'=>'fixture-code']),410,'LinkedIn OAuth state is single-use');$reject(fn()=>$broker->claim($other,$claim),403,'LinkedIn claim bound to licensed installation');$credential=$broker->claim($server,$claim);$check($credential['member_id']==='member_A1'&&$credential['name']==='Sense CMS Owner'&&$credential['access_token']===$access&&$credential['expires_at']>time(),'one-time LinkedIn credential claimed by correct installation');$reject(fn()=>$broker->claim($server,$claim),410,'claimed LinkedIn credential is deleted');
    $cancel=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/linkedin/callback']);$cancelRequest=$requestToken($cancel);$check($broker->callback(['state'=>$cancelRequest,'error'=>'user_cancelled_login'])==='https://tenant.example/social-publishing/linkedin/callback','cancelled LinkedIn login returns without claim');
    $raw='';foreach(glob($root.'/storage/*.json')?:[]as$file)$raw.=file_get_contents($file);$check(!str_contains($raw,$cfg['client_secret'])&&!str_contains($raw,$access)&&!str_contains($raw,str_repeat('K',32)),'LinkedIn state encrypted without credentials or licence plaintext');
    $edge=['HTTPS'=>'on','HTTP_HOST'=>'www.sensecms.com','REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/social/linkedin/v1/start','CONTENT_TYPE'=>'application/json']+$server;[$status,$headers,$body]=LinkedInOAuthEndpoint::response($edge,json_encode(['return_url'=>'https://tenant.example/social-publishing/linkedin/callback']),$broker);$check($status===200&&($headers['Content-Type']??'')==='application/json; charset=utf-8'&&isset(json_decode($body,true)['authorize_url']),'LinkedIn HTTP start returns broker URL');
    foreach([[array_replace($edge,['HTTPS'=>'off']),421],[array_replace($edge,['HTTP_HOST'=>'evil.example']),421],[array_replace($edge,['REQUEST_URI'=>'/api/social/linkedin/v1/unknown']),404],[array_replace($edge,['REQUEST_METHOD'=>'GET']),405],[array_replace($edge,['CONTENT_TYPE'=>'text/plain']),415],[array_replace($edge,['CONTENT_LENGTH'=>65537]),413]]as$idx=>[$unsafe,$expected])$check(LinkedInOAuthEndpoint::response($unsafe,'{}',$broker)[0]===$expected,'LinkedIn HTTP edge rejects unsafe request '.$idx);
    $check(LinkedInOAuthEndpoint::response($edge,'broken',$broker)[0]===400,'LinkedIn HTTP edge rejects invalid JSON');
    echo"$count LinkedIn broker checks passed; isolated transport only.\n";
}finally{$broker=null;gc_collect_cycles();foreach(glob($root.'/storage/*.json')?:[]as$file)unlink($file);if(is_file($root.'/broker.lock'))unlink($root.'/broker.lock');if(is_dir($root.'/storage'))rmdir($root.'/storage');rmdir($root);}
