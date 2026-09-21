<?php

declare(strict_types=1);

namespace SenseCMS\YouTube;

use App\Core\Runtime;
use RuntimeException;
use SensitiveParameter;

require_once __DIR__.'/YouTubeOnboardingClient.php';

return new class {
    private string$root='';
    public function initialize(string$root):void{$this->root=$root;}

    public function verify(array$credentials):array
    {
        $credentials=$this->fresh($credentials);try{$channel=$this->channel((string)$credentials['access_token']);}catch(RuntimeException$error){if($error->getCode()!==401)throw$error;$credentials=$this->fresh($credentials,true);$channel=$this->channel((string)$credentials['access_token']);}
        if($channel['id']!==$credentials['channel_id'])throw new RuntimeException('The YouTube access token belongs to another channel.');$credentials['channel_title']=$channel['title'];return['external_id'=>$channel['id'],'display_name'=>$channel['title'],'credentials'=>$credentials];
    }

    public function editor(array$credentials):array
    {
        $credentials=$this->fresh($credentials);$channel=$this->channel((string)$credentials['access_token']);if($channel['id']!==$credentials['channel_id'])throw new RuntimeException('The connected YouTube channel changed.');$public=!empty($credentials['public_uploads']);$privacy=[['value'=>'private','label'=>'Private']];if($public){$privacy[]=['value'=>'unlisted','label'=>'Unlisted'];$privacy[]=['value'=>'public','label'=>'Public'];}
        return['credentials'=>$credentials,'account'=>['title'=>$channel['title'],'subtitle'=>$public?'Compliance review approved for public publishing.':'Development Preview: YouTube enforces private uploads.'],'fields'=>[
            ['name'=>'media_id','type'=>'media','kind'=>'video','accept_mime'=>['video/mp4','video/webm'],'label'=>'Video from Media Library','help'=>'Choose an MP4 or WebM video, up to the current 80 MiB Sense CMS media limit.','required'=>true],
            ['name'=>'privacy_status','type'=>'select','label'=>'Privacy','help'=>$public?'Choose who can view this upload.':'Only private uploads are available until Google completes the YouTube API compliance audit.','required'=>true,'options'=>$privacy],
            ['name'=>'made_for_kids','type'=>'select','label'=>'Audience','help'=>'YouTube requires every upload to declare whether it is made for children.','required'=>true,'options'=>[['value'=>'no','label'=>'No, it is not made for kids'],['value'=>'yes','label'=>'Yes, it is made for kids']]],
            ['name'=>'contains_synthetic_media','type'=>'checkbox','label'=>'Contains realistic altered or synthetic media','help'=>'Enable when YouTube requires disclosure of realistic AI-generated or meaningfully altered content.'],
            ['name'=>'notify_subscribers','type'=>'checkbox','label'=>'Notify subscribers','help'=>'Ask YouTube to notify channel subscribers about the new upload.'],
        ]];
    }

    public function options(array$credentials,array$input):array
    {
        $media=max(0,(int)($input['media_id']??0));if(!$media)throw new RuntimeException('Choose a video from the Media Library.');$privacy=(string)($input['privacy_status']??'private');$allowed=!empty($credentials['public_uploads'])?['private','unlisted','public']:['private'];if(!in_array($privacy,$allowed,true))throw new RuntimeException('This YouTube application can currently upload only private videos.');$audience=(string)($input['made_for_kids']??'');if(!in_array($audience,['yes','no'],true))throw new RuntimeException('Choose the YouTube audience setting.');return['options'=>['media_id'=>$media,'privacy_status'=>$privacy,'made_for_kids'=>$audience==='yes','contains_synthetic_media'=>!empty($input['contains_synthetic_media']),'notify_subscribers'=>!empty($input['notify_subscribers'])]];
    }

    public function publish(array$credentials,array$payload):array
    {
        $options=is_array($payload['social_options']??null)?$payload['social_options']:[];$media=max(0,(int)($options['media_id']??0));[$file,$size,$mime]=$this->video($media,max(0,(int)($payload['post_id']??0)));$privacy=(string)($options['privacy_status']??'private');if(!in_array($privacy,!empty($credentials['public_uploads'])?['private','unlisted','public']:['private'],true))throw new RuntimeException('This YouTube application can currently upload only private videos.');if(!array_key_exists('made_for_kids',$options)||!is_bool($options['made_for_kids']))throw new RuntimeException('The YouTube audience setting is missing.');$title=mb_substr(trim(strip_tags((string)($payload['title']??'')))?:'Sense CMS video',0,100);$description=trim(strip_tags((string)($payload['message']??'')));$url=trim((string)($payload['url']??''));if($url!==''&&!str_contains($description,$url))$description=trim($description."\n\n".$url);$description=mb_substr($description,0,5000);$credentials=$this->fresh($credentials);
        $metadata=['snippet'=>['title'=>$title,'description'=>$description,'categoryId'=>'22'],'status'=>['privacyStatus'=>$privacy,'selfDeclaredMadeForKids'=>$options['made_for_kids'],'containsSyntheticMedia'=>!empty($options['contains_synthetic_media'])]];
        try{$video=$this->upload((string)$credentials['access_token'],$file,$size,$mime,$metadata,!empty($options['notify_subscribers']));}catch(RuntimeException$error){if($error->getCode()!==401)throw$error;$credentials=$this->fresh($credentials,true);$video=$this->upload((string)$credentials['access_token'],$file,$size,$mime,$metadata,!empty($options['notify_subscribers']));}
        $id=(string)($video['id']??'');if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$id))throw new RuntimeException('YouTube returned an invalid video identifier.');return['external_id'=>$id,'external_url'=>'https://www.youtube.com/watch?v='.$id,'credentials'=>$credentials];
    }

    private function fresh(array$credentials,bool$force=false):array
    {
        $id=(string)($credentials['channel_id']??'');$title=trim((string)($credentials['channel_title']??''));$access=(string)($credentials['access_token']??'');$refresh=(string)($credentials['refresh_token']??'');$expires=(int)($credentials['expires_at']??0);if(!preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$id)||$title===''||mb_strlen($title)>180||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<1)throw new RuntimeException('The YouTube connection has expired. Reconnect this channel.');if(!$force&&$expires>time()+300)return$credentials;$tokens=$this->client()->refresh($refresh);$credentials['access_token']=(string)$tokens['access_token'];$credentials['refresh_token']=(string)$tokens['refresh_token'];$credentials['expires_at']=(int)$tokens['expires_at'];return$credentials;
    }

    private function channel(#[SensitiveParameter]string$token):array
    {
        $data=$this->json('GET','https://www.googleapis.com/youtube/v3/channels?part=id%2Csnippet&mine=true&maxResults=1',$token);$items=is_array($data['items']??null)?$data['items']:[];$row=$items[0]??null;$id=is_array($row)?(string)($row['id']??''):'';$title=is_array($row)?trim((string)($row['snippet']['title']??'')):'';if(count($items)!==1||!preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$id)||$title===''||mb_strlen($title)>180)throw new RuntimeException('No accessible YouTube channel was returned for this account.');return['id'=>$id,'title'=>$title];
    }

    private function video(int$id,int$postId):array
    {
        if($this->root===''||!is_dir($this->root)||$id<1||$postId<1)throw new RuntimeException('The selected YouTube video is invalid.');$runtime=new Runtime($this->root);$installed=$runtime->read('installed');$db=Runtime::connect((array)($installed['database']??[]));$statement=$db->prepare('SELECT m.path,m.mime_type,m.size_bytes FROM media m INNER JOIN posts p ON p.id=? WHERE m.id=? AND m.status="active" AND (m.facility_id IS NULL OR m.facility_id=p.facility_id) LIMIT 1');$statement->execute([$postId,$id]);$item=$statement->fetch();$mime=(string)($item['mime_type']??'');$allowed=['video/mp4'=>'mp4','video/webm'=>'webm'];if(!$item||!isset($allowed[$mime]))throw new RuntimeException('Choose an available MP4 or WebM video from this post facility or the shared Media Library.');$path=(string)$item['path'];if(!preg_match('#^/media/[A-Za-z0-9._/-]+\.'.preg_quote($allowed[$mime],'#').'$#D',$path)||str_contains($path,'..'))throw new RuntimeException('The stored YouTube video path is invalid.');$mediaRoot=realpath($this->root.'/public/media');$file=realpath($this->root.'/public'.$path);if(!$mediaRoot||!$file||!str_starts_with(str_replace('\\','/',$file).'/',rtrim(str_replace('\\','/',$mediaRoot),'/').'/')||is_link($file)||!is_file($file))throw new RuntimeException('The selected YouTube video is unavailable.');$size=filesize($file);$actual=(new \finfo(FILEINFO_MIME_TYPE))->file($file);if($size===false||$size<1||$size>83886080||$actual!==$mime||(int)$item['size_bytes']!==$size)throw new RuntimeException('YouTube requires an unchanged MP4 or WebM file up to 80 MiB.');return[$file,$size,$mime];
    }

    private function upload(#[SensitiveParameter]string$token,string$file,int$size,string$mime,array$metadata,bool$notify):array
    {
        if(!in_array($mime,['video/mp4','video/webm'],true))throw new RuntimeException('The YouTube video format is invalid.');$location='';$body='';$url='https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet%2Cstatus&notifySubscribers='.($notify?'true':'false');$curl=curl_init($url);if($curl===false)throw new RuntimeException('YouTube upload could not be initialized.');$json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json; charset=UTF-8','X-Upload-Content-Type: '.$mime,'X-Upload-Content-Length: '.$size],CURLOPT_USERAGENT=>'SenseCMS-YouTube-Publisher/0.1',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($handle,string$line)use(&$location):int{if(str_starts_with(strtolower($line),'location:'))$location=trim(substr($line,9));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if($ok===false||$error!==''||$status===401)throw new RuntimeException('The YouTube access token must be refreshed.',401);if($status<200||$status>=300)$this->apiFailure($status,$body);$parts=parse_url($location);if(!filter_var($location,FILTER_VALIDATE_URL)||!is_array($parts)||($parts['scheme']??'')!=='https'||!in_array(strtolower((string)($parts['host']??'')),['www.googleapis.com','youtube.googleapis.com'],true)||(int)($parts['port']??443)!==443||($parts['path']??'')!=='/upload/youtube/v3/videos'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('YouTube returned an invalid resumable upload address.');
        $stream=fopen($file,'rb');if(!$stream)throw new RuntimeException('The selected YouTube video could not be opened.');$response='';$curl=curl_init($location);if($curl===false){fclose($stream);throw new RuntimeException('YouTube upload could not be initialized.');}curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>$size,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: '.$mime,'Content-Length: '.$size],CURLOPT_USERAGENT=>'SenseCMS-YouTube-Publisher/0.1',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>900,CURLOPT_LOW_SPEED_LIMIT=>1024,CURLOPT_LOW_SPEED_TIME=>60,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>524288)return 0;$response.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);fclose($stream);if($ok===false||$error!==''||$status===401)throw new RuntimeException('The YouTube access token must be refreshed.',401);if($status<200||$status>=300)$this->apiFailure($status,$response);$data=json_decode($response,true);if(!is_array($data))throw new RuntimeException('YouTube returned an invalid upload response.');return$data;
    }

    private function json(string$method,string$url,#[SensitiveParameter]string$token):array
    {
        if($url!=='https://www.googleapis.com/youtube/v3/channels?part=id%2Csnippet&mine=true&maxResults=1')throw new RuntimeException('The YouTube API address is invalid.');$response='';$curl=curl_init($url);if($curl===false)throw new RuntimeException('YouTube could not be initialized.');curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],CURLOPT_USERAGENT=>'SenseCMS-YouTube-Publisher/0.1',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if($ok===false||$error!==''||$status===401)throw new RuntimeException('The YouTube access token must be refreshed.',401);if($status<200||$status>=300)$this->apiFailure($status,$response);$data=json_decode($response,true);if(!is_array($data))throw new RuntimeException('YouTube returned an invalid response.');return$data;
    }

    private function apiFailure(int$status,string$body):never
    {
        $data=json_decode($body,true);$reason=is_array($data)?(string)($data['error']['errors'][0]['reason']??''):'';throw new RuntimeException(match($reason){'quotaExceeded','dailyLimitExceeded','uploadLimitExceeded'=>'YouTube upload quota is currently exhausted.','youtubeSignupRequired'=>'Create a YouTube channel for this Google account before connecting it.','forbiddenPrivacySetting'=>'This channel cannot use the selected YouTube privacy setting.',default=>$status===429?'YouTube temporarily limited publishing.':'YouTube rejected the video upload.'},$status===429?429:502);
    }

    private function client():YouTubeOnboardingClient
    {
        if($this->root===''||!is_dir($this->root))throw new RuntimeException('The YouTube provider runtime is unavailable.');$runtime=new Runtime($this->root);$installed=$runtime->read('installed');$root=$this->root;$baseUrl=$runtime->baseUrl();$config=require$root.'/config/workspace.php';return new YouTubeOnboardingClient($runtime->license(),(string)($config['integrations']['youtube_social_broker_url']??''),(string)$config['base_url']);
    }
};
