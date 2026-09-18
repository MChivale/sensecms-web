<?php

declare(strict_types=1);

namespace SenseCMS\X;

use App\Core\Runtime;
use RuntimeException;

require_once __DIR__.'/XOnboardingClient.php';

return new class {
    private string $root='';

    public function initialize(string $root): void{$this->root=$root;}

    public function verify(array $credentials): array
    {
        $credentials=$this->fresh($credentials);$data=$this->request('GET','https://api.x.com/2/users/me?user.fields=name%2Cusername','',$credentials['access_token']);$user=$data['data']??null;
        if(!is_array($user)||(string)($user['id']??'')!==$credentials['user_id']||!preg_match('/^[A-Za-z0-9_]{1,15}$/D',(string)($user['username']??'')))throw new RuntimeException('The connected X account is unavailable to this access token.');
        $credentials['username']=(string)$user['username'];$credentials['name']=mb_substr(trim((string)($user['name']??$credentials['name'])),0,180);
        return['external_id'=>$credentials['user_id'],'display_name'=>'@'.$credentials['username'],'credentials'=>$credentials];
    }

    public function publish(array $credentials,array $payload): array
    {
        $credentials=$this->fresh($credentials);$message=trim((string)($payload['message']??''));$url=trim((string)($payload['url']??''));
        if($message===''||mb_strlen($message)>250)throw new RuntimeException('The X message is empty or too long.');
        if(!$this->https($url))throw new RuntimeException('The X destination URL is invalid.');
        $body=json_encode(['text'=>$message."\n\n".$url],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        try{$data=$this->request('POST','https://api.x.com/2/tweets',$body,$credentials['access_token']);}
        catch(RuntimeException$error){if($error->getCode()!==401)throw$error;$credentials=$this->fresh($credentials,true);$data=$this->request('POST','https://api.x.com/2/tweets',$body,$credentials['access_token']);}
        $id=(string)($data['data']['id']??'');if(!preg_match('/^[0-9]{1,30}$/D',$id))throw new RuntimeException('X returned an invalid publication identifier.');
        return['external_id'=>$id,'external_url'=>'https://x.com/'.$credentials['username'].'/status/'.$id,'credentials'=>$credentials];
    }

    private function fresh(array $credentials,bool $force=false): array
    {
        $user=(string)($credentials['user_id']??'');$username=(string)($credentials['username']??'');$name=trim((string)($credentials['name']??''));$access=(string)($credentials['access_token']??'');$refresh=(string)($credentials['refresh_token']??'');$expires=(int)($credentials['expires_at']??0);
        if(!preg_match('/^[0-9]{1,30}$/D',$user)||!preg_match('/^[A-Za-z0-9_]{1,15}$/D',$username)||$name===''||mb_strlen($name)>180||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<1)throw new RuntimeException('The X connection credentials are invalid.');
        if(!$force&&$expires>time()+120)return$credentials;
        $tokens=$this->client()->refresh($refresh);$credentials['access_token']=(string)$tokens['access_token'];$credentials['refresh_token']=(string)$tokens['refresh_token'];$credentials['expires_at']=(int)$tokens['expires_at'];return$credentials;
    }

    private function client(): XOnboardingClient
    {
        if($this->root===''||!is_dir($this->root))throw new RuntimeException('The X provider runtime is unavailable.');
        $runtime=new Runtime($this->root);$installed=$runtime->read('installed');$root=$this->root;$baseUrl=$runtime->baseUrl();$config=require$root.'/config/workspace.php';
        return new XOnboardingClient($runtime->license(),(string)($config['integrations']['x_social_broker_url']??''),(string)$config['base_url']);
    }

    private function request(string $method,string $url,string $payload,string $token): array
    {
        if(!in_array($url,['https://api.x.com/2/users/me?user.fields=name%2Cusername','https://api.x.com/2/tweets'],true))throw new RuntimeException('The X API address is invalid.');
        $response='';$curl=curl_init($url);if($curl===false)throw new RuntimeException('X publishing could not be initialized.');
        $headers=['Authorization: Bearer '.$token,'Accept: application/json'];$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-X-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}];
        if($method==='POST'){$options[CURLOPT_POSTFIELDS]=$payload;$options[CURLOPT_HTTPHEADER][]='Content-Type: application/json';}
        curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data)){$limited=$status===429;$unauthorized=$status===401;throw new RuntimeException($limited?'X temporarily limited publishing.':($unauthorized?'The X access token must be refreshed.':'X rejected the publication request.'),$limited?429:($unauthorized?401:502));}
        return$data;
    }

    private function https(string $url): bool
    {
        $parts=parse_url($url);return filter_var($url,FILTER_VALIDATE_URL)!==false&&is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!isset($parts['user'])&&!isset($parts['pass']);
    }
};
