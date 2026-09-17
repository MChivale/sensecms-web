<?php

declare(strict_types=1);

namespace SenseCMS\Facebook;

use RuntimeException;

return new class {
    public function verify(array $credentials): array
    {
        [$page,$token,$version]=$this->credentials($credentials);
        $data=$this->request('GET',$version.'/'.$page,['fields'=>'id,name'],$token);
        if((string)($data['id']??'')!==$page||trim((string)($data['name']??''))==='')throw new RuntimeException('The connected Facebook Page is unavailable to this access token.');
        return['external_id'=>$page,'display_name'=>mb_substr((string)$data['name'],0,180)];
    }

    public function publish(array $credentials,array $payload): array
    {
        [$page,$token,$version]=$this->credentials($credentials);$message=trim((string)($payload['message']??''));$url=trim((string)($payload['url']??''));$image=trim((string)($payload['image_url']??''));
        if($message===''||mb_strlen($message)>5000)throw new RuntimeException('The Facebook message is empty or too long.');
        if(!$this->https($url))throw new RuntimeException('The Facebook destination URL is invalid.');
        if($image!==''&&!$this->https($image))throw new RuntimeException('The Facebook image URL is invalid.');
        if($image!=='')$data=$this->request('POST',$version.'/'.$page.'/photos',['url'=>$image,'caption'=>$message."\n\n".$url],$token);
        else$data=$this->request('POST',$version.'/'.$page.'/feed',['message'=>$message,'link'=>$url],$token);
        $id=(string)($data['post_id']??$data['id']??'');
        if(!preg_match('/^[0-9]+(?:_[0-9]+)?$/D',$id))throw new RuntimeException('Facebook returned an invalid publication identifier.');
        return['external_id'=>$id,'external_url'=>''];
    }

    private function credentials(array $credentials): array
    {
        $page=(string)($credentials['page_id']??'');$token=(string)($credentials['access_token']??'');$version=(string)($credentials['api_version']??'');
        if(!preg_match('/^[0-9]{5,30}$/D',$page)||strlen($token)<20||strlen($token)>4096||preg_match('/[\r\n]/',$token)||!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$version))throw new RuntimeException('The Facebook connection credentials are invalid.');
        return[$page,$token,$version];
    }

    private function request(string $method,string $path,array $fields,string $token): array
    {
        if(!preg_match('#^v[0-9]{1,2}\.[0-9]/[0-9]+(?:/(?:feed|photos))?$#D',$path))throw new RuntimeException('The Facebook Graph path is invalid.');
        $url='https://graph.facebook.com/'.$path;$response='';
        if($method==='GET')$url.='?'.http_build_query($fields,'','&',PHP_QUERY_RFC3986);
        $curl=curl_init($url);if($curl===false)throw new RuntimeException('Facebook publishing could not be initialized.');
        $options=[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],CURLOPT_USERAGENT=>'SenseCMS-Facebook-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}];
        if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=http_build_query($fields,'','&',PHP_QUERY_RFC3986);$options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';}
        curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data)){$code=(int)($data['error']['code']??0);$limited=$status===429||$code===4;throw new RuntimeException($limited?'Facebook temporarily limited publishing.':'Facebook rejected the publication request.',$limited?429:502);}
        return$data;
    }

    private function https(string $url): bool
    {
        $parts=parse_url($url);return filter_var($url,FILTER_VALIDATE_URL)!==false&&is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!isset($parts['user'])&&!isset($parts['pass']);
    }
};
