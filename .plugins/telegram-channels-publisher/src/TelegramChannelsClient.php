<?php

declare(strict_types=1);

namespace SenseCMS\TelegramChannels;

use App\Core\LicenseException;
use App\Core\LicenseService;
use Closure;
use RuntimeException;

final class TelegramChannelsClient
{
    private readonly Closure $http;

    public function __construct(private readonly LicenseService $license,private readonly string $base,private readonly string $installationUrl,?Closure $http=null)
    {
        if(rtrim($base,'/')!=='https://www.sensecms.com/api/telegram/v1')throw new RuntimeException('The central Telegram service is not configured securely.',503);$this->http=$http??$this->request(...);
    }

    public function verify(string$channel):array
    {
        return$this->identity($this->call('/channels/verify',['channel'=>trim($channel)]));
    }

    public function publish(array$credentials,array$payload):array
    {
        $identity=$this->stored($credentials);$message=trim((string)($payload['message']??''));$url=$this->https((string)($payload['url']??''));$event=(string)($payload['delivery_key']??'');
        if($message===''||mb_strlen($message)>3800||!preg_match('/^[a-f0-9]{64}$/D',$event))throw new RuntimeException('The Telegram channel publication payload is invalid.');$text=$message."\n\n".$url;if(mb_strlen($text)>4096)throw new RuntimeException('The Telegram channel publication exceeds the supported message limit.');
        $result=$this->call('/channels/publish',['channel_id'=>$identity['channel_id'],'event_key'=>$event,'text'=>$text]);$verified=$this->identity($result);if(!hash_equals($identity['channel_id'],$verified['channel_id']))throw new RuntimeException('The central Telegram service returned a different channel.',502);$messageId=$result['message_id']??null;if(!is_int($messageId)||$messageId<1)throw new RuntimeException('The central Telegram service returned an invalid publication identity.',502);
        $username=$verified['username'];$url=$username!==''?'https://t.me/'.$username.'/'.$messageId:'https://t.me/c/'.substr($verified['channel_id'],4).'/'.$messageId;return['external_id'=>$verified['channel_id'].':'.$messageId,'external_url'=>$url,'credentials'=>$verified];
    }

    private function stored(array$credentials):array
    {
        $identity=$this->identity($credentials);if(!hash_equals($identity['channel_id'],(string)($credentials['channel_id']??'')))throw new RuntimeException('The Telegram channel credentials are invalid.');return$identity;
    }

    private function identity(array$data):array
    {
        $id=(string)($data['channel_id']??'');$title=trim((string)($data['title']??''));$username=(string)($data['username']??'');$bot=(string)($data['bot_username']??'');
        if(!preg_match('/^-100[0-9]{6,16}$/D',$id)||$title===''||mb_strlen($title)>180||($username!==''&&!preg_match('/^[A-Za-z0-9_]{5,32}$/D',$username))||!preg_match('/^[A-Za-z0-9_]{5,32}$/D',$bot))throw new RuntimeException('The central Telegram service returned an invalid channel identity.',502);
        return['channel_id'=>$id,'title'=>$title,'username'=>$username,'bot_username'=>$bot];
    }

    private function call(string$path,array$payload):array
    {
        if(!in_array($path,['/channels/verify','/channels/publish'],true))throw new RuntimeException('The Telegram channel operation is invalid.',503);
        try{$headers=$this->license->telegramHeaders($this->installationUrl,$this->base);}catch(LicenseException){throw new RuntimeException('A valid CMS license is required to use shared Telegram delivery.',403);}$headers[]='Content-Type: application/json';[$status,$body]=($this->http)($this->base.$path,$headers,json_encode((object)$payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$data=json_decode($body,true);
        if($status<200||$status>=300||!is_array($data)||!str_starts_with(ltrim($body),'{'))throw new RuntimeException($status===422?'The Telegram channel or bot permission could not be verified.':'The central Telegram service is temporarily unavailable.',in_array($status,[403,404,409,422,429,502,503],true)?$status:502);return$data;
    }

    private function request(string$url,array$headers,string$payload):array
    {
        $body='';$curl=curl_init($url);if($curl===false)throw new RuntimeException('The Telegram channel service could not be initialized.',503);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Telegram-Channels/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>131072)return 0;$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return[$ok===false?0:$status,$body];
    }

    private function https(string$url):string
    {
        $url=trim($url);$parts=parse_url($url);if(strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||(int)($parts['port']??443)!==443||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('The Telegram publication address is invalid.');return$url;
    }
}
