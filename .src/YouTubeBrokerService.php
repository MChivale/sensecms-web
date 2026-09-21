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

/** Official-site Google OAuth broker. The client secret never enters a customer package. */
final class YouTubeBrokerService
{
    private const ORIGIN='https://www.sensecms.com';
    private const CALLBACK=self::ORIGIN.'/api/social/youtube/v1/callback';
    private const SCOPES=['https://www.googleapis.com/auth/youtube.upload','https://www.googleapis.com/auth/youtube.readonly'];
    private readonly Closure$http;
    private readonly string$key;
    private readonly string$root;

    public function __construct(string$root,#[SensitiveParameter]string$key,private readonly array$config,?Closure$http=null)
    {
        if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||is_link($root)||!is_dir($root))throw new RuntimeException('Private YouTube storage is unavailable.',503);$this->root=realpath($root)?:throw new RuntimeException('Private YouTube storage is unavailable.',503);if(PHP_OS_FAMILY!=='Windows'&&(fileperms($this->root)&0077))throw new RuntimeException('YouTube storage must be private.',503);$this->key=$key;$this->http=$http??$this->request(...);
    }

    public function ready():bool{return preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D',(string)($this->config['client_id']??''))===1&&preg_match('/^[^\x00-\x20]{8,512}$/D',(string)($this->config['client_secret']??''))===1;}

    public function start(#[SensitiveParameter]array$server,array$input):array
    {
        $this->assertReady();$domain=$this->authenticate($server);$return=(string)($input['return_url']??'');if($return!==$domain.'/social-publishing/youtube/callback')throw new RuntimeException('Invalid YouTube connection return address.',422);return$this->locked(function()use($domain,$return):array{$this->rate($domain,20,3600);$request=$this->token();$this->write('request',$request,['domain'=>$domain,'return_url'=>$return,'expires'=>time()+600,'used'=>false]);return['authorize_url'=>self::ORIGIN.'/api/social/youtube/v1/authorize?request='.rawurlencode($request),'expires_in'=>600];});
    }

    public function authorize(string$request):string
    {
        $this->assertReady();$this->assertToken($request);$record=$this->read('request',$request);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This YouTube connection request has expired.',410);return'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$this->config['client_id'],'redirect_uri'=>self::CALLBACK,'response_type'=>'code','scope'=>implode(' ',self::SCOPES),'access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'true','state'=>$request],'','&',PHP_QUERY_RFC3986);
    }

    public function callback(array$query):string
    {
        $this->assertReady();$state=(string)($query['state']??'');$this->assertToken($state);return$this->locked(function()use($state,$query):string{$record=$this->read('request',$state);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This YouTube connection request has expired.',410);$record['used']=true;$this->write('request',$state,$record);if(isset($query['error']))return(string)$record['return_url'];$code=(string)($query['code']??'');if($code===''||strlen($code)>4096||preg_match('/[\x00-\x20]/',$code))throw new RuntimeException('Google did not return a valid authorization code.',422);$tokens=$this->exchange($code);$channel=$this->channel((string)$tokens['access_token']);$claim=$this->token();$this->write('claim',$claim,['domain'=>$record['domain'],'channel_id'=>$channel['id'],'channel_title'=>$channel['title'],'access_token'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token'],'expires_at'=>$tokens['expires_at'],'public_uploads'=>(bool)($this->config['public_uploads']??false),'expires'=>time()+600]);return(string)$record['return_url'].'?claim='.rawurlencode($claim);});
    }

    public function claim(#[SensitiveParameter]array$server,string$claim):array
    {
        $this->assertReady();$domain=$this->authenticate($server);$this->assertToken($claim);return$this->locked(function()use($domain,$claim):array{$record=$this->read('claim',$claim);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This YouTube connection result has expired.',410);if(!hash_equals((string)($record['domain']??''),$domain))throw new RuntimeException('This YouTube connection belongs to another installation.',403);$this->delete('claim',$claim);unset($record['domain'],$record['expires']);return$record;});
    }

    public function refresh(#[SensitiveParameter]array$server,#[SensitiveParameter]string$refresh):array
    {
        $this->assertReady();$domain=$this->authenticate($server);if(strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh))throw new RuntimeException('Invalid YouTube refresh credential.',422);return$this->locked(function()use($domain,$refresh):array{$this->rate('refresh:'.$domain,120,3600);$payload=http_build_query(['client_id'=>$this->config['client_id'],'client_secret'=>$this->config['client_secret'],'refresh_token'=>$refresh,'grant_type'=>'refresh_token'],'','&',PHP_QUERY_RFC3986);[$status,$body]=($this->http)('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);$data=json_decode($body,true);$access=is_array($data)?(string)($data['access_token']??''):'';$expires=(int)($data['expires_in']??0);if($status!==200||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||$expires<60||$expires>86400)throw new RuntimeException('Google did not refresh valid YouTube credentials.',502);return['access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>time()+$expires,'public_uploads'=>(bool)($this->config['public_uploads']??false)];});
    }

    public function cleanup():array{return$this->locked(function():array{$counts=['removed'=>0,'retained'=>0,'invalid'=>0];$now=time();$dir=$this->root.'/storage';if(!is_dir($dir)||is_link($dir))return$counts;foreach(new \DirectoryIterator($dir)as$file){if(!$file->isFile()||$file->isLink()||!preg_match('/^(request|claim|rate)-[a-f0-9]{64}\.json$/D',$file->getFilename()))continue;try{$record=$this->readName(substr($file->getFilename(),0,-5));}catch(Throwable){$counts['invalid']++;continue;}$expiry=(int)($record['expires']??(($record['since']??0)+86400));if($expiry>$now-86400){$counts['retained']++;continue;}if(!unlink($file->getPathname()))throw new RuntimeException('Cannot remove expired YouTube state.',503);$counts['removed']++;}return$counts;});}

    private function exchange(#[SensitiveParameter]string$code):array
    {
        $payload=http_build_query(['code'=>$code,'client_id'=>$this->config['client_id'],'client_secret'=>$this->config['client_secret'],'redirect_uri'=>self::CALLBACK,'grant_type'=>'authorization_code'],'','&',PHP_QUERY_RFC3986);[$status,$body]=($this->http)('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);$data=json_decode($body,true);$access=is_array($data)?(string)($data['access_token']??''):'';$refresh=is_array($data)?(string)($data['refresh_token']??''):'';$expires=(int)($data['expires_in']??0);$granted=preg_split('/\s+/',rawurldecode(trim((string)($data['scope']??''))))?:[];if($status!==200||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<60||$expires>86400||($granted!==['']&&array_diff(self::SCOPES,$granted)!==[]))throw new RuntimeException('Google did not issue valid YouTube publishing credentials.',502);return['access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>time()+$expires];
    }

    private function channel(#[SensitiveParameter]string$token):array
    {
        [$status,$body]=($this->http)('GET','https://www.googleapis.com/youtube/v3/channels?part=id%2Csnippet&mine=true&maxResults=1',['Authorization: Bearer '.$token,'Accept: application/json'],'');$data=json_decode($body,true);$items=is_array($data['items']??null)?$data['items']:[];$row=$items[0]??null;$id=is_array($row)?(string)($row['id']??''):'';$title=is_array($row)?trim((string)($row['snippet']['title']??'')):'';if($status!==200||count($items)!==1||!preg_match('/^UC[A-Za-z0-9_-]{22}$/D',$id)||$title===''||mb_strlen($title)>180)throw new RuntimeException('Google did not return an accessible YouTube channel.',502);return['id'=>$id,'title'=>$title];
    }

    private function authenticate(#[SensitiveParameter]array$server):string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match))throw new RuntimeException('A valid CMS installation licence is required.',401);try{$domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??''));}catch(LicenseException){throw new RuntimeException('Invalid installation identity.',401);}$cfg=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];$payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$cfg['product_name'],'ProductModel'=>$cfg['product_model'],'ProductVersion'=>$cfg['product_version']],'','&',PHP_QUERY_RFC3986);[$status,$body]=($this->http)('POST',$cfg['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);try{(new LicenseClient($cfg))->response($status,$body);}catch(LicenseException){throw new RuntimeException('The CMS licence does not authorize this installation.',403);}return$domain;
    }

    private function rate(string$subject,int$limit,int$window):void{$record=$this->read('rate',$subject);$now=time();if(($record['since']??0)>$now)throw new RuntimeException('YouTube rate-limit clock mismatch.',503);if(($record['since']??0)+$window<=$now)$record=['since'=>$now,'count'=>0];if(($record['count']??0)>=$limit)throw new RuntimeException('Too many YouTube connection requests.',429);$record['count']=($record['count']??0)+1;$this->write('rate',$subject,$record);}
    private function locked(Closure$operation):mixed{$path=$this->root.'/broker.lock';if(is_link($path))throw new RuntimeException('Invalid YouTube lock.',503);$mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('YouTube service is busy. Try again.',503);}try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}}
    private function read(string$type,string$subject):array{return$this->readName($this->name($type,$subject));}
    private function readName(string$name):array{$stored=(new Runtime($this->root))->read($name);if(!$stored)return[];$raw=base64_decode((string)($stored['sealed']??''),true);if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid private YouTube state.',503);$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);if(!is_string($plain))throw new RuntimeException('YouTube state integrity check failed.',503);try{$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}finally{sodium_memzero($plain);}if(!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null))throw new RuntimeException('Invalid private YouTube state.',503);return$data['data'];}
    private function write(string$type,string$subject,#[SensitiveParameter]array$data):void{$name=$this->name($type,$subject);$nonce=random_bytes(24);$plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($plain)>131072)throw new RuntimeException('YouTube state limit reached.',503);try{(new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]);}finally{sodium_memzero($plain);}}
    private function delete(string$type,string$subject):void{$path=$this->root.'/storage/'.$this->name($type,$subject).'.json';if(is_link($path))throw new RuntimeException('Invalid private YouTube state.',503);if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot consume private YouTube state.',503);}
    private function name(string$type,string$subject):string{return$type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key);}
    private function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function assertToken(string$token):void{if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Invalid YouTube connection token.',422);}
    private function assertReady():void{if(!$this->ready())throw new RuntimeException('The Sense CMS YouTube application is not configured.',503);}
    private function request(string$method,string$url,array$headers,#[SensitiveParameter]string$payload):array{$body='';$curl=curl_init($url);if(!$curl)throw new RuntimeException('YouTube transport unavailable.',503);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}];if($method==='POST')$options[CURLOPT_POSTFIELDS]=$payload;curl_setopt_array($curl,$options);try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}if($ok===false)throw new RuntimeException('YouTube transport unavailable.',503);return[$status,$body];}
}
