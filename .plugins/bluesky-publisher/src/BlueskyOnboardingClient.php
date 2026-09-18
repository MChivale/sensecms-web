<?php

declare(strict_types=1);

namespace SenseCMS\Bluesky;

use App\Core\LicenseException;
use App\Core\LicenseService;
use RuntimeException;

final class BlueskyOnboardingClient
{
    private const OFFICIAL='https://www.sensecms.com/api/social/bluesky/v1';
    private string $base;

    public function __construct(private readonly LicenseService $license,string $base,private readonly string $installationUrl)
    {
        $this->base=rtrim($base,'/');
        if($this->base!==self::OFFICIAL)throw new RuntimeException('The official Bluesky connection service is not configured securely.');
    }

    public function start(): string
    {
        $data=$this->request('/start',['return_url'=>rtrim($this->installationUrl,'/').'/social-publishing/bluesky/callback']);
        $url=(string)($data['authorize_url']??'');$parts=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strcasecmp((string)($parts['host']??''),'www.sensecms.com')!==0||(int)($parts['port']??443)!==443||($parts['path']??'')!=='/api/social/bluesky/v1/authorize'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('The Bluesky connection service returned an invalid authorization address.');
        return$url;
    }

    public function claim(string $claim): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$claim))throw new RuntimeException('The Bluesky connection result is invalid.');
        return$this->credentials($this->request('/claim',['claim'=>$claim]),true);
    }

    public function refresh(array $credentials): array
    {
        foreach(['refresh_token','dpop_private_key','dpop_x','dpop_y']as$field)if(!is_string($credentials[$field]??null)||$credentials[$field]==='')throw new RuntimeException('The Bluesky refresh credentials are invalid.');
        return$this->credentials($this->request('/refresh',array_intersect_key($credentials,array_flip(['refresh_token','dpop_private_key','dpop_x','dpop_y','auth_nonce']))),false);
    }

    private function credentials(array $data,bool $identity): array
    {
        $access=(string)($data['access_token']??'');$refresh=(string)($data['refresh_token']??'');$expires=(int)($data['expires_at']??0);$scope=(string)($data['scope']??'');
        if(strlen($access)<20||strlen($access)>8192||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>8192||preg_match('/[\x00-\x20]/',$refresh)||$expires<=time()||!in_array('atproto',preg_split('/\s+/',$scope)?:[],true)||!str_contains($scope,'repo:app.bsky.feed.post'))throw new RuntimeException('The Bluesky connection service returned incomplete credentials.');
        if(!str_starts_with((string)($data['dpop_private_key']??''),'-----BEGIN PRIVATE KEY-----')||!preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($data['dpop_x']??''))||!preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($data['dpop_y']??'')))throw new RuntimeException('The Bluesky connection service returned invalid proof credentials.');
        if($identity&&(!preg_match('/^did:plc:[a-z2-7]{24}$/D',(string)($data['did']??''))||!preg_match('/^[a-z0-9](?:[a-z0-9.-]{1,251}[a-z0-9])?$/D',(string)($data['handle']??''))||trim((string)($data['name']??''))===''))throw new RuntimeException('The Bluesky connection service returned an invalid account identity.');
        return$data;
    }

    private function request(string $path,array $payload): array
    {
        try{$headers=$this->license->socialHeaders($this->installationUrl,$this->base);}catch(LicenseException){throw new RuntimeException('A valid Sense CMS license is required to connect Bluesky.',403);}
        $headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$response='';$curl=curl_init($this->base.$path);
        if($curl===false)throw new RuntimeException('The Bluesky connection service could not be initialized.');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Bluesky-Onboarding/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data))throw new RuntimeException(is_array($data)&&is_string($data['message']??null)?mb_substr($data['message'],0,240):'The Bluesky connection service is temporarily unavailable.',in_array($status,[403,410,422,429,503],true)?$status:502);
        return$data;
    }
}
