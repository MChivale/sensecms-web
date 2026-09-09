<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class TelegramConnectionClient
{
    private readonly string $base;

    public function __construct(private readonly LicenseService $license,array$config,private readonly string$installationUrl)
    {
        $this->base=rtrim((string)($config['base_url']??''),'/');
        if($this->base!=='https://www.sensecms.com/api/telegram/v1')throw new RuntimeException('The central Telegram service is not configured securely.',503);
    }

    public function start(int$userId,string$name):array
    {
        $data=$this->request('/connect/start',['user_id'=>$userId,'user_name'=>mb_substr(trim($name),0,120)]);$url=(string)($data['connect_url']??'');$token=(string)($data['request_token']??'');$parts=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strcasecmp((string)($parts['host']??''),'t.me')!==0||array_intersect(['user','pass','port','fragment'],array_keys($parts))||!preg_match('#^/[A-Za-z0-9_]{5,32}$#D',(string)($parts['path']??''))||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)||($parts['query']??'')!=='start='.$token)throw new RuntimeException('The central Telegram service returned an invalid connection address.',502);
        return['connect_url'=>$url,'request_token'=>$token,'expires_in'=>max(60,min(600,(int)($data['expires_in']??600)))];
    }

    public function status(int$userId,string$requestToken=''):array
    {
        if($requestToken!==''&&!preg_match('/^[A-Za-z0-9_-]{43}$/D',$requestToken))throw new RuntimeException('The Telegram connection request is invalid.');
        $data=$this->request('/connect/status',['user_id'=>$userId,'request_token'=>$requestToken]);$account=is_array($data['account']??null)?$data['account']:null;
        return['connected'=>!empty($data['connected']),'account'=>$account?['username'=>mb_substr((string)($account['username']??''),0,32),'name'=>mb_substr((string)($account['name']??''),0,120),'connected_at'=>(string)($account['connected_at']??'')]:null,'request_status'=>(string)($data['request_status']??'')];
    }

    public function recipients():array
    {
        $data=$this->request('/recipients',[]);$users=[];foreach((array)($data['user_ids']??[])as$id)if(($id=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))!==false)$users[]=(int)$id;return array_values(array_unique($users));
    }

    public function disconnect(int$userId):void{$this->request('/connect/disconnect',['user_id'=>$userId]);}

    public function deliver(int$userId,string$eventKey,string$title,string$body):void
    {
        $data=$this->request('/deliver',['user_id'=>$userId,'event_key'=>$eventKey,'title'=>mb_substr($title,0,180),'body'=>mb_substr($body,0,3500)]);if(empty($data['sent']))throw new RuntimeException('The central Telegram service did not confirm delivery.',502);
    }

    private function request(string$path,array$payload):array
    {
        try{$headers=$this->license->telegramHeaders($this->installationUrl,$this->base);}catch(LicenseException){throw new RuntimeException('A valid CMS license is required to use shared Telegram delivery.',403);}
        $headers[]='Content-Type: application/json';$body=json_encode((object)$payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$response='';$curl=curl_init($this->base.$path);if($curl===false)throw new RuntimeException('The Telegram connection service could not be initialized.',503);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Telegram-Connection/1.0',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>131072)return 0;$response.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$status<200||$status>=300||!is_array($data)||!str_starts_with(ltrim($response),'{'))throw new RuntimeException('The central Telegram service is temporarily unavailable.',in_array($status,[403,404,409,410,422,429,502,503],true)?$status:502);return$data;
    }
}
