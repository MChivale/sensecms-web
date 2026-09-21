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

/** Official-site TikTok OAuth and verified media broker. App credentials never enter customer packages. */
final class TikTokBrokerService
{
    private const ORIGIN='https://www.sensecms.com';
    private const CALLBACK=self::ORIGIN.'/api/social/tiktok/v1/callback';
    private const SCOPES=['user.info.basic','video.publish'];
    private readonly Closure$http;
    private readonly string$key;
    private readonly string$root;

    public function __construct(string$root,#[SensitiveParameter]string$key,private readonly array$config,?Closure$http=null)
    {
        if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||is_link($root)||!is_dir($root))throw new RuntimeException('Private TikTok storage is unavailable.',503);$this->root=realpath($root)?:throw new RuntimeException('Private TikTok storage is unavailable.',503);if(PHP_OS_FAMILY!=='Windows'&&(fileperms($this->root)&0077))throw new RuntimeException('TikTok storage must be private.',503);$media=$this->root.'/media';if(is_link($media)||!is_dir($media)&&!mkdir($media,0700,true))throw new RuntimeException('Private TikTok media storage is unavailable.',503);if(PHP_OS_FAMILY!=='Windows'&&(fileperms($media)&0077))throw new RuntimeException('TikTok media storage must be private.',503);$this->key=$key;$this->http=$http??$this->request(...);
    }

    public function ready():bool{return preg_match('/^[A-Za-z0-9_-]{5,200}$/D',(string)($this->config['client_key']??''))===1&&preg_match('/^[^\x00-\x20]{8,512}$/D',(string)($this->config['client_secret']??''))===1;}

    public function start(#[SensitiveParameter]array$server,array$input):array
    {
        $this->assertReady();$domain=$this->authenticate($server);$return=(string)($input['return_url']??'');if($return!==$domain.'/social-publishing/tiktok/callback')throw new RuntimeException('Invalid TikTok connection return address.',422);return$this->locked(function()use($domain,$return):array{$this->rate($domain,20,3600);$request=$this->token();$this->write('request',$request,['domain'=>$domain,'return_url'=>$return,'expires'=>time()+600,'used'=>false]);return['authorize_url'=>self::ORIGIN.'/api/social/tiktok/v1/authorize?request='.rawurlencode($request),'expires_in'=>600];});
    }

    public function authorize(string$request):string
    {
        $this->assertReady();$this->assertToken($request);$record=$this->read('request',$request);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This TikTok connection request has expired.',410);return'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query(['client_key'=>$this->config['client_key'],'response_type'=>'code','scope'=>implode(',',self::SCOPES),'redirect_uri'=>self::CALLBACK,'state'=>$request],'','&',PHP_QUERY_RFC3986);
    }

    public function callback(array$query):string
    {
        $this->assertReady();$state=(string)($query['state']??'');$this->assertToken($state);return$this->locked(function()use($state,$query):string{$record=$this->read('request',$state);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This TikTok connection request has expired.',410);$record['used']=true;$this->write('request',$state,$record);if(isset($query['error']))return(string)$record['return_url'];$code=(string)($query['code']??'');if($code===''||strlen($code)>4096||preg_match('/[\x00-\x20]/',$code))throw new RuntimeException('TikTok did not return a valid authorization code.',422);$tokens=$this->tokenRequest(['client_key'=>$this->config['client_key'],'client_secret'=>$this->config['client_secret'],'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>self::CALLBACK]);$user=$this->user((string)$tokens['access_token']);if((string)$tokens['open_id']!==$user['open_id'])throw new RuntimeException('TikTok returned inconsistent account identities.',502);$claim=$this->token();$this->write('claim',$claim,['domain'=>$record['domain'],'open_id'=>$user['open_id'],'display_name'=>$user['display_name'],'access_token'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token'],'expires_at'=>$tokens['expires_at'],'refresh_expires_at'=>$tokens['refresh_expires_at'],'expires'=>time()+600]);return(string)$record['return_url'].'?claim='.rawurlencode($claim);});
    }

    public function claim(#[SensitiveParameter]array$server,string$claim):array
    {
        $this->assertReady();$domain=$this->authenticate($server);$this->assertToken($claim);return$this->locked(function()use($domain,$claim):array{$record=$this->read('claim',$claim);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This TikTok connection result has expired.',410);if(!hash_equals((string)($record['domain']??''),$domain))throw new RuntimeException('This TikTok connection belongs to another installation.',403);$this->delete('claim',$claim);unset($record['domain'],$record['expires']);return$record;});
    }

    public function refresh(#[SensitiveParameter]array$server,#[SensitiveParameter]string$refreshToken):array
    {
        $this->assertReady();$domain=$this->authenticate($server);if(strlen($refreshToken)<20||strlen($refreshToken)>4096||preg_match('/[\x00-\x20]/',$refreshToken))throw new RuntimeException('Invalid TikTok refresh credential.',422);return$this->locked(function()use($domain,$refreshToken):array{$this->rate('refresh:'.$domain,240,3600);return$this->tokenRequest(['client_key'=>$this->config['client_key'],'client_secret'=>$this->config['client_secret'],'grant_type'=>'refresh_token','refresh_token'=>$refreshToken]);});
    }

    public function stage(#[SensitiveParameter]array$server,string$mime,#[SensitiveParameter]string$binary,string$deliveryKey):array
    {
        $this->assertReady();$domain=$this->authenticate($server);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||$binary===''||strlen($binary)>20*1024*1024||!preg_match('/^[a-f0-9]{64}$/D',$deliveryKey))throw new RuntimeException('Invalid TikTok image upload.',422);$image=@getimagesizefromstring($binary);if(!is_array($image)||($image['mime']??'')!==$mime||(int)$image[0]<360||(int)$image[1]<360||(int)$image[0]>8192||(int)$image[1]>8192)throw new RuntimeException('TikTok requires a valid JPEG, PNG or WebP image between 360 and 8192 pixels.',422);return$this->locked(function()use($domain,$mime,$binary,$deliveryKey):array{$this->rate('media:'.$domain,120,3600);$subject=hash_hmac('sha256',$domain."\0".$deliveryKey,$this->key);$existing=$this->read('stage',$subject);if(($existing['expires']??0)>time()&&is_string($existing['token']??null)){try{$this->media($existing['token']);return['source_url'=>$this->mediaUrl($existing['token'],$existing['mime']),'expires_at'=>$existing['expires']];}catch(Throwable){}}$token=$this->token();$name=hash_hmac('sha256','media'."\0".$token,$this->key).'.bin';$path=$this->root.'/media/'.$name;$mask=umask(0077);try{$written=file_put_contents($path,$binary,LOCK_EX);if($written!==strlen($binary)||!chmod($path,0600))throw new RuntimeException('TikTok image staging failed.',503);}finally{umask($mask);} $expires=time()+3600;$record=['token'=>$token,'name'=>$name,'mime'=>$mime,'bytes'=>strlen($binary),'sha256'=>hash('sha256',$binary),'expires'=>$expires];$this->write('media',$token,$record);$this->write('stage',$subject,$record);return['source_url'=>$this->mediaUrl($token,$mime),'expires_at'=>$expires];});
    }

    public function media(string$token):array
    {
        $this->assertToken($token);$record=$this->read('media',$token);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This TikTok media source has expired.',410);$name=(string)($record['name']??'');if(!preg_match('/^[a-f0-9]{64}\.bin$/D',$name))throw new RuntimeException('Invalid TikTok media source.',503);$path=$this->root.'/media/'.$name;if(is_link($path)||!is_file($path)||(int)filesize($path)!==(int)($record['bytes']??-1)||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)))throw new RuntimeException('TikTok media source is unavailable.',410);$binary=file_get_contents($path);if(!is_string($binary)||!hash_equals((string)$record['sha256'],hash('sha256',$binary)))throw new RuntimeException('TikTok media source integrity failed.',503);return['mime'=>(string)$record['mime'],'binary'=>$binary,'sha256'=>(string)$record['sha256'],'expires'=>(int)$record['expires']];
    }

    public function cleanup():array
    {
        return$this->locked(function():array{$counts=['removed'=>0,'retained'=>0,'invalid'=>0];$now=time();$dir=$this->root.'/storage';if(!is_dir($dir)||is_link($dir))return$counts;foreach(new \DirectoryIterator($dir)as$file){if(!$file->isFile()||$file->isLink()||!preg_match('/^(request|claim|rate|stage|media)-[a-f0-9]{64}\.json$/D',$file->getFilename()))continue;try{$record=$this->readName(substr($file->getFilename(),0,-5));}catch(Throwable){$counts['invalid']++;continue;}$expiry=(int)($record['expires']??(($record['since']??0)+86400));if($expiry>$now-86400){$counts['retained']++;continue;}if(str_starts_with($file->getFilename(),'media-')&&preg_match('/^[a-f0-9]{64}\.bin$/D',(string)($record['name']??''))){$media=$this->root.'/media/'.$record['name'];if(is_file($media)&&!is_link($media))unlink($media);}if(!unlink($file->getPathname()))throw new RuntimeException('Cannot remove expired TikTok state.',503);$counts['removed']++;}return$counts;});
    }

    private function tokenRequest(#[SensitiveParameter]array$fields):array
    {
        [$status,$body]=($this->http)('POST','https://open.tiktokapis.com/v2/oauth/token/',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],http_build_query($fields,'','&',PHP_QUERY_RFC3986));$data=json_decode($body,true);$access=is_array($data)?(string)($data['access_token']??''):'';$refresh=is_array($data)?(string)($data['refresh_token']??''):'';$open=(string)($data['open_id']??'');$expires=(int)($data['expires_in']??0);$refreshExpires=(int)($data['refresh_expires_in']??0);$scopeRaw=trim((string)($data['scope']??''));$scopes=$scopeRaw===''?[]:(preg_split('/[\s,]+/',$scopeRaw)?:[]);if($status!==200||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||!preg_match('/^[A-Za-z0-9._-]{1,191}$/D',$open)||$expires<60||$refreshExpires<60||array_diff(self::SCOPES,$scopes))throw new RuntimeException('TikTok did not issue valid publishing credentials.',502);return['open_id'=>$open,'access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>time()+$expires,'refresh_expires_at'=>time()+$refreshExpires];
    }

    private function user(#[SensitiveParameter]string$token):array
    {
        [$status,$body]=($this->http)('GET','https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name',['Authorization: Bearer '.$token,'Accept: application/json'],'');$data=json_decode($body,true);$user=is_array($data)?($data['data']['user']??null):null;$code=is_array($data)?(string)($data['error']['code']??''):'';$open=is_array($user)?(string)($user['open_id']??''):'';$name=is_array($user)?trim((string)($user['display_name']??'')):'';if($status!==200||$code!=='ok'||!preg_match('/^[A-Za-z0-9._-]{1,191}$/D',$open)||$name===''||mb_strlen($name)>180)throw new RuntimeException('TikTok account discovery failed.',502);return['open_id'=>$open,'display_name'=>$name];
    }

    private function authenticate(#[SensitiveParameter]array$server):string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match))throw new RuntimeException('A valid CMS installation licence is required.',401);try{$domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??''));}catch(LicenseException){throw new RuntimeException('Invalid installation identity.',401);}$config=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];$payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$config['product_name'],'ProductModel'=>$config['product_model'],'ProductVersion'=>'1.0'],'','&',PHP_QUERY_RFC3986);[$status,$body]=($this->http)('POST',$config['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);try{(new LicenseClient($config))->response($status,$body);}catch(LicenseException){throw new RuntimeException('The CMS licence does not authorize this installation.',403);}return$domain;
    }

    private function mediaUrl(string$token,string$mime):string{$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??throw new RuntimeException('Invalid TikTok media type.',503);return self::ORIGIN.'/api/social/tiktok/v1/media/'.$token.'.'.$ext;}
    private function rate(string$subject,int$limit,int$window):void{$record=$this->read('rate',$subject);$now=time();if(($record['since']??0)>$now)throw new RuntimeException('TikTok rate-limit clock mismatch.',503);if(($record['since']??0)+$window<=$now)$record=['since'=>$now,'count'=>0];if(($record['count']??0)>=$limit)throw new RuntimeException('Too many TikTok requests.',429);$record['count']=($record['count']??0)+1;$this->write('rate',$subject,$record);}
    private function locked(Closure$operation):mixed{$path=$this->root.'/broker.lock';if(is_link($path))throw new RuntimeException('Invalid TikTok lock.',503);$mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('TikTok service is busy. Try again.',503);}try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}}
    private function read(string$type,string$subject):array{return$this->readName($this->name($type,$subject));}
    private function readName(string$name):array{$stored=(new Runtime($this->root))->read($name);if(!$stored)return[];$raw=base64_decode((string)($stored['sealed']??''),true);if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid private TikTok state.',503);$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);if(!is_string($plain))throw new RuntimeException('TikTok state integrity check failed.',503);try{$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}finally{sodium_memzero($plain);}if(!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null))throw new RuntimeException('Invalid private TikTok state.',503);return$data['data'];}
    private function write(string$type,string$subject,#[SensitiveParameter]array$data):void{$name=$this->name($type,$subject);$nonce=random_bytes(24);$plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($plain)>524288)throw new RuntimeException('TikTok state limit reached.',503);try{(new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]);}finally{sodium_memzero($plain);}}
    private function delete(string$type,string$subject):void{$path=$this->root.'/storage/'.$this->name($type,$subject).'.json';if(is_link($path))throw new RuntimeException('Invalid private TikTok state.',503);if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot consume private TikTok state.',503);}
    private function name(string$type,string$subject):string{return$type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key);}
    private function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function assertToken(string$token):void{if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Invalid TikTok connection token.',422);}
    private function assertReady():void{if(!$this->ready())throw new RuntimeException('The Sense CMS TikTok application is not configured.',503);}
    private function request(string$method,string$url,array$headers,#[SensitiveParameter]string$payload):array{$body='';$curl=curl_init($url);if(!$curl)throw new RuntimeException('TikTok transport unavailable.',503);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>524288)return 0;$body.=$chunk;return strlen($chunk);}];if($method==='POST')$options[CURLOPT_POSTFIELDS]=$payload;curl_setopt_array($curl,$options);try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}if($ok===false)throw new RuntimeException('TikTok transport unavailable.',503);return[$status,$body];}
}
