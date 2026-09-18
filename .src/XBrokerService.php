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

/** Official-site X OAuth broker. The application secret never enters a customer package. */
final class XBrokerService
{
    private const ORIGIN='https://www.sensecms.com';
    private const CALLBACK=self::ORIGIN.'/api/social/x/v1/callback';
    private const SCOPES=['tweet.read','tweet.write','users.read','offline.access'];
    private readonly Closure $http;
    private readonly string $key;
    private readonly string $root;

    public function __construct(string $root,#[SensitiveParameter]string$key,private readonly array$config,?Closure$http=null)
    {
        if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||is_link($root)||!is_dir($root))throw new RuntimeException('Private X storage is unavailable.',503);
        $this->root=realpath($root)?:throw new RuntimeException('Private X storage is unavailable.',503);
        if(PHP_OS_FAMILY!=='Windows'&&(fileperms($this->root)&0077))throw new RuntimeException('X storage must be private.',503);
        $this->key=$key;$this->http=$http??$this->request(...);
    }

    public function ready(): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{8,200}$/D',(string)($this->config['client_id']??''))===1&&preg_match('/^[A-Za-z0-9_-]{20,200}$/D',(string)($this->config['client_secret']??''))===1;
    }

    public function start(#[SensitiveParameter]array$server,array$input): array
    {
        $this->assertReady();$domain=$this->authenticate($server);$return=(string)($input['return_url']??'');
        if($return!==$domain.'/social-publishing/x/callback')throw new RuntimeException('Invalid X connection return address.',422);
        return$this->locked(function()use($domain,$return):array{$this->rate($domain,20,3600);$request=$this->token();$verifier=$this->token();$this->write('request',$request,['domain'=>$domain,'return_url'=>$return,'verifier'=>$verifier,'expires'=>time()+600,'used'=>false]);return['authorize_url'=>self::ORIGIN.'/api/social/x/v1/authorize?request='.rawurlencode($request),'expires_in'=>600];});
    }

    public function authorize(string$request): string
    {
        $this->assertReady();$this->assertToken($request);$record=$this->read('request',$request);
        if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This X connection request has expired.',410);
        $query=http_build_query(['response_type'=>'code','client_id'=>$this->config['client_id'],'redirect_uri'=>self::CALLBACK,'scope'=>implode(' ',self::SCOPES),'state'=>$request,'code_challenge'=>$this->challenge((string)$record['verifier']),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986);
        return'https://x.com/i/oauth2/authorize?'.$query;
    }

    public function callback(array$query): string
    {
        $this->assertReady();$state=(string)($query['state']??'');$this->assertToken($state);
        return$this->locked(function()use($state,$query):string{$record=$this->read('request',$state);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This X connection request has expired.',410);$record['used']=true;$this->write('request',$state,$record);if(isset($query['error']))return(string)$record['return_url'];$code=(string)($query['code']??'');if($code===''||strlen($code)>2048||preg_match('/[\x00-\x20]/',$code))throw new RuntimeException('X did not return a valid authorization code.',422);$tokens=$this->exchange($code,(string)$record['verifier']);$user=$this->user((string)$tokens['access_token']);$claim=$this->token();$this->write('claim',$claim,['domain'=>$record['domain'],'user_id'=>$user['id'],'username'=>$user['username'],'name'=>$user['name'],'access_token'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token'],'expires_at'=>$tokens['expires_at'],'expires'=>time()+600]);return(string)$record['return_url'].'?claim='.rawurlencode($claim);});
    }

    public function claim(#[SensitiveParameter]array$server,string$claim): array
    {
        $this->assertReady();$domain=$this->authenticate($server);$this->assertToken($claim);
        return$this->locked(function()use($domain,$claim):array{$record=$this->read('claim',$claim);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This X connection result has expired.',410);if(!hash_equals((string)($record['domain']??''),$domain))throw new RuntimeException('This X connection belongs to another installation.',403);$this->delete('claim',$claim);unset($record['domain'],$record['expires']);return$record;});
    }

    public function refresh(#[SensitiveParameter]array$server,#[SensitiveParameter]string$refreshToken): array
    {
        $this->assertReady();$domain=$this->authenticate($server);if(strlen($refreshToken)<20||strlen($refreshToken)>4096||preg_match('/[\x00-\x20]/',$refreshToken))throw new RuntimeException('Invalid X refresh credential.',422);
        return$this->locked(function()use($domain,$refreshToken):array{$this->rate('refresh:'.$domain,240,3600);return$this->tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken);});
    }

    public function cleanup(): array
    {
        return$this->locked(function():array{$counts=['removed'=>0,'retained'=>0,'invalid'=>0];$now=time();$dir=$this->root.'/storage';if(!is_dir($dir)||is_link($dir))return$counts;foreach(new \DirectoryIterator($dir)as$file){if(!$file->isFile()||$file->isLink()||!preg_match('/^(request|claim|rate)-[a-f0-9]{64}\.json$/D',$file->getFilename()))continue;try{$record=$this->readName(substr($file->getFilename(),0,-5));}catch(Throwable){$counts['invalid']++;continue;}$expiry=(int)($record['expires']??(($record['since']??0)+86400));if($expiry>$now-86400){$counts['retained']++;continue;}if(!unlink($file->getPathname()))throw new RuntimeException('Cannot remove expired X state.',503);$counts['removed']++;}return$counts;});
    }

    private function exchange(#[SensitiveParameter]string$code,#[SensitiveParameter]string$verifier): array
    {
        return$this->tokenRequest(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>self::CALLBACK,'code_verifier'=>$verifier]);
    }

    private function tokenRequest(#[SensitiveParameter]array$fields,#[SensitiveParameter]?string$fallbackRefresh=null): array
    {
        $payload=http_build_query($fields,'','&',PHP_QUERY_RFC3986);$basic=base64_encode((string)$this->config['client_id'].':'.(string)$this->config['client_secret']);
        [$status,$body]=($this->http)('POST','https://api.x.com/2/oauth2/token',['Authorization: Basic '.$basic,'Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);$data=json_decode($body,true);
        $access=is_array($data)?(string)($data['access_token']??''):'';$issuedRefresh=is_array($data)?(string)($data['refresh_token']??''):'';$refresh=$issuedRefresh!==''?$issuedRefresh:(string)$fallbackRefresh;$expires=(int)($data['expires_in']??0);$scopeRaw=trim((string)($data['scope']??''));$scope=$scopeRaw===''?[]:(preg_split('/\s+/',$scopeRaw)?:[]);$type=(string)($data['token_type']??'');
        if($status!==200||strcasecmp($type,'bearer')!==0||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<60||$expires>31536000||($scopeRaw===''?$fallbackRefresh===null:(bool)array_diff(self::SCOPES,$scope)))throw new RuntimeException('X did not issue valid publishing credentials.',502);
        return['access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>time()+$expires];
    }

    private function user(#[SensitiveParameter]string$token): array
    {
        [$status,$body]=($this->http)('GET','https://api.x.com/2/users/me?user.fields=name%2Cusername',['Authorization: Bearer '.$token,'Accept: application/json'],'');$data=json_decode($body,true);$user=is_array($data)?($data['data']??null):null;$id=is_array($user)?(string)($user['id']??''):'';$username=is_array($user)?(string)($user['username']??''):'';$name=is_array($user)?trim((string)($user['name']??'')):'';
        if($status!==200||!preg_match('/^[0-9]{1,30}$/D',$id)||!preg_match('/^[A-Za-z0-9_]{1,15}$/D',$username)||$name===''||mb_strlen($name)>180)throw new RuntimeException('X account discovery failed.',502);
        return['id'=>$id,'username'=>$username,'name'=>$name];
    }

    private function authenticate(#[SensitiveParameter]array$server): string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match))throw new RuntimeException('A valid CMS installation licence is required.',401);
        try{$domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??''));}catch(LicenseException){throw new RuntimeException('Invalid installation identity.',401);}
        $config=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];$payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$config['product_name'],'ProductModel'=>$config['product_model'],'ProductVersion'=>'1.0'],'','&',PHP_QUERY_RFC3986);[$status,$body]=($this->http)('POST',$config['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);
        try{(new LicenseClient($config))->response($status,$body);}catch(LicenseException){throw new RuntimeException('The CMS licence does not authorize this installation.',403);}return$domain;
    }

    private function rate(string$subject,int$limit,int$window): void
    {
        $record=$this->read('rate',$subject);$now=time();if(($record['since']??0)>$now)throw new RuntimeException('X rate-limit clock mismatch.',503);if(($record['since']??0)+$window<=$now)$record=['since'=>$now,'count'=>0];if(($record['count']??0)>=$limit)throw new RuntimeException('Too many X connection requests.',429);$record['count']=($record['count']??0)+1;$this->write('rate',$subject,$record);
    }

    private function locked(Closure$operation): mixed
    {
        $path=$this->root.'/broker.lock';if(is_link($path))throw new RuntimeException('Invalid X lock.',503);$mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('X service is busy. Try again.',503);}try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private function read(string$type,string$subject): array{return$this->readName($this->name($type,$subject));}
    private function readName(string$name): array
    {
        $stored=(new Runtime($this->root))->read($name);if(!$stored)return[];$raw=base64_decode((string)($stored['sealed']??''),true);if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid private X state.',503);$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);if(!is_string($plain))throw new RuntimeException('X state integrity check failed.',503);try{$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}finally{sodium_memzero($plain);}if(!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null))throw new RuntimeException('Invalid private X state.',503);return$data['data'];
    }
    private function write(string$type,string$subject,#[SensitiveParameter]array$data): void
    {
        $name=$this->name($type,$subject);$nonce=random_bytes(24);$plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($plain)>131072)throw new RuntimeException('X state limit reached.',503);try{(new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]);}finally{sodium_memzero($plain);}
    }
    private function delete(string$type,string$subject): void{$path=$this->root.'/storage/'.$this->name($type,$subject).'.json';if(is_link($path))throw new RuntimeException('Invalid private X state.',503);if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot consume private X state.',503);}
    private function name(string$type,string$subject): string{return$type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key);}
    private function token(): string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function challenge(string$verifier): string{return rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');}
    private function assertToken(string$token): void{if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Invalid X connection token.',422);}
    private function assertReady(): void{if(!$this->ready())throw new RuntimeException('The Sense CMS X application is not configured.',503);}
    private function request(string$method,string$url,array$headers,#[SensitiveParameter]string$payload): array
    {
        $body='';$curl=curl_init($url);if(!$curl)throw new RuntimeException('X transport unavailable.',503);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}];if($method==='POST')$options[CURLOPT_POSTFIELDS]=$payload;curl_setopt_array($curl,$options);try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}if($ok===false)throw new RuntimeException('X transport unavailable.',503);return[$status,$body];
    }
}
