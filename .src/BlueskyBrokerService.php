<?php

declare(strict_types=1);

namespace SenseCMS\Website;

use App\Core\LicenseClient;
use App\Core\LicenseException;
use App\Core\Runtime;
use Closure;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Confidential AT Protocol OAuth broker for Bluesky-hosted accounts. */
final class BlueskyBrokerService
{
    public const CLIENT_ID='https://www.sensecms.com/api/social/bluesky/v1/client-metadata.json';
    private const ORIGIN='https://www.sensecms.com';
    private const CALLBACK=self::ORIGIN.'/api/social/bluesky/v1/callback';
    private const PDS='https://bsky.social';
    private const SCOPE='atproto repo:app.bsky.feed.post?action=create';
    private readonly Closure $http;
    private readonly string $key;
    private readonly string $root;
    private readonly array $clientJwk;

    public function __construct(string$root,#[SensitiveParameter]string$key,#[SensitiveParameter]private readonly string$clientPrivate,?Closure$http=null)
    {
        if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||is_link($root)||!is_dir($root))throw new RuntimeException('Private Bluesky storage is unavailable.',503);$this->root=realpath($root)?:throw new RuntimeException('Private Bluesky storage is unavailable.',503);if(PHP_OS_FAMILY!=='Windows'&&(fileperms($this->root)&0077))throw new RuntimeException('Bluesky storage must be private.',503);$this->key=$key;$this->clientJwk=AtprotoCrypto::publicJwk($clientPrivate);$this->http=$http??$this->request(...);
    }

    public function clientMetadata(): array{return['client_id'=>self::CLIENT_ID,'application_type'=>'web','client_name'=>'Sense CMS Bluesky Publisher','client_uri'=>self::ORIGIN.'/','logo_uri'=>self::ORIGIN.'/favicon.svg','tos_uri'=>self::ORIGIN.'/terms','policy_uri'=>self::ORIGIN.'/privacy','grant_types'=>['authorization_code','refresh_token'],'scope'=>self::SCOPE,'response_types'=>['code'],'redirect_uris'=>[self::CALLBACK],'token_endpoint_auth_method'=>'private_key_jwt','token_endpoint_auth_signing_alg'=>'ES256','dpop_bound_access_tokens'=>true,'jwks'=>$this->jwks()];}
    public function jwks(): array{return['keys'=>[$this->clientJwk+['use'=>'sig','alg'=>'ES256','kid'=>AtprotoCrypto::kid($this->clientJwk)]]];}

    public function start(#[SensitiveParameter]array$server,array$input): array
    {
        $domain=$this->authenticate($server);$return=(string)($input['return_url']??'');if($return!==$domain.'/social-publishing/bluesky/callback')throw new RuntimeException('Invalid Bluesky connection return address.',422);
        return$this->locked(function()use($domain,$return):array{$this->rate($domain,20,3600);$this->cleanup();$request=$this->token();$this->write('request',$request,['domain'=>$domain,'return_url'=>$return,'expires'=>time()+600,'used'=>false]);return['authorize_url'=>self::ORIGIN.'/api/social/bluesky/v1/authorize?request='.rawurlencode($request),'expires_in'=>600];});
    }

    public function authorize(string$request): string
    {
        $this->assertToken($request);return$this->locked(function()use($request):string{$record=$this->read('request',$request);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This Bluesky connection request has expired.',410);$meta=$this->metadata();[$dpopPrivate,$dpopJwk]=AtprotoCrypto::generate();$verifier=$this->token();$fields=['client_id'=>self::CLIENT_ID,'response_type'=>'code','redirect_uri'=>self::CALLBACK,'scope'=>self::SCOPE,'state'=>$request,'code_challenge'=>AtprotoCrypto::b64(hash('sha256',$verifier,true)),'code_challenge_method'=>'S256','client_assertion_type'=>'urn:ietf:params:oauth:client-assertion-type:jwt-bearer','client_assertion'=>AtprotoCrypto::assertion($meta['issuer'],$this->clientPrivate,$this->clientJwk)];[$par,$nonce]=$this->oauthPost($meta['par'],$fields,$dpopPrivate,$dpopJwk,null);$requestUri=(string)($par['request_uri']??'');if($requestUri===''||strlen($requestUri)>2048||preg_match('/[\x00-\x20]/',$requestUri))throw new RuntimeException('Bluesky did not accept the authorization request.',502);$record+=['verifier'=>$verifier,'dpop_private_key'=>$dpopPrivate,'dpop_x'=>$dpopJwk['x'],'dpop_y'=>$dpopJwk['y'],'auth_nonce'=>$nonce,'issuer'=>$meta['issuer'],'token_endpoint'=>$meta['token'],'scope'=>self::SCOPE];$this->write('request',$request,$record);return$meta['authorization'].'?'.http_build_query(['client_id'=>self::CLIENT_ID,'request_uri'=>$requestUri],'','&',PHP_QUERY_RFC3986);});
    }

    public function callback(array$query): string
    {
        $state=(string)($query['state']??'');$this->assertToken($state);return$this->locked(function()use($state,$query):string{$record=$this->read('request',$state);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This Bluesky connection request has expired.',410);$record['used']=true;$this->write('request',$state,$record);if(isset($query['error']))return(string)$record['return_url'];if(!hash_equals((string)$record['issuer'],(string)($query['iss']??'')))throw new RuntimeException('Bluesky returned an unexpected authorization issuer.',422);$code=(string)($query['code']??'');if($code===''||strlen($code)>2048||preg_match('/[\x00-\x20]/',$code))throw new RuntimeException('Bluesky did not return a valid authorization code.',422);$tokens=$this->tokenRequest(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>self::CALLBACK,'code_verifier'=>(string)$record['verifier']],$record);$did=(string)$tokens['sub'];$pds=$this->accountPds($did);$profile=$this->profile($did);$claim=$this->token();$this->write('claim',$claim,['domain'=>$record['domain'],'did'=>$did,'handle'=>$profile['handle'],'name'=>$profile['name'],'pds'=>$pds,'access_token'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token'],'expires_at'=>$tokens['expires_at'],'scope'=>$tokens['scope'],'dpop_private_key'=>$record['dpop_private_key'],'dpop_x'=>$record['dpop_x'],'dpop_y'=>$record['dpop_y'],'auth_nonce'=>$tokens['auth_nonce'],'resource_nonce'=>'','expires'=>time()+600]);return(string)$record['return_url'].'?claim='.rawurlencode($claim);});
    }

    public function claim(#[SensitiveParameter]array$server,string$claim): array
    {
        $domain=$this->authenticate($server);$this->assertToken($claim);return$this->locked(function()use($domain,$claim):array{$record=$this->read('claim',$claim);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This Bluesky connection result has expired.',410);if(!hash_equals((string)($record['domain']??''),$domain))throw new RuntimeException('This Bluesky connection belongs to another installation.',403);$this->delete('claim',$claim);unset($record['domain'],$record['expires']);return$record;});
    }

    public function refresh(#[SensitiveParameter]array$server,#[SensitiveParameter]array$input): array
    {
        $domain=$this->authenticate($server);$refresh=(string)($input['refresh_token']??'');$private=(string)($input['dpop_private_key']??'');$x=(string)($input['dpop_x']??'');$y=(string)($input['dpop_y']??'');if(strlen($refresh)<20||strlen($refresh)>8192||preg_match('/[\x00-\x20]/',$refresh)||strlen($private)>4096||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$x)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$y))throw new RuntimeException('Invalid Bluesky refresh credentials.',422);$jwk=AtprotoCrypto::publicJwk($private);if(!hash_equals($jwk['x'],$x)||!hash_equals($jwk['y'],$y))throw new RuntimeException('Invalid Bluesky proof credentials.',422);$record=['issuer'=>self::PDS,'token_endpoint'=>self::PDS.'/oauth/token','dpop_private_key'=>$private,'dpop_x'=>$x,'dpop_y'=>$y,'auth_nonce'=>(string)($input['auth_nonce']??'')];
        return$this->locked(function()use($domain,$refresh,$record):array{$this->rate('refresh:'.$domain,240,3600);$tokens=$this->tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$refresh],$record);unset($tokens['sub']);return$tokens;});
    }

    private function metadata(): array
    {
        $issuer=self::PDS;[$status,$body]=$this->simple('GET',$issuer.'/.well-known/oauth-authorization-server');$data=json_decode($body,true);if($status!==200||!is_array($data)||($data['issuer']??'')!==$issuer||!in_array('code',(array)($data['response_types_supported']??[]),true)||!in_array('authorization_code',(array)($data['grant_types_supported']??[]),true)||!in_array('refresh_token',(array)($data['grant_types_supported']??[]),true)||!in_array('S256',(array)($data['code_challenge_methods_supported']??[]),true)||!in_array('private_key_jwt',(array)($data['token_endpoint_auth_methods_supported']??[]),true)||!in_array('ES256',(array)($data['dpop_signing_alg_values_supported']??[]),true)||($data['require_pushed_authorization_requests']??null)!==true||($data['client_id_metadata_document_supported']??null)!==true)throw new RuntimeException('Bluesky authorization discovery failed.',502);return['issuer'=>$issuer,'authorization'=>$this->endpoint((string)($data['authorization_endpoint']??''),'/oauth/authorize'),'token'=>$this->endpoint((string)($data['token_endpoint']??''),'/oauth/token'),'par'=>$this->endpoint((string)($data['pushed_authorization_request_endpoint']??''),'/oauth/par')];
    }

    private function tokenRequest(array$fields,array$record): array
    {
        $fields['client_id']=self::CLIENT_ID;$fields['client_assertion_type']='urn:ietf:params:oauth:client-assertion-type:jwt-bearer';$fields['client_assertion']=AtprotoCrypto::assertion((string)$record['issuer'],$this->clientPrivate,$this->clientJwk);$jwk=['kty'=>'EC','crv'=>'P-256','x'=>(string)$record['dpop_x'],'y'=>(string)$record['dpop_y']];[$data,$nonce]=$this->oauthPost((string)$record['token_endpoint'],$fields,(string)$record['dpop_private_key'],$jwk,(string)($record['auth_nonce']??''));$access=(string)($data['access_token']??'');$refresh=(string)($data['refresh_token']??'');$expires=(int)($data['expires_in']??0);$scope=trim((string)($data['scope']??''));$sub=(string)($data['sub']??'');if(strcasecmp((string)($data['token_type']??''),'DPoP')!==0||strlen($access)<20||strlen($access)>8192||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>8192||preg_match('/[\x00-\x20]/',$refresh)||$expires<60||$expires>86400||!in_array('atproto',preg_split('/\s+/',$scope)?:[],true)||!str_contains($scope,'repo:app.bsky.feed.post'))throw new RuntimeException('Bluesky did not issue valid publishing credentials.',502);if(($fields['grant_type']??'')==='authorization_code'&&!preg_match('/^did:plc:[a-z2-7]{24}$/D',$sub))throw new RuntimeException('Bluesky did not identify the authorized account.',502);return['sub'=>$sub,'access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>time()+$expires,'scope'=>$scope,'auth_nonce'=>$nonce];
    }

    private function oauthPost(string$url,array$fields,#[SensitiveParameter]string$private,array$jwk,?string$nonce): array
    {
        for($attempt=0;$attempt<2;$attempt++){$proof=AtprotoCrypto::proof('POST',$url,$private,$jwk,$nonce?:null);[$status,$body,$headers]=($this->http)('POST',$url,['Content-Type: application/x-www-form-urlencoded','Accept: application/json','DPoP: '.$proof],http_build_query($fields,'','&',PHP_QUERY_RFC3986));$issued=trim((string)($headers['dpop-nonce']??''));if($issued!=='')$nonce=$issued;$data=json_decode($body,true);if($attempt===0&&in_array($status,[400,401],true)&&$issued!=='')continue;if($status<200||$status>=300||!is_array($data)||$nonce===null||$nonce===''){$reason=is_array($data)&&is_string($data['error']??null)?preg_replace('/[^A-Za-z0-9_.:-]/','',(string)$data['error']):'http-'.$status;$description=is_array($data)&&is_string($data['error_description']??null)?mb_substr(preg_replace('/[^\x20-\x7e]/','',(string)$data['error_description']),0,160):'';if($status>=200&&$status<300&&($nonce===null||$nonce===''))$reason='missing-dpop-nonce';throw new RuntimeException('Bluesky OAuth proof negotiation failed: '.$reason.($description!==''?' ('.$description.')':'').'.',502);}return[$data,$nonce];}throw new RuntimeException('Bluesky OAuth proof negotiation failed.',502);
    }

    private function accountPds(string$did): string
    {
        if(!preg_match('/^did:plc:[a-z2-7]{24}$/D',$did))throw new RuntimeException('Unsupported Bluesky account identity.',502);[$status,$body]=$this->simple('GET','https://plc.directory/'.$did);$doc=json_decode($body,true);$services=is_array($doc)?(array)($doc['service']??[]):[];if($status===200)foreach($services as$service)if(is_array($service)&&str_ends_with((string)($service['id']??''),'#atproto_pds')&&($service['type']??'')==='AtprotoPersonalDataServer')return$this->officialPds((string)($service['serviceEndpoint']??''));throw new RuntimeException('The Bluesky account is not hosted by the supported PDS.',422);
    }

    private function officialPds(string$url): string
    {
        $parts=parse_url($url);if(!is_array($parts))throw new RuntimeException('The Bluesky account is not hosted by the supported PDS.',422);$host=strtolower((string)($parts['host']??''));$official=$host==='bsky.social'||($host!=='host.bsky.network'&&str_ends_with($host,'.host.bsky.network'));if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)($parts['scheme']??''))!=='https'||!$official||filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false||(int)($parts['port']??443)!==443||!in_array((string)($parts['path']??''),['','/'],true)||isset($parts['query'])||isset($parts['fragment'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('The Bluesky account is not hosted by the supported PDS.',422);return'https://'.$host;
    }

    private function profile(string$did): array
    {
        [$status,$body]=$this->simple('GET','https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor='.rawurlencode($did));$data=json_decode($body,true);$handle=is_array($data)?strtolower((string)($data['handle']??'')):'';$name=is_array($data)?trim((string)($data['displayName']??'')):'';if($status!==200||!preg_match('/^[a-z0-9](?:[a-z0-9.-]{1,251}[a-z0-9])?$/D',$handle))throw new RuntimeException('Bluesky profile discovery failed.',502);return['handle'=>$handle,'name'=>mb_substr($name!==''?$name:'@'.$handle,0,180)];
    }

    private function authenticate(#[SensitiveParameter]array$server): string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match))throw new RuntimeException('A valid CMS installation licence is required.',401);try{$domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??''));}catch(LicenseException){throw new RuntimeException('Invalid installation identity.',401);}$config=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];$payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$config['product_name'],'ProductModel'=>$config['product_model'],'ProductVersion'=>'1.0'],'','&',PHP_QUERY_RFC3986);[$status,$body]=$this->simple('POST',$config['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);try{(new LicenseClient($config))->response($status,$body);}catch(LicenseException){throw new RuntimeException('The CMS licence does not authorize this installation.',403);}return$domain;
    }

    private function endpoint(string$url,string$path): string{$parts=parse_url($url);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strcasecmp((string)($parts['host']??''),'bsky.social')!==0||(int)($parts['port']??443)!==443||($parts['path']??'')!==$path||isset($parts['query'])||isset($parts['fragment'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Unsafe Bluesky OAuth endpoint.',502);return$url;}
    private function simple(string$method,string$url,array$headers=['Accept: application/json'],string$payload=''): array{[$status,$body]=$result=($this->http)($method,$url,$headers,$payload);return[$status,$body,$result[2]??[]];}
    private function rate(string$subject,int$limit,int$window): void{$record=$this->read('rate',$subject);$now=time();if(($record['since']??0)>$now)throw new RuntimeException('Bluesky rate-limit clock mismatch.',503);if(($record['since']??0)+$window<=$now)$record=['since'=>$now,'count'=>0];if(($record['count']??0)>=$limit)throw new RuntimeException('Too many Bluesky connection requests.',429);$record['count']=($record['count']??0)+1;$this->write('rate',$subject,$record);}
    private function cleanup(): void{$now=time();foreach(glob($this->root.'/storage/{request,claim,rate}-*.json',GLOB_BRACE)?:[]as$file){if(is_link($file)||!is_file($file))continue;try{$stored=json_decode((string)file_get_contents($file),true,4,JSON_THROW_ON_ERROR);$sealed=base64_decode((string)($stored['sealed']??''),true);$plain=is_string($sealed)?sodium_crypto_secretbox_open(substr($sealed,24),substr($sealed,0,24),$this->key):false;$data=is_string($plain)?json_decode($plain,true,16,JSON_THROW_ON_ERROR):[];if(is_string($plain))sodium_memzero($plain);$expiry=(int)($data['data']['expires']??(($data['data']['since']??0)+86400));if($expiry<$now-86400)unlink($file);}catch(Throwable){continue;}}}
    private function locked(Closure$operation): mixed{$path=$this->root.'/broker.lock';if(is_link($path))throw new RuntimeException('Invalid Bluesky lock.',503);$mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Bluesky service is busy. Try again.',503);}try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}}
    private function read(string$type,string$subject): array{$name=$this->name($type,$subject);$stored=(new Runtime($this->root))->read($name);if(!$stored)return[];$raw=base64_decode((string)($stored['sealed']??''),true);if(!is_string($raw)||strlen($raw)<=24)throw new RuntimeException('Invalid private Bluesky state.',503);$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);if(!is_string($plain))throw new RuntimeException('Bluesky state integrity check failed.',503);try{$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}finally{sodium_memzero($plain);}if(!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null))throw new RuntimeException('Invalid private Bluesky state.',503);return$data['data'];}
    private function write(string$type,string$subject,#[SensitiveParameter]array$data): void{$name=$this->name($type,$subject);$nonce=random_bytes(24);$plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($plain)>131072)throw new RuntimeException('Bluesky state limit reached.',503);try{(new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]);}finally{sodium_memzero($plain);}}
    private function delete(string$type,string$subject): void{$path=$this->root.'/storage/'.$this->name($type,$subject).'.json';if(is_link($path))throw new RuntimeException('Invalid private Bluesky state.',503);if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot consume private Bluesky state.',503);}
    private function name(string$type,string$subject): string{return$type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key);}
    private function token(): string{return AtprotoCrypto::b64(random_bytes(32));}
    private function assertToken(string$token): void{if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Invalid Bluesky connection token.',422);}
    private function request(string$method,string$url,array$headers,#[SensitiveParameter]string$payload): array{$body='';$responseHeaders=[];$curl=curl_init($url);if(!$curl)throw new RuntimeException('Bluesky transport unavailable.',503);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_HEADERFUNCTION=>static function($handle,string$line)use(&$responseHeaders):int{$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}];if($method==='POST')$options[CURLOPT_POSTFIELDS]=$payload;curl_setopt_array($curl,$options);try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}if($ok===false)throw new RuntimeException('Bluesky transport unavailable.',503);return[$status,$body,$responseHeaders];}
}
