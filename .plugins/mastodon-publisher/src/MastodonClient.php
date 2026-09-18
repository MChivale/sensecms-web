<?php

declare(strict_types=1);

namespace SenseCMS\Mastodon;

use RuntimeException;

final class MastodonClient
{
    private const SCOPE = 'read:accounts write:statuses';
    private const PATHS = ['/api/v1/apps','/oauth/token','/api/v1/accounts/verify_credentials','/api/v1/statuses'];

    public function begin(string $input,string $redirect,string $website): array
    {
        $origin=self::origin($input);$redirect=$this->httpsUrl($redirect);$website=$this->httpsUrl($website);
        $app=$this->request($origin,'POST','/api/v1/apps',['client_name'=>'Sense CMS Social Publishing','redirect_uris'=>$redirect,'scopes'=>self::SCOPE,'website'=>$website]);
        $client=$this->credential($app['client_id']??null,'client ID');$secret=$this->credential($app['client_secret']??null,'client secret');
        $state=self::b64(random_bytes(32));$verifier=self::b64(random_bytes(32));$query=http_build_query(['client_id'=>$client,'scope'=>self::SCOPE,'redirect_uri'=>$redirect,'response_type'=>'code','state'=>$state,'code_challenge'=>self::b64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986);
        return['state'=>$state,'authorize_url'=>$origin.'/oauth/authorize?'.$query,'pending'=>['origin'=>$origin,'client_id'=>$client,'client_secret'=>$secret,'verifier'=>$verifier,'redirect'=>$redirect,'expires_at'=>time()+600]];
    }

    public function finish(array $pending,string $code,string $redirect): array
    {
        $origin=$this->storedOrigin($pending['origin']??null);$client=$this->credential($pending['client_id']??null,'client ID');$secret=$this->credential($pending['client_secret']??null,'client secret');$verifier=(string)($pending['verifier']??'');$expected=$this->httpsUrl((string)($pending['redirect']??''));
        if((int)($pending['expires_at']??0)<time()||!hash_equals($expected,$this->httpsUrl($redirect))||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$verifier)||strlen($code)<8||strlen($code)>4096||preg_match('/[\x00-\x20]/',$code))throw new RuntimeException('The Mastodon authorization result is invalid.');
        $token=$this->request($origin,'POST','/oauth/token',['grant_type'=>'authorization_code','client_id'=>$client,'client_secret'=>$secret,'redirect_uri'=>$expected,'code'=>$code,'code_verifier'=>$verifier,'scope'=>self::SCOPE]);$access=$this->credential($token['access_token']??null,'access token');
        if(strcasecmp((string)($token['token_type']??'Bearer'),'Bearer')!==0||!$this->scopes((string)($token['scope']??'')))throw new RuntimeException('The Mastodon token does not grant the required publishing permissions.');
        return$this->identity($origin,$access,['instance'=>$origin,'client_id'=>$client,'client_secret'=>$secret,'access_token'=>$access,'scope'=>self::SCOPE]);
    }

    public function verify(array $credentials): array
    {
        $origin=$this->storedOrigin($credentials['instance']??null);$access=$this->credential($credentials['access_token']??null,'access token');$id=(string)($credentials['account_id']??'');
        if(!preg_match('/^[0-9]{1,40}$/D',$id))throw new RuntimeException('The Mastodon connection credentials are invalid.');
        $verified=$this->identity($origin,$access,$credentials);if(!hash_equals($id,(string)$verified['credentials']['account_id']))throw new RuntimeException('The connected Mastodon account is unavailable to this access token.');return$verified;
    }

    public function publish(array $credentials,array $payload): array
    {
        $origin=$this->storedOrigin($credentials['instance']??null);$access=$this->credential($credentials['access_token']??null,'access token');$account=(string)($credentials['account_id']??'');$message=trim((string)($payload['message']??''));$url=$this->httpsUrl((string)($payload['url']??''));$post=(int)($payload['post_id']??0);
        if(!preg_match('/^[0-9]{1,40}$/D',$account)||$message===''||mb_strlen($message)>400||$post<1)throw new RuntimeException('The Mastodon publication payload is invalid.');$status=$message."\n\n".$url;if(mb_strlen($status)>500)throw new RuntimeException('The Mastodon status exceeds the supported 500-character limit.');
        $idempotency=hash('sha256',$origin."\0".$account."\0".$post."\0".json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$data=$this->request($origin,'POST','/api/v1/statuses',['status'=>$status,'visibility'=>'public'],$access,$idempotency);$id=(string)($data['id']??'');$external=(string)($data['url']??'');
        if(!preg_match('/^[0-9]{1,40}$/D',$id)||!$this->sameOriginUrl($external,$origin))throw new RuntimeException('Mastodon returned an invalid publication identity.');return['external_id'=>$id,'external_url'=>$external];
    }

    public static function origin(string $input): string
    {
        $input=trim($input);if($input!==''&&!str_contains($input,'://'))$input='https://'.$input;$parts=parse_url($input);$host=strtolower(rtrim((string)($parts['host']??''),'.'));
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||$host===''||strlen($host)>120||(int)($parts['port']??443)!==443||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array((string)($parts['path']??''),['','/'],true)||filter_var($host,FILTER_VALIDATE_IP)!==false||!str_contains($host,'.')||!preg_match('/^(?=.{1,120}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host))throw new RuntimeException('Enter a public Mastodon server hostname, for example mastodon.social.');
        return'https://'.$host;
    }

    private function identity(string $origin,string $access,array $credentials): array
    {
        $data=$this->request($origin,'GET','/api/v1/accounts/verify_credentials',[],$access);$id=(string)($data['id']??'');$username=(string)($data['username']??'');$acct=(string)($data['acct']??'');$profile=(string)($data['url']??'');$host=(string)parse_url($origin,PHP_URL_HOST);
        if(!preg_match('/^[0-9]{1,40}$/D',$id)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$username)||$acct===''||mb_strlen($acct)>180||preg_match('/[\x00-\x20]/u',$acct)||!$this->sameOriginUrl($profile,$origin))throw new RuntimeException('Mastodon returned an invalid account identity.');
        $credentials['account_id']=$id;$credentials['username']=$username;$credentials['acct']=$acct;$credentials['profile_url']=$profile;$display='@'.$acct.(str_contains($acct,'@')?'':'@'.$host);return['external_id'=>$host.':'.$id,'display_name'=>$display,'credentials'=>$credentials];
    }

    private function request(string $origin,string $method,string $path,array $fields,?string $token=null,?string $idempotency=null): array
    {
        if(!in_array($path,self::PATHS,true))throw new RuntimeException('The Mastodon API address is invalid.');$host=(string)parse_url($origin,PHP_URL_HOST);$resolved=$this->resolve($host);$response='';$curl=curl_init($origin.$path);if($curl===false)throw new RuntimeException('The Mastodon request could not be initialized.');
        $headers=['Accept: application/json'];if($token!==null)$headers[]='Authorization: Bearer '.$token;if($idempotency!==null)$headers[]='Idempotency-Key: '.$idempotency;$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'SenseCMS-Mastodon-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>$resolved,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>524288)return 0;$response.=$chunk;return strlen($chunk);}];
        if($method==='POST'){$options[CURLOPT_POSTFIELDS]=http_build_query($fields,'','&',PHP_QUERY_RFC3986);$options[CURLOPT_HTTPHEADER][]='Content-Type: application/x-www-form-urlencoded';}curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($response,true);
        if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data)){if($status===429)throw new RuntimeException('The Mastodon server temporarily limited requests.',429);if($status===401||$status===403)throw new RuntimeException('The Mastodon authorization is no longer valid.',401);throw new RuntimeException('The Mastodon server rejected the request.',in_array($status,[422,503],true)?$status:502);}return$data;
    }

    private function resolve(string $host): array
    {
        $records=dns_get_record($host,DNS_A|DNS_AAAA);if(!is_array($records)||$records===[])throw new RuntimeException('The Mastodon server hostname could not be resolved.');$ips=[];
        foreach($records as$record){$ip=(string)($record['ip']??$record['ipv6']??'');if($ip==='')continue;if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)throw new RuntimeException('The Mastodon server resolves to a non-public address.');$ips[$ip]=true;}
        if($ips===[]||count($ips)>8)throw new RuntimeException('The Mastodon server DNS response is invalid.');return array_map(static fn(string$ip):string=>$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip),array_keys($ips));
    }

    private function storedOrigin(mixed $origin): string
    {
        if(!is_string($origin))throw new RuntimeException('The Mastodon connection credentials are invalid.');$normalized=self::origin($origin);if(!hash_equals($normalized,$origin))throw new RuntimeException('The Mastodon connection credentials are invalid.');return$normalized;
    }

    private function credential(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<8||strlen($value)>4096||preg_match('/[\x00-\x20]/',$value))throw new RuntimeException('Mastodon returned an invalid '.$label.'.');return$value;
    }

    private function scopes(string $scope): bool
    {
        $items=array_values(array_filter(preg_split('/[\s,]+/',trim($scope))?:[]));return in_array('read:accounts',$items,true)&&in_array('write:statuses',$items,true);
    }

    private function httpsUrl(string $url): string
    {
        $url=trim($url);$parts=parse_url($url);if(strlen($url)>2048||filter_var($url,FILTER_VALIDATE_URL)===false||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||(int)($parts['port']??443)!==443||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('The Mastodon HTTPS address is invalid.');return$url;
    }

    private function sameOriginUrl(string $url,string $origin): bool
    {
        try{$safe=$this->httpsUrl($url);}catch(RuntimeException){return false;}$parts=parse_url($safe);return strtolower((string)$parts['host'])===(string)parse_url($origin,PHP_URL_HOST)&&(int)($parts['port']??443)===443;
    }

    private static function b64(string $value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
}
