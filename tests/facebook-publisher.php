<?php
declare(strict_types=1);

namespace SenseCMS\Facebook {
    function curl_init(string$url):object{return(object)['url'=>$url,'options'=>[],'status'=>0];}
    function curl_setopt_array(object$curl,array$options):bool{$curl->options=$options;return true;}
    function curl_exec(object$curl):bool{$GLOBALS['facebook_requests'][]=$curl;$next=array_shift($GLOBALS['facebook_responses']);if(!$next)throw new \RuntimeException('Unexpected Facebook request.');$curl->status=$next[0];$body=is_array($next[1])?json_encode($next[1]):$next[1];return($curl->options[CURLOPT_WRITEFUNCTION])($curl,$body)===strlen($body);}
    function curl_error(object$curl):string{return'';}
    function curl_getinfo(object$curl,int$kind):int{return$curl->status;}
    function curl_close(object$curl):void{}
}
namespace {
    $provider=require dirname(__DIR__).'/.plugins/facebook-publisher/src/provider.php';$checks=0;
    function facebookCheck(bool$ok,string$label):void{global$checks;if(!$ok)throw new RuntimeException($label);$checks++;echo"PASS $label\n";}
    function facebookRejects(callable$fn,string$label,?int$code=null):void{try{$fn();}catch(RuntimeException$error){facebookCheck($code===null||$error->getCode()===$code,$label);return;}throw new RuntimeException($label);}
    function facebookResponses(array$items):void{$GLOBALS['facebook_responses']=$items;$GLOBALS['facebook_requests']=[];}
    $render=static function(array$provider):string{$facebookProvider=$provider;$facebookOAuthRevision=3;$facebookOAuthOutcome='success';$csrf='fixture-csrf';ob_start();require dirname(__DIR__).'/.plugins/facebook-publisher/views/facebook.php';return(string)ob_get_clean();};
    $disconnected=$render([]);facebookCheck(str_contains($disconnected,'action="/social-publishing/facebook/connect"')&&str_contains($disconnected,'data-facebook-connect'),'OAuth connect exposes the focused popup hook');
    facebookCheck(str_contains($disconnected,'data-facebook-oauth-modal')&&str_contains($disconnected,'aria-live="polite"'),'OAuth progress dialog is accessible');
    $connected=$render(['connected'=>true,'enabled'=>true,'connected_count'=>2,'connections'=>[['id'=>7,'enabled'=>true,'display_name'=>'Sense Page','external_account_id'=>'123456789','last_verified_at'=>'now'],['id'=>8,'enabled'=>true,'display_name'=>'Second Page','external_account_id'=>'987654321','last_verified_at'=>'later']]]);facebookCheck(str_contains($connected,'action="/social-publishing/facebook/connections/7/disconnect"')&&str_contains($connected,'action="/social-publishing/facebook/connections/8/disconnect"'),'each Facebook Page has an independent disconnect action');
    facebookCheck(substr_count($connected,'class="social-account-card"')===2&&str_contains($connected,'Add Facebook Page'),'Facebook settings list multiple Pages and retain the add action');
    facebookCheck(str_contains($connected,'sensecms-unified-workspace')&&str_contains($connected,'social-account-list')&&str_contains($connected,'social-steps'),'Facebook settings use the unified responsive workspace');
    $overview=static function():string{$socialPublishing=['providers'=>[['slug'=>'facebook-publisher','label'=>'Facebook Page','icon'=>'facebook','connected'=>true,'enabled'=>true,'connected_count'=>2,'config_url'=>'/social-publishing/facebook']],'counts'=>[],'deliveries'=>[['id'=>1,'post_id'=>2,'post_title'=>'Story','plugin_slug'=>'facebook-publisher','connection_id'=>7,'destination_display_name'=>'Sense Page','destination_external_id'=>'123456789','status'=>'published','attempts'=>1,'created_at'=>'now','last_error'=>null,'external_url'=>'https://facebook.example/post']]];$socialCanManage=true;$socialCanPublish=true;$csrf='fixture-csrf';ob_start();require dirname(__DIR__).'/.addons/social-publishing/views/social.php';return(string)ob_get_clean();};
    $social=$overview();facebookCheck(str_contains($social,'social-publishing-stats')&&str_contains($social,'social-provider-identity')&&str_contains($social,'2 destinations connected')&&str_contains($social,'Sense Page'),'Social overview exposes destination totals and delivery identity');
    $popup=(string)file_get_contents(dirname(__DIR__).'/.plugins/facebook-publisher/assets/facebook.js');facebookCheck(str_contains($popup,"window.open('about:blank',popupName")&&str_contains($popup,"event.origin!==location.origin")&&str_contains($popup,"popup.location.replace(body.authorize_url)"),'OAuth popup validates its message origin and broker destination');
    facebookCheck(str_contains($popup,"fetch('/social-publishing/facebook/status'")&&str_contains($popup,'checks>=600')&&str_contains($popup,'facebookOauthRevision'),'OAuth popup polls a bounded per-session completion revision');
    facebookCheck(str_contains($popup,"if(window.name!==popupName)return false")&&str_contains($popup,'if(window.opener&&!window.opener.closed)')&&str_contains($popup,'window.close()'),'OAuth popup closes after a provider-isolated return without requiring its opener');
    facebookCheck(str_contains($popup,"facebookOauthOutcome==='success'")&&!str_contains($popup,"facebookConnected==='1'"),'existing connections cannot mask an OAuth failure');
    $editor=(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/assets/editor.js');$manager=(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/src/SocialIntegrationManager.php');facebookCheck(str_contains($editor,'provider.connections')&&str_contains($editor,'social_targets[${connection.id}]')&&str_contains($manager,"\$row['id'] = (int) \$row['id'];"),'post editor targets server-normalized independent connection IDs');
    $credentials=['page_id'=>'123456789','access_token'=>'test-access-token-1234567890','api_version'=>'v26.0'];
    $payload=['message'=>'A reviewed story','url'=>'https://example.test/en/posts/story','image_url'=>null];
    facebookResponses([[200,['id'=>'123456789','name'=>'Sense Page']]]);
    $verified=$provider->verify($credentials);facebookCheck($verified['external_id']==='123456789'&&$verified['display_name']==='Sense Page','Page identity available to system-user token');
    $request=$GLOBALS['facebook_requests'][0];facebookCheck(str_starts_with($request->url,'https://graph.facebook.com/v26.0/123456789?'),'Graph host, version and Page are fixed by validated credentials');
    facebookCheck($request->options[CURLOPT_FOLLOWLOCATION]===false&&$request->options[CURLOPT_SSL_VERIFYPEER]===true,'Redirects disabled and TLS verified');
    facebookCheck(in_array('Authorization: Bearer '.$credentials['access_token'],$request->options[CURLOPT_HTTPHEADER],true),'Page token sent in Authorization header');
    facebookResponses([[200,['id'=>'987654321','name'=>'Another Page']]]);facebookRejects(fn()=>$provider->verify($credentials),'Mismatched Page identity rejected');
    facebookResponses([[200,['id'=>'123456789_987654321']]]);$published=$provider->publish($credentials,$payload);facebookCheck($published['external_id']==='123456789_987654321','Link post publication identity accepted');
    $request=$GLOBALS['facebook_requests'][0];facebookCheck(str_ends_with($request->url,'/123456789/feed'),'Link post uses Page feed endpoint');
    parse_str((string)$request->options[CURLOPT_POSTFIELDS],$form);facebookCheck($form['message']===$payload['message']&&$form['link']===$payload['url'],'Link post contains reviewed message and canonical URL');
    facebookResponses([[200,['post_id'=>'123456789_111111111','id'=>'222222222']]]);$photo=$provider->publish($credentials,array_replace($payload,['image_url'=>'https://example.test/media/story.jpg']));facebookCheck($photo['external_id']==='123456789_111111111','Photo publication prefers returned post identity');
    $request=$GLOBALS['facebook_requests'][0];parse_str((string)$request->options[CURLOPT_POSTFIELDS],$form);facebookCheck(str_ends_with($request->url,'/photos')&&$form['url']==='https://example.test/media/story.jpg'&&str_contains($form['caption'],$payload['url']),'Photo endpoint receives remote image and canonical URL');
    facebookResponses([]);facebookRejects(fn()=>$provider->publish(array_replace($credentials,['page_id'=>'bad']),$payload),'Invalid Page ID rejected before network');facebookCheck(!$GLOBALS['facebook_requests'],'Invalid credentials send no request');
    facebookResponses([]);facebookRejects(fn()=>$provider->publish($credentials,array_replace($payload,['url'=>'http://example.test/story'])),'Non-HTTPS public URL rejected');
    facebookResponses([[429,['error'=>['code'=>4,'message'=>'secret upstream details']]]]);facebookRejects(fn()=>$provider->publish($credentials,$payload),'Rate limit remains a failure',429);
    facebookResponses([[200,'not json']]);facebookRejects(fn()=>$provider->publish($credentials,$payload),'Malformed Graph response rejected',502);
    facebookResponses([[200,str_repeat('x',262145)]]);facebookRejects(fn()=>$provider->publish($credentials,$payload),'Oversized Graph response rejected',502);
    facebookResponses([[200,['id'=>'unsafe/id']]]);facebookRejects(fn()=>$provider->publish($credentials,$payload),'Unexpected publication identity rejected');
    echo"$checks Facebook Publisher protocol checks passed; no live Meta requests.\n";
}
