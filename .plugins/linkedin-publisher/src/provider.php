<?php

declare(strict_types=1);

namespace SenseCMS\LinkedIn;

use RuntimeException;

return new class {
    public function initialize(string $root): void{}

    public function verify(array $credentials): array
    {
        [$id,$name,$token]=$this->credentials($credentials);$data=$this->request('GET','https://api.linkedin.com/v2/userinfo','',$token);$remote=(string)($data['json']['sub']??'');$remoteName=trim((string)($data['json']['name']??''));
        if($remote!==$id||$remoteName===''||mb_strlen($remoteName)>180)throw new RuntimeException('The connected LinkedIn profile is unavailable to this access token.');
        $credentials['name']=$remoteName;return['external_id'=>$id,'display_name'=>$remoteName,'credentials'=>$credentials];
    }

    public function publish(array $credentials,array $payload): array
    {
        [$id,$name,$token]=$this->credentials($credentials);$message=trim((string)($payload['message']??''));$url=trim((string)($payload['url']??''));$title=trim((string)($payload['title']??''));$excerpt=trim(strip_tags((string)($payload['excerpt']??'')));
        if($message===''||mb_strlen($message)>3000)throw new RuntimeException('The LinkedIn message is empty or too long.');
        if(!$this->https($url))throw new RuntimeException('The LinkedIn destination URL is invalid.');
        $media=['status'=>'READY','originalUrl'=>$url];if($title!=='')$media['title']=['text'=>mb_substr($title,0,200)];if($excerpt!=='')$media['description']=['text'=>mb_substr($excerpt,0,256)];
        $body=json_encode(['author'=>'urn:li:person:'.$id,'lifecycleState'=>'PUBLISHED','specificContent'=>['com.linkedin.ugc.ShareContent'=>['shareCommentary'=>['text'=>$message],'shareMediaCategory'=>'ARTICLE','media'=>[$media]]],'visibility'=>['com.linkedin.ugc.MemberNetworkVisibility'=>'PUBLIC']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $data=$this->request('POST','https://api.linkedin.com/v2/ugcPosts',$body,$token);$urn=trim((string)($data['headers']['x-restli-id']??''));
        if(!preg_match('/^urn:li:(?:share|ugcPost):[0-9]{1,30}$/D',$urn))throw new RuntimeException('LinkedIn returned an invalid publication identifier.');
        return['external_id'=>$urn,'external_url'=>'https://www.linkedin.com/feed/update/'.$urn,'credentials'=>$credentials];
    }

    private function credentials(array $credentials): array
    {
        $id=(string)($credentials['member_id']??'');$name=trim((string)($credentials['name']??''));$token=(string)($credentials['access_token']??'');$expires=(int)($credentials['expires_at']??0);
        if(!preg_match('/^[A-Za-z0-9_-]{2,128}$/D',$id)||$name===''||mb_strlen($name)>180||strlen($token)<20||strlen($token)>4096||preg_match('/[\x00-\x20]/',$token)||$expires<=time()+120)throw new RuntimeException('The LinkedIn connection has expired. Reconnect this profile.');
        return[$id,$name,$token];
    }

    private function request(string $method,string $url,string $payload,string $token): array
    {
        if(!in_array($url,['https://api.linkedin.com/v2/userinfo','https://api.linkedin.com/v2/ugcPosts'],true))throw new RuntimeException('The LinkedIn API address is invalid.');
        $body='';$headers=[];$curl=curl_init($url);if($curl===false)throw new RuntimeException('LinkedIn publishing could not be initialized.');
        $requestHeaders=['Authorization: Bearer '.$token,'Accept: application/json','X-Restli-Protocol-Version: 2.0.0'];$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$requestHeaders,CURLOPT_USERAGENT=>'SenseCMS-LinkedIn-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($handle,string$line)use(&$headers):int{$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}];
        if($method==='POST'){$options[CURLOPT_POSTFIELDS]=$payload;$options[CURLOPT_HTTPHEADER][]='Content-Type: application/json';}
        curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$json=$body===''?[]:json_decode($body,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($json))throw new RuntimeException($status===429?'LinkedIn temporarily limited publishing.':($status===401?'The LinkedIn connection has expired. Reconnect this profile.':'LinkedIn rejected the publication request.'),$status===429?429:($status===401?401:502));
        return['json'=>$json,'headers'=>$headers,'status'=>$status];
    }

    private function https(string $url): bool
    {
        $parts=parse_url($url);return filter_var($url,FILTER_VALIDATE_URL)!==false&&is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!isset($parts['user'])&&!isset($parts['pass']);
    }
};
