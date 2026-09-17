<?php

declare(strict_types=1);

namespace SenseCMS\Facebook;

use App\Core\LicenseException;
use App\Core\LicenseService;
use RuntimeException;

final class MetaOnboardingClient
{
    private const OFFICIAL = 'https://www.sensecms.com/api/social/meta/v1';
    private string $base;

    public function __construct(private readonly LicenseService $license, string $base, private readonly string $installationUrl)
    {
        $this->base=rtrim($base,'/');
        if($this->base!==self::OFFICIAL)throw new RuntimeException('The official Meta connection service is not configured securely.');
    }

    public function start(): string
    {
        $data=$this->request('/start',['return_url'=>rtrim($this->installationUrl,'/').'/social-publishing/facebook/callback']);
        $url=(string)($data['authorize_url']??'');$parts=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strcasecmp((string)($parts['host']??''),'www.sensecms.com')!==0||(int)($parts['port']??443)!==443||!str_starts_with((string)($parts['path']??''),'/api/social/meta/v1/')||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('The Meta connection service returned an invalid authorization address.');
        return$url;
    }

    public function claim(string $claim): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$claim))throw new RuntimeException('The Meta connection result is invalid.');
        $data=$this->request('/claim',['claim'=>$claim]);$token=(string)($data['access_token']??'');$version=(string)($data['api_version']??'');
        if(!preg_match('/^[0-9]{5,30}$/D',(string)($data['page_id']??''))||trim((string)($data['page_name']??''))===''||mb_strlen((string)$data['page_name'])>180||!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$version)||strlen($token)<20||strlen($token)>4096||preg_match('/[\r\n]/',$token))throw new RuntimeException('The Meta connection service returned incomplete credentials.');
        return$data;
    }

    private function request(string $path,array $payload): array
    {
        try{$headers=$this->license->socialHeaders($this->installationUrl,$this->base);}catch(LicenseException){throw new RuntimeException('A valid Sense CMS license is required to connect Facebook.',403);}
        $headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$response='';$curl=curl_init($this->base.$path);
        if($curl===false)throw new RuntimeException('The Meta connection service could not be initialized.');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Facebook-Onboarding/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>131072)return 0;$response.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data))throw new RuntimeException(is_array($data)&&is_string($data['message']??null)?mb_substr($data['message'],0,240):'The Meta connection service is temporarily unavailable.',in_array($status,[403,410,422,429,503],true)?$status:502);
        return$data;
    }
}
