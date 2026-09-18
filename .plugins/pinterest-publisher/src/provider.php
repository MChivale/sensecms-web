<?php

declare(strict_types=1);

namespace SenseCMS\Pinterest;

use App\Core\Runtime;
use RuntimeException;

require_once __DIR__.'/PinterestOnboardingClient.php';

return new class {
    private string$root='';
    public function initialize(string$root):void{$this->root=$root;}

    public function verify(array$credentials):array
    {
        $credentials=$this->fresh($credentials);$board=$this->request('GET','https://api.pinterest.com/v5/boards/'.$credentials['board_id'],'',$credentials['access_token']);$id=(string)($board['id']??'');$name=trim((string)($board['name']??''));$owner=(string)($board['owner']['username']??$credentials['username']);
        if($id!==$credentials['board_id']||$name===''||mb_strlen($name)>180||!preg_match('/^[A-Za-z0-9_]{3,30}$/D',$owner))throw new RuntimeException('The connected Pinterest board is unavailable to this access token.');
        $credentials['board_name']=$name;$credentials['username']=$owner;return['external_id'=>$id,'display_name'=>'@'.$owner.' · '.$name,'credentials'=>$credentials];
    }

    public function publish(array$credentials,array$payload):array
    {
        $credentials=$this->fresh($credentials);$message=trim(strip_tags((string)($payload['message']??'')));$title=trim(strip_tags((string)($payload['title']??'')));$url=$this->https((string)($payload['url']??''),'destination');$image=$this->https((string)($payload['image_url']??''),'featured image');
        if($message===''||mb_strlen($message)>500)throw new RuntimeException('The Pinterest description is empty or longer than 500 characters.');if($title==='')$title=mb_substr($message,0,100);$title=mb_substr($title,0,100);
        $body=json_encode(['board_id'=>$credentials['board_id'],'title'=>$title,'description'=>$message,'link'=>$url,'alt_text'=>$title,'media_source'=>['source_type'=>'image_url','url'=>$image,'is_standard'=>true]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        try{$data=$this->request('POST','https://api.pinterest.com/v5/pins',$body,$credentials['access_token']);}catch(RuntimeException$error){if($error->getCode()!==401)throw$error;$credentials=$this->fresh($credentials,true);$data=$this->request('POST','https://api.pinterest.com/v5/pins',$body,$credentials['access_token']);}
        $id=(string)($data['id']??'');if(!preg_match('/^[0-9]{1,30}$/D',$id)||(string)($data['board_id']??$credentials['board_id'])!==$credentials['board_id'])throw new RuntimeException('Pinterest returned an invalid Pin identity.');
        return['external_id'=>$id,'external_url'=>'https://www.pinterest.com/pin/'.$id.'/','credentials'=>$credentials];
    }

    private function fresh(array$credentials,bool$force=false):array
    {
        foreach(['user_id','board_id']as$key)if(!preg_match('/^[0-9]{1,30}$/D',(string)($credentials[$key]??'')))throw new RuntimeException('The Pinterest connection credentials are invalid.');$username=(string)($credentials['username']??'');$board=trim((string)($credentials['board_name']??''));$access=(string)($credentials['access_token']??'');$refresh=(string)($credentials['refresh_token']??'');$expires=(int)($credentials['expires_at']??0);$refreshExpires=(int)($credentials['refresh_expires_at']??0);
        if(!preg_match('/^[A-Za-z0-9_]{3,30}$/D',$username)||$board===''||mb_strlen($board)>180||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<1||$refreshExpires<=time())throw new RuntimeException('The Pinterest connection has expired. Reconnect this board.');
        if(!$force&&$expires>time()+300)return$credentials;$tokens=$this->client()->refresh($refresh);$credentials['access_token']=(string)$tokens['access_token'];$credentials['refresh_token']=(string)$tokens['refresh_token'];$credentials['expires_at']=(int)$tokens['expires_at'];$credentials['refresh_expires_at']=(int)$tokens['refresh_expires_at'];return$credentials;
    }

    private function client():PinterestOnboardingClient
    {
        if($this->root===''||!is_dir($this->root))throw new RuntimeException('The Pinterest provider runtime is unavailable.');$runtime=new Runtime($this->root);$installed=$runtime->read('installed');$root=$this->root;$baseUrl=$runtime->baseUrl();$config=require$root.'/config/workspace.php';return new PinterestOnboardingClient($runtime->license(),(string)($config['integrations']['pinterest_social_broker_url']??''),(string)$config['base_url']);
    }

    private function request(string$method,string$url,string$payload,string$token):array
    {
        if($url!=='https://api.pinterest.com/v5/pins'&&!preg_match('#^https://api\.pinterest\.com/v5/boards/[0-9]{1,30}$#D',$url))throw new RuntimeException('The Pinterest API address is invalid.');$response='';$curl=curl_init($url);if($curl===false)throw new RuntimeException('Pinterest publishing could not be initialized.');$headers=['Authorization: Bearer '.$token,'Accept: application/json'];$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Pinterest-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}];if($method==='POST'){$options[CURLOPT_POSTFIELDS]=$payload;$options[CURLOPT_HTTPHEADER][]='Content-Type: application/json';}curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data))throw new RuntimeException($status===429?'Pinterest temporarily limited publishing.':($status===401?'The Pinterest access token must be refreshed.':'Pinterest rejected the request.'),$status===429?429:($status===401?401:502));return$data;
    }

    private function https(string$url,string$kind):string
    {
        $url=trim($url);$parts=parse_url($url);if(strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||(int)($parts['port']??443)!==443||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('The Pinterest '.$kind.' URL is invalid.');return$url;
    }
};
