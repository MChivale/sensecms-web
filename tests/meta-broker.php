<?php

declare(strict_types=1);

require dirname(__DIR__).'/.cms/source/bootstrap.php';
require dirname(__DIR__).'/.src/MetaBrokerService.php';
require dirname(__DIR__).'/.src/MetaOAuthEndpoint.php';

use SenseCMS\Website\MetaBrokerService;
use SenseCMS\Website\MetaOAuthEndpoint;

$root=sys_get_temp_dir().'/sensecms-meta-test-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700))throw new RuntimeException('Cannot create private Meta fixture.');
$key=random_bytes(32);$secret=str_repeat('s',40);$userToken='user-token-'.str_repeat('u',32);$pageA='page-token-a-'.str_repeat('a',32);$pageB='page-token-b-'.str_repeat('b',32);$one=false;$calls=[];$count=0;
$cfg=['app_id'=>'1373530514896687','app_secret'=>$secret,'configuration_id'=>'28576295405370380','api_version'=>'v26.0'];
$http=static function(string$method,string$url,array$headers,string$payload)use(&$calls,&$one,$userToken,$pageA,$pageB):array{
    $calls[]=[$method,$url,$headers,$payload];
    if($url==='https://www.chivale.com/license/'){
        parse_str($payload,$form);if(($form['LicenseKey']??'')!==str_repeat('K',32))return[403,'{"error":true}'];
        return[200,json_encode(['error'=>false,'data'=>['product'=>['name'=>'Sense CMS','model'=>'Sense CMS System','version'=>'1.0'],'valid_from'=>null,'valid_to'=>null]])];
    }
    if($url==='https://graph.facebook.com/v26.0/oauth/access_token')return[200,json_encode(['access_token'=>$userToken,'token_type'=>'bearer'])];
    if($url==='https://graph.facebook.com/v26.0/me?fields=id')return[200,'{"id":"9988776655"}'];
    if($url==='https://graph.facebook.com/v26.0/me/accounts?fields=id%2Cname%2Caccess_token%2Ctasks&limit=25'){
        $rows=[['id'=>'100000000001','name'=>'Sense Main','access_token'=>$pageA,'tasks'=>['CREATE_CONTENT']],['id'=>'100000000002','name'=>'Sense Second','access_token'=>$pageB,'tasks'=>['MANAGE']]];
        return[200,json_encode(['data'=>$one?[$rows[0]]:$rows])];
    }
    throw new RuntimeException('Unexpected fixture URL.');
};
$broker=new MetaBrokerService($root,$key,$cfg,$http);
$server=['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('K',32),'HTTP_X_SENSECMS_DOMAIN'=>'https://tenant.example'];
$other=array_replace($server,['HTTP_X_SENSECMS_DOMAIN'=>'https://other.example']);
$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo"PASS $label\n";};
$reject=static function(callable$fn,int$code,string$label)use($check):void{try{$fn();}catch(RuntimeException$error){$check($error->getCode()===$code&&!str_contains($error->getMessage(),'page-token'),$label);return;}throw new RuntimeException($label);};
$requestToken=static function(array$start):string{parse_str((string)parse_url($start['authorize_url'],PHP_URL_QUERY),$query);return(string)($query['request']??'');};
$signed=static function(string$user)use($secret):string{$payload=rtrim(strtr(base64_encode(json_encode(['algorithm'=>'HMAC-SHA256','user_id'=>$user],JSON_THROW_ON_ERROR)),'+/','-_'),'=');$signature=rtrim(strtr(base64_encode(hash_hmac('sha256',$payload,$secret,true)),'+/','-_'),'=');return$signature.'.'.$payload;};
try{
    $check($broker->ready(),'configured broker ready');
    $reject(fn()=>(new MetaBrokerService($root,$key,[],$http))->start($server,[]),503,'missing app secret fails closed');
    $reject(fn()=>$broker->start([],['return_url'=>'https://tenant.example/social-publishing/facebook/callback']),401,'installation licence required');
    $reject(fn()=>$broker->start($server,['return_url'=>'https://evil.example/social-publishing/facebook/callback']),422,'return URL bound to licensed installation');
    $start=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/facebook/callback']);$request=$requestToken($start);
    $check(strlen($request)===43&&$start['expires_in']===600,'short-lived central authorization request');
    $authorize=$broker->authorize($request);parse_str((string)parse_url($authorize,PHP_URL_QUERY),$authQuery);
    $check(str_starts_with($authorize,'https://www.facebook.com/v26.0/dialog/oauth?')&&$authQuery['client_id']===$cfg['app_id']&&$authQuery['config_id']===$cfg['configuration_id']&&$authQuery['state']===$request&&$authQuery['redirect_uri']==='https://www.sensecms.com/api/social/meta/v1/callback','versioned Facebook Login for Business URL');
    $result=$broker->callback(['state'=>$request,'code'=>'fixture-code']);
    $check(isset($result['selection'])&&count($result['selection']['pages'])===2&&!isset($result['selection']['pages'][0]['access_token']),'multiple Pages require a token-free selection');
    $selection=$result['selection']['token'];
    $reject(fn()=>$broker->callback(['state'=>$request,'code'=>'fixture-code']),410,'OAuth state is single-use');
    $reject(fn()=>$broker->select($selection,'999999999999'),422,'ungranted Page cannot be selected');
    $redirect=$broker->select($selection,'100000000002');parse_str((string)parse_url($redirect,PHP_URL_QUERY),$claimQuery);$claim=$claimQuery['claim']??'';
    $check(str_starts_with($redirect,'https://tenant.example/social-publishing/facebook/callback?claim=')&&strlen($claim)===43,'selected Page returns opaque customer claim');
    $reject(fn()=>$broker->select($selection,'100000000002'),410,'Page selection is single-use');
    $reject(fn()=>$broker->claim($other,$claim),403,'claim bound to licensed installation');
    $credential=$broker->claim($server,$claim);
    $check($credential['page_id']==='100000000002'&&$credential['page_name']==='Sense Second'&&$credential['access_token']===$pageB&&$credential['api_version']==='v26.0','one-time Page credential claimed by correct installation');
    $reject(fn()=>$broker->claim($server,$claim),410,'claimed Page credential is deleted');
    $one=true;$single=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/facebook/callback']);$singleRequest=$requestToken($single);$singleResult=$broker->callback(['state'=>$singleRequest,'code'=>'fixture-code-2']);
    $check(isset($singleResult['redirect'])&&str_contains($singleResult['redirect'],'claim='),'single Page skips selection safely');
    parse_str((string)parse_url($singleResult['redirect'],PHP_URL_QUERY),$singleClaimQuery);$pendingClaim=(string)$singleClaimQuery['claim'];
    $deletion=$broker->deleteData($signed('9988776655'));
    $check(str_starts_with($deletion['url'],'https://www.sensecms.com/data-deletion?code=')&&strlen($deletion['confirmation_code'])===24&&$deletion['removed']===1,'signed Meta deletion removes transient user state');
    $reject(fn()=>$broker->claim($server,$pendingClaim),410,'deleted transient credential cannot be claimed');
    $reject(fn()=>$broker->deleteData('invalid.payload'),403,'forged deletion request refused');
    $cancel=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/facebook/callback']);$cancelRequest=$requestToken($cancel);$cancelResult=$broker->callback(['state'=>$cancelRequest,'error'=>'access_denied']);
    $check($cancelResult['redirect']==='https://tenant.example/social-publishing/facebook/callback','cancelled Meta login returns without leaking provider details');
    $raw='';foreach(glob($root.'/storage/*.json')?:[]as$file)$raw.=file_get_contents($file);
    $check(!str_contains($raw,$secret)&&!str_contains($raw,$userToken)&&!str_contains($raw,$pageA)&&!str_contains($raw,$pageB)&&!str_contains($raw,str_repeat('K',32)),'private state encrypted without tokens or licence plaintext');
    $edge=['HTTPS'=>'on','HTTP_HOST'=>'www.sensecms.com','REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/social/meta/v1/start','CONTENT_TYPE'=>'application/json']+$server;
    [$status,$headers,$body]=MetaOAuthEndpoint::response($edge,json_encode(['return_url'=>'https://tenant.example/social-publishing/facebook/callback']),$broker);
    $check($status===200&&($headers['Content-Type']??'')==='application/json; charset=utf-8'&&isset(json_decode($body,true)['authorize_url']),'HTTP start endpoint returns broker URL');
    foreach([[array_replace($edge,['HTTPS'=>'off']),421],[array_replace($edge,['HTTP_HOST'=>'evil.example']),421],[array_replace($edge,['REQUEST_URI'=>'/api/social/meta/v1/unknown']),404],[array_replace($edge,['REQUEST_METHOD'=>'GET']),405],[array_replace($edge,['CONTENT_TYPE'=>'text/plain']),415],[array_replace($edge,['CONTENT_LENGTH'=>65537]),413]]as$idx=>[$unsafe,$expected])$check(MetaOAuthEndpoint::response($unsafe,'{}',$broker)[0]===$expected,'HTTP edge rejects unsafe request '.$idx);
    $check(MetaOAuthEndpoint::response($edge,'broken',$broker)[0]===400,'HTTP edge rejects invalid JSON');
    $_GET=['request'=>$requestToken($broker->start($server,['return_url'=>'https://tenant.example/social-publishing/facebook/callback']))];$authorizeEdge=array_replace($edge,['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/social/meta/v1/authorize?request='.$_GET['request']]);
    [$status,$headers]=MetaOAuthEndpoint::response($authorizeEdge,'',$broker);$check($status===302&&str_starts_with($headers['Location'],'https://www.facebook.com/v26.0/dialog/oauth?'),'HTTP authorize endpoint redirects only to Meta');
    $start2=$broker->start($server,['return_url'=>'https://tenant.example/social-publishing/facebook/callback']);$_GET=['state'=>$requestToken($start2),'code'=>'fixture-code-3'];$callbackEdge=array_replace($edge,['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/social/meta/v1/callback?code=x']);$one=false;
    [$status,$headers,$html]=MetaOAuthEndpoint::response($callbackEdge,'',$broker);$check($status===200&&str_contains($html,'Select the Facebook Page')&&!str_contains($html,$pageA)&&str_contains($headers['Content-Security-Policy'],'form-action'),'Page selection HTML contains no credential');
    $payload=rtrim(strtr(base64_encode(json_encode(['algorithm'=>'HMAC-SHA256','user_id'=>'123'],JSON_THROW_ON_ERROR)),'+/','-_'),'=');$bad=rtrim(strtr(base64_encode(hash_hmac('sha256',$payload,'wrong',true)),'+/','-_'),'=').'.'.$payload;
    $deleteEdge=array_replace($edge,['REQUEST_URI'=>'/api/social/meta/v1/data-deletion','CONTENT_TYPE'=>'application/x-www-form-urlencoded']);
    $check(MetaOAuthEndpoint::response($deleteEdge,http_build_query(['signed_request'=>$bad]),$broker)[0]===403,'HTTP data deletion requires Meta signature');
    echo"$count Meta broker checks passed; isolated transport only.\n";
}finally{
    foreach(glob($root.'/storage/*.json')?:[]as$file)unlink($file);if(is_dir($root.'/storage'))rmdir($root.'/storage');if(is_file($root.'/broker.lock'))unlink($root.'/broker.lock');rmdir($root);
}
