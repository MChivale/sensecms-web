<?php

declare(strict_types=1);

namespace SenseCMS\X;

use App\Core\LicenseException;
use App\Core\LicenseService;
use RuntimeException;

final class XOnboardingClient
{
    private const OFFICIAL='https://www.sensecms.com/api/social/x/v1';
    private string $base;

    public function __construct(private readonly LicenseService $license,string $base,private readonly string $installationUrl)
    {
        $this->base=rtrim($base,'/');
        if($this->base!==self::OFFICIAL)throw new RuntimeException('The official X connection service is not configured securely.');
    }

    public function start(): string
    {
        $data=$this->request('/start',['return_url'=>rtrim($this->installationUrl,'/').'/social-publishing/x/callback']);
        $url=(string)($data['authorize_url']??'');$parts=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strcasecmp((string)($parts['host']??''),'www.sensecms.com')!==0||(int)($parts['port']??443)!==443||!str_starts_with((string)($parts['path']??''),'/api/social/x/v1/')||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('The X connection service returned an invalid authorization address.');
        return$url;
    }

    public function claim(string $claim): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$claim))throw new RuntimeException('The X connection result is invalid.');
        return$this->credentials($this->request('/claim',['claim'=>$claim]));
    }

    public function refresh(string $refreshToken): array
    {
        if(strlen($refreshToken)<20||strlen($refreshToken)>4096||preg_match('/[\x00-\x20]/',$refreshToken))throw new RuntimeException('The X refresh credential is invalid.');
        return$this->credentials($this->request('/refresh',['refresh_token'=>$refreshToken]),false);
    }

    private function credentials(array $data,bool $identity=true): array
    {
        $access=(string)($data['access_token']??'');$refresh=(string)($data['refresh_token']??'');$expires=(int)($data['expires_at']??0);
        if(strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<=time())throw new RuntimeException('The X connection service returned incomplete credentials.');
        if($identity&&(!preg_match('/^[0-9]{1,30}$/D',(string)($data['user_id']??''))||!preg_match('/^[A-Za-z0-9_]{1,15}$/D',(string)($data['username']??''))||trim((string)($data['name']??''))===''||mb_strlen((string)$data['name'])>180))throw new RuntimeException('The X connection service returned an invalid account identity.');
        return$data;
    }

    private function request(string $path,array $payload): array
    {
        try{$headers=$this->license->socialHeaders($this->installationUrl,$this->base);}catch(LicenseException){throw new RuntimeException('A valid Sense CMS license is required to connect X.',403);}
        $headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$response='';$curl=curl_init($this->base.$path);
        if($curl===false)throw new RuntimeException('The X connection service could not be initialized.');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-X-Onboarding/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>131072)return 0;$response.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data))throw new RuntimeException(is_array($data)&&is_string($data['message']??null)?mb_substr($data['message'],0,240):'The X connection service is temporarily unavailable.',in_array($status,[403,410,422,429,503],true)?$status:502);
        return$data;
    }
}
