<?php

declare(strict_types=1);

namespace SenseCMS\TikTok;

use App\Core\Runtime;
use RuntimeException;
use SensitiveParameter;

require_once __DIR__.'/TikTokOnboardingClient.php';

return new class {
    private string$root='';
    public function initialize(string$root):void{$this->root=$root;}

    public function verify(array$credentials):array
    {
        $credentials=$this->fresh($credentials);$user=$this->user($credentials['access_token']);if($user['open_id']!==$credentials['open_id'])throw new RuntimeException('The connected TikTok account is unavailable to this access token.');$credentials['display_name']=$user['display_name'];return['external_id'=>$user['open_id'],'display_name'=>'@'.$user['display_name'],'credentials'=>$credentials];
    }

    public function editor(array$credentials):array
    {
        $credentials=$this->fresh($credentials);$creator=$this->creatorCached($credentials);return$this->definition($creator)+['credentials'=>$credentials];
    }

    public function options(array$credentials,array$input):array
    {
        $credentials=$this->fresh($credentials);$creator=$this->creatorCached($credentials);return['options'=>$this->normalize($input,$creator),'credentials'=>$credentials];
    }

    public function publish(array$credentials,array$payload):array
    {
        $credentials=$this->fresh($credentials);$creator=$this->creatorCached($credentials);$options=$this->normalize(is_array($payload['social_options']??null)?$payload['social_options']:[],$creator);$message=trim(strip_tags((string)($payload['message']??'')));if($message===''||mb_strlen($message)>4000)throw new RuntimeException('The TikTok description is empty or longer than 4000 characters.');$title=trim(strip_tags((string)($payload['title']??'')));if($title==='')$title=$message;$title=$this->utf16($title,90);[$mime,$binary]=$this->image((string)($payload['image_url']??''));$delivery=(string)($payload['delivery_key']??'');$staged=$this->client()->stage($mime,$binary,$delivery);$body=['media_type'=>'PHOTO','post_mode'=>'DIRECT_POST','post_info'=>['title'=>$title,'description'=>$message,'privacy_level'=>$options['privacy_level'],'disable_comment'=>!$options['allow_comment'],'auto_add_music'=>false,'brand_content_toggle'=>$options['branded_content'],'brand_organic_toggle'=>$options['your_brand']],'source_info'=>['source'=>'PULL_FROM_URL','photo_cover_index'=>0,'photo_images'=>[$staged['source_url']]],'is_aigc'=>$options['ai_generated']];$data=$this->request('POST','https://open.tiktokapis.com/v2/post/publish/content/init/',$credentials['access_token'],$body);$id=(string)($data['data']['publish_id']??'');if(!preg_match('/^[A-Za-z0-9._:-]{1,64}$/D',$id))throw new RuntimeException('TikTok returned an invalid publication identifier.');return['external_id'=>$id,'external_url'=>'','status'=>'processing','credentials'=>$credentials];
    }

    public function status(array$credentials,string$publishId):array
    {
        if(!preg_match('/^[A-Za-z0-9._:-]{1,255}$/D',$publishId))throw new RuntimeException('The TikTok publication identifier is invalid.');$credentials=$this->fresh($credentials);$data=$this->request('POST','https://open.tiktokapis.com/v2/post/publish/status/fetch/',$credentials['access_token'],['publish_id'=>$publishId]);$status=(string)($data['data']['status']??'');if($status==='PUBLISH_COMPLETE')return['status'=>'published','external_url'=>'','credentials'=>$credentials];if($status==='FAILED')return['status'=>'failed','error'=>$this->failure((string)($data['data']['fail_reason']??'')),'credentials'=>$credentials];if(!in_array($status,['PROCESSING_DOWNLOAD','PROCESSING_UPLOAD','PROCESSING_TO_PUBLISH'],true))throw new RuntimeException('TikTok returned an unknown publication status.');return['status'=>'processing','credentials'=>$credentials];
    }

    private function definition(array$creator):array
    {
        $privacy=[];foreach($creator['privacy_level_options']as$value)$privacy[]=['value'=>$value,'label'=>match($value){'PUBLIC_TO_EVERYONE'=>'Public','MUTUAL_FOLLOW_FRIENDS'=>'Friends','FOLLOWER_OF_CREATOR'=>'Followers','SELF_ONLY'=>'Only me',default=>ucwords(strtolower(str_replace('_',' ',$value)))}];return['account'=>['title'=>'@'.$creator['username'],'subtitle'=>$creator['nickname']], 'fields'=>[
            ['name'=>'privacy_level','type'=>'select','label'=>'Who can view this post?','help'=>'TikTok requires an explicit privacy choice for every publication.','required'=>true,'options'=>$privacy],
            ['name'=>'allow_comment','type'=>'checkbox','label'=>'Allow comments','help'=>$creator['comment_disabled']?'Comments are disabled in this TikTok account.':'Leave unchecked to disable comments.','disabled'=>$creator['comment_disabled']],
            ['name'=>'commercial_content','type'=>'checkbox','label'=>'This post promotes a brand, product or service','help'=>'Enable the commercial-content disclosure when applicable.'],
            ['name'=>'your_brand','type'=>'checkbox','label'=>'Your brand','help'=>'The post promotes your own business or brand.'],
            ['name'=>'branded_content','type'=>'checkbox','label'=>'Branded content','help'=>'The post promotes another brand in exchange for payment or another incentive.'],
            ['name'=>'ai_generated','type'=>'checkbox','label'=>'AI-generated image','help'=>'TikTok will add an AI-generated label when this image was created with AI.'],
            ['name'=>'music_consent','type'=>'checkbox','label'=>'I agree to TikTok’s Music Usage Confirmation','help'=>'Required before every Direct Post, even when automatic music is disabled.','required'=>true,'link'=>['label'=>'Read confirmation','url'=>'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en']],
        ],'rules'=>[
            ['type'=>'at_least_one','when'=>'commercial_content','fields'=>['your_brand','branded_content'],'message'=>'Choose Your brand or Branded content.'],
            ['type'=>'at_least_one','when'=>'your_brand','fields'=>['commercial_content'],'message'=>'Enable the commercial-content disclosure for brand promotion.'],
            ['type'=>'at_least_one','when'=>'branded_content','fields'=>['commercial_content'],'message'=>'Enable the commercial-content disclosure for brand promotion.'],
            ['type'=>'incompatible','field'=>'branded_content','with'=>'privacy_level','value'=>'SELF_ONLY','message'=>'Branded content cannot be published with Only me privacy.'],
        ]];
    }

    private function normalize(array$input,array$creator):array
    {
        $privacy=(string)($input['privacy_level']??'');if(!in_array($privacy,$creator['privacy_level_options'],true))throw new RuntimeException('Choose an available TikTok privacy setting.');$bool=static fn(string$key):bool=>!empty($input[$key]);$allow=$bool('allow_comment');if($allow&&$creator['comment_disabled'])throw new RuntimeException('Comments are disabled for this TikTok account.');$commercial=$bool('commercial_content');$own=$bool('your_brand');$branded=$bool('branded_content');if(($own||$branded)&&!$commercial)throw new RuntimeException('Enable the commercial-content disclosure for brand promotion.');if($commercial&&!$own&&!$branded)throw new RuntimeException('Choose Your brand or Branded content for commercial content.');if($branded&&$privacy==='SELF_ONLY')throw new RuntimeException('Branded content cannot use Only me privacy.');if(!$bool('music_consent'))throw new RuntimeException('TikTok Music Usage Confirmation is required.');return['privacy_level'=>$privacy,'allow_comment'=>$allow,'commercial_content'=>$commercial,'your_brand'=>$own,'branded_content'=>$branded,'ai_generated'=>$bool('ai_generated'),'music_consent'=>true];
    }

    private function creator(#[SensitiveParameter]string$token):array
    {
        try{$data=$this->request('POST','https://open.tiktokapis.com/v2/post/publish/creator_info/query/',$token,[]);}catch(RuntimeException$error){if($error->getCode()!==502)throw$error;usleep(200000);$data=$this->request('POST','https://open.tiktokapis.com/v2/post/publish/creator_info/query/',$token,[]);}return$this->creatorData($data['data']??null);
    }

    private function creatorCached(array&$credentials):array
    {
        if((int)($credentials['creator_info_expires_at']??0)>time()&&is_array($credentials['creator_info']??null)){try{return$this->creatorData($credentials['creator_info']);}catch(RuntimeException){}}
        $creator=$this->creator((string)$credentials['access_token']);$credentials['creator_info']=$creator;$credentials['creator_info_expires_at']=time()+300;return$creator;
    }

    private function creatorData(mixed$row):array
    {
        $privacy=is_array($row)?array_values(array_unique(array_filter(array_map('strval',(array)($row['privacy_level_options']??[])),static fn(string$value):bool=>in_array($value,['PUBLIC_TO_EVERYONE','MUTUAL_FOLLOW_FRIENDS','FOLLOWER_OF_CREATOR','SELF_ONLY'],true)))):[];$username=is_array($row)?trim((string)($row['creator_username']??$row['username']??'')):'';$nickname=is_array($row)?trim((string)($row['creator_nickname']??$row['nickname']??'')):'';if(!$privacy||$username===''||mb_strlen($username)>100||$nickname===''||mb_strlen($nickname)>180)throw new RuntimeException('TikTok creator publishing information is unavailable.');return['username'=>$username,'nickname'=>$nickname,'privacy_level_options'=>$privacy,'comment_disabled'=>(bool)($row['comment_disabled']??true)];
    }

    private function user(#[SensitiveParameter]string$token):array
    {
        $data=$this->request('GET','https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name',$token,null);$user=$data['data']['user']??null;$open=is_array($user)?(string)($user['open_id']??''):'';$name=is_array($user)?trim((string)($user['display_name']??'')):'';if(!preg_match('/^[A-Za-z0-9._-]{1,191}$/D',$open)||$name===''||mb_strlen($name)>180)throw new RuntimeException('TikTok account discovery failed.');return['open_id'=>$open,'display_name'=>$name];
    }

    private function fresh(array$credentials,bool$force=false):array
    {
        $open=(string)($credentials['open_id']??'');$name=trim((string)($credentials['display_name']??''));$access=(string)($credentials['access_token']??'');$refresh=(string)($credentials['refresh_token']??'');$expires=(int)($credentials['expires_at']??0);$refreshExpires=(int)($credentials['refresh_expires_at']??0);if(!preg_match('/^[A-Za-z0-9._-]{1,191}$/D',$open)||$name===''||mb_strlen($name)>180||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<1||$refreshExpires<=time())throw new RuntimeException('The TikTok connection has expired. Reconnect this account.');if(!$force&&$expires>time()+300)return$credentials;$tokens=$this->client()->refresh($refresh);if(isset($tokens['open_id'])&&$tokens['open_id']!==$open)throw new RuntimeException('TikTok refreshed a different account identity.');$credentials['access_token']=(string)$tokens['access_token'];$credentials['refresh_token']=(string)$tokens['refresh_token'];$credentials['expires_at']=(int)$tokens['expires_at'];$credentials['refresh_expires_at']=(int)$tokens['refresh_expires_at'];return$credentials;
    }

    private function image(string$url):array
    {
        if($this->root===''||!is_dir($this->root))throw new RuntimeException('The TikTok provider runtime is unavailable.');$runtime=new Runtime($this->root);$base=parse_url($runtime->baseUrl());$parts=parse_url($url);if(!is_array($base)||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strcasecmp((string)($parts['host']??''),(string)($base['host']??''))!==0||(int)($parts['port']??443)!==(int)($base['port']??443)||isset($parts['query'])||isset($parts['fragment']))throw new RuntimeException('TikTok requires a local featured image from this installation.');$path=rawurldecode((string)($parts['path']??''));if(!preg_match('#^/media/[A-Za-z0-9._/-]+$#D',$path)||str_contains($path,'..'))throw new RuntimeException('The TikTok featured image path is invalid.');$public=realpath($this->root.'/public/media');$file=realpath($this->root.'/public'.$path);if(!$public||!$file||!str_starts_with(str_replace('\\','/',$file).'/',rtrim(str_replace('\\','/',$public),'/').'/')||is_link($file)||!is_file($file))throw new RuntimeException('The TikTok featured image is unavailable.');$size=filesize($file);if($size===false||$size<1||$size>20*1024*1024)throw new RuntimeException('The TikTok featured image must be smaller than 20 MB.');$binary=file_get_contents($file);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);if(!is_string($binary)||!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('TikTok requires a JPEG, PNG or WebP featured image.');return[$mime,$binary];
    }

    private function client():TikTokOnboardingClient
    {
        if($this->root===''||!is_dir($this->root))throw new RuntimeException('The TikTok provider runtime is unavailable.');$runtime=new Runtime($this->root);$installed=$runtime->read('installed');$root=$this->root;$baseUrl=$runtime->baseUrl();$config=require$root.'/config/workspace.php';return new TikTokOnboardingClient($runtime->license(),(string)($config['integrations']['tiktok_social_broker_url']??''),(string)$config['base_url']);
    }

    private function request(string$method,string$url,#[SensitiveParameter]string$token,?array$payload):array
    {
        if(!in_array($url,['https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name','https://open.tiktokapis.com/v2/post/publish/creator_info/query/','https://open.tiktokapis.com/v2/post/publish/content/init/','https://open.tiktokapis.com/v2/post/publish/status/fetch/'],true))throw new RuntimeException('The TikTok API address is invalid.');$response='';$curl=curl_init($url);if($curl===false)throw new RuntimeException('TikTok publishing could not be initialized.');$headers=['Authorization: Bearer '.$token,'Accept: application/json'];$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-TikTok-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}];if($payload!==null){$options[CURLOPT_POSTFIELDS]=$payload===[]?'{}':json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$options[CURLOPT_HTTPHEADER][]='Content-Type: application/json; charset=UTF-8';}curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);$apiCode=is_array($data)?(string)($data['error']['code']??''):'';if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data)||$apiCode!=='ok'){$safe=substr((string)preg_replace('/[^A-Za-z0-9._-]/','',$apiCode),0,80);error_log('TikTok API request failed: HTTP '.$status.' code '.($safe!==''?$safe:'unavailable'));throw new RuntimeException($status===429?'TikTok temporarily limited publishing.':($status===401?'The TikTok access token must be refreshed.':'TikTok rejected the request.'),$status===429?429:($status===401?401:502));}return$data;
    }

    private function failure(string$reason):string{return match($reason){'photo_pull_failed'=>'TikTok could not download the featured image.','spam_risk_text'=>'TikTok rejected the post text as possible spam.','spam_risk_user'=>'TikTok temporarily restricted this account from posting.','url_ownership_unverified'=>'TikTok did not accept the verified media source.','unaudited_client_can_only_post_to_private_accounts'=>'This unaudited TikTok application can publish only to private target accounts.',default=>'TikTok could not complete this publication.'};}
    private function utf16(string$value,int$limit):string{$result='';$units=0;foreach(mb_str_split($value)as$char){$size=(int)(strlen(mb_convert_encoding($char,'UTF-16LE','UTF-8'))/2);if($units+$size>$limit)break;$result.=$char;$units+=$size;}return$result;}
};
