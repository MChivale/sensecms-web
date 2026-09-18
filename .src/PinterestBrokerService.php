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

/** Official-site Pinterest OAuth broker. The application secret never enters a customer package. */
final class PinterestBrokerService
{
    private const ORIGIN='https://www.sensecms.com';
    private const CALLBACK=self::ORIGIN.'/api/social/pinterest/v1/callback';
    private const SCOPES=['boards:read','pins:read','pins:write','user_accounts:read'];
    private readonly Closure $http;
    private readonly string $key;
    private readonly string $root;

    public function __construct(string$root,#[SensitiveParameter]string$key,private readonly array$config,?Closure$http=null)
    {
        if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES||is_link($root)||!is_dir($root))throw new RuntimeException('Private Pinterest storage is unavailable.',503);
        $this->root=realpath($root)?:throw new RuntimeException('Private Pinterest storage is unavailable.',503);
        if(PHP_OS_FAMILY!=='Windows'&&(fileperms($this->root)&0077))throw new RuntimeException('Pinterest storage must be private.',503);
        $this->key=$key;$this->http=$http??$this->request(...);
    }

    public function ready():bool{return preg_match('/^[A-Za-z0-9_-]{5,200}$/D',(string)($this->config['client_id']??''))===1&&preg_match('/^[^\x00-\x20]{8,512}$/D',(string)($this->config['client_secret']??''))===1;}

    public function start(#[SensitiveParameter]array$server,array$input):array
    {
        $this->assertReady();$domain=$this->authenticate($server);$return=(string)($input['return_url']??'');if($return!==$domain.'/social-publishing/pinterest/callback')throw new RuntimeException('Invalid Pinterest connection return address.',422);
        return$this->locked(function()use($domain,$return):array{$this->rate($domain,20,3600);$request=$this->token();$this->write('request',$request,['domain'=>$domain,'return_url'=>$return,'expires'=>time()+600,'used'=>false]);return['authorize_url'=>self::ORIGIN.'/api/social/pinterest/v1/authorize?request='.rawurlencode($request),'expires_in'=>600];});
    }

    public function authorize(string$request):string
    {
        $this->assertReady();$this->assertToken($request);$record=$this->read('request',$request);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This Pinterest connection request has expired.',410);
        return'https://www.pinterest.com/oauth/?'.http_build_query(['response_type'=>'code','client_id'=>$this->config['client_id'],'redirect_uri'=>self::CALLBACK,'scope'=>implode(',',self::SCOPES),'state'=>$request],'','&',PHP_QUERY_RFC3986);
    }

    public function callback(array$query):string
    {
        $this->assertReady();$state=(string)($query['state']??'');$this->assertToken($state);
        return$this->locked(function()use($state,$query):string{$record=$this->read('request',$state);if(!$record||($record['expires']??0)<=time()||!empty($record['used']))throw new RuntimeException('This Pinterest connection request has expired.',410);$record['used']=true;$this->write('request',$state,$record);if(isset($query['error']))return(string)$record['return_url'];$code=(string)($query['code']??'');if($code===''||strlen($code)>4096||preg_match('/[\x00-\x20]/',$code))throw new RuntimeException('Pinterest did not return a valid authorization code.',422);$tokens=$this->tokenRequest(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>self::CALLBACK,'continuous_refresh'=>'true']);$user=$this->user((string)$tokens['access_token']);$boards=$this->boards((string)$tokens['access_token']);if($boards===[])throw new RuntimeException('The Pinterest account has no board available for publishing.',422);$selection=$this->token();$this->write('selection',$selection,['domain'=>$record['domain'],'return_url'=>$record['return_url'],'user'=>$user,'boards'=>$boards,'tokens'=>$tokens,'csrf'=>$this->token(),'expires'=>time()+600]);return self::ORIGIN.'/api/social/pinterest/v1/select?request='.rawurlencode($selection);});
    }

    public function selectionForm(string$request):string
    {
        $this->assertReady();$this->assertToken($request);$record=$this->read('selection',$request);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This Pinterest board selection has expired.',410);$h=static fn(mixed$value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$items='';foreach((array)$record['boards']as$i=>$board){$id=(string)($board['id']??'');$name=(string)($board['name']??'');if(!preg_match('/^[0-9]{1,30}$/D',$id)||$name==='')continue;$items.='<label><input type="radio" name="board_id" value="'.$h($id).'"'.($i===0?' checked':'').'><span><strong>'.$h($name).'</strong><small>Board ID '.$h($id).'</small></span></label>';}
        if($items==='')throw new RuntimeException('No Pinterest board is available.',422);return'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Choose a Pinterest board</title><style>*{box-sizing:border-box}body{align-items:center;background:#f8fafc;color:#172033;display:flex;font:15px system-ui,-apple-system,sans-serif;justify-content:center;margin:0;min-height:100vh;padding:24px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 24px 70px #0f172a1f;max-width:560px;padding:28px;width:100%}.brand{align-items:center;display:flex;gap:14px}.icon{align-items:center;background:#e60023;border-radius:999px;color:#fff;display:flex;font-size:24px;font-weight:800;height:52px;justify-content:center;width:52px}h1{font-size:24px;margin:0}p{color:#64748b;line-height:1.55;margin:8px 0 22px}label{align-items:center;border:1px solid #e2e8f0;border-radius:12px;cursor:pointer;display:flex;gap:12px;margin:10px 0;padding:14px}label:has(input:checked){background:#fff0f2;border-color:#e60023}input{accent-color:#e60023}span{display:flex;flex-direction:column;gap:3px}small{color:#64748b}button{background:#2563eb;border:0;border-radius:9px;color:#fff;cursor:pointer;font:inherit;font-weight:650;margin-top:14px;padding:12px 18px;width:100%}</style></head><body><main class="card"><div class="brand"><span class="icon">P</span><div><h1>Choose a Pinterest board</h1><p>Connect one board now. You can repeat this process for additional boards.</p></div></div><form method="post" action="'.self::ORIGIN.'/api/social/pinterest/v1/select"><input type="hidden" name="request" value="'.$h($request).'"><input type="hidden" name="csrf" value="'.$h($record['csrf']).'">'.$items.'<button type="submit">Connect selected board</button></form></main></body></html>';
    }

    public function select(array$input):string
    {
        $this->assertReady();$request=(string)($input['request']??'');$this->assertToken($request);$csrf=(string)($input['csrf']??'');$boardId=(string)($input['board_id']??'');if(!preg_match('/^[0-9]{1,30}$/D',$boardId))throw new RuntimeException('Select a valid Pinterest board.',422);
        return$this->locked(function()use($request,$csrf,$boardId):string{$record=$this->read('selection',$request);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This Pinterest board selection has expired.',410);if(!is_string($record['csrf']??null)||!hash_equals($record['csrf'],$csrf))throw new RuntimeException('Invalid Pinterest board selection.',403);$board=null;foreach((array)$record['boards']as$item)if((string)($item['id']??'')===$boardId){$board=$item;break;}if(!is_array($board))throw new RuntimeException('The selected Pinterest board is unavailable.',422);$claim=$this->token();$tokens=(array)$record['tokens'];$user=(array)$record['user'];$this->write('claim',$claim,['domain'=>$record['domain'],'user_id'=>$user['id'],'username'=>$user['username'],'board_id'=>$board['id'],'board_name'=>$board['name'],'access_token'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token'],'expires_at'=>$tokens['expires_at'],'refresh_expires_at'=>$tokens['refresh_expires_at'],'expires'=>time()+600]);$this->delete('selection',$request);return(string)$record['return_url'].'?claim='.rawurlencode($claim);});
    }

    public function claim(#[SensitiveParameter]array$server,string$claim):array
    {
        $this->assertReady();$domain=$this->authenticate($server);$this->assertToken($claim);return$this->locked(function()use($domain,$claim):array{$record=$this->read('claim',$claim);if(!$record||($record['expires']??0)<=time())throw new RuntimeException('This Pinterest connection result has expired.',410);if(!hash_equals((string)($record['domain']??''),$domain))throw new RuntimeException('This Pinterest connection belongs to another installation.',403);$this->delete('claim',$claim);unset($record['domain'],$record['expires']);return$record;});
    }

    public function refresh(#[SensitiveParameter]array$server,#[SensitiveParameter]string$refreshToken):array
    {
        $this->assertReady();$domain=$this->authenticate($server);if(strlen($refreshToken)<20||strlen($refreshToken)>4096||preg_match('/[\x00-\x20]/',$refreshToken))throw new RuntimeException('Invalid Pinterest refresh credential.',422);return$this->locked(function()use($domain,$refreshToken):array{$this->rate('refresh:'.$domain,240,3600);return$this->tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],$refreshToken);});
    }

    public function cleanup():array
    {
        return$this->locked(function():array{$counts=['removed'=>0,'retained'=>0,'invalid'=>0];$now=time();$dir=$this->root.'/storage';if(!is_dir($dir)||is_link($dir))return$counts;foreach(new \DirectoryIterator($dir)as$file){if(!$file->isFile()||$file->isLink()||!preg_match('/^(request|selection|claim|rate)-[a-f0-9]{64}\.json$/D',$file->getFilename()))continue;try{$record=$this->readName(substr($file->getFilename(),0,-5));}catch(Throwable){$counts['invalid']++;continue;}$expiry=(int)($record['expires']??(($record['since']??0)+86400));if($expiry>$now-86400){$counts['retained']++;continue;}if(!unlink($file->getPathname()))throw new RuntimeException('Cannot remove expired Pinterest state.',503);$counts['removed']++;}return$counts;});
    }

    private function tokenRequest(#[SensitiveParameter]array$fields,#[SensitiveParameter]?string$fallbackRefresh=null):array
    {
        $basic=base64_encode((string)$this->config['client_id'].':'.(string)$this->config['client_secret']);[$status,$body]=($this->http)('POST','https://api.pinterest.com/v5/oauth/token',['Authorization: Basic '.$basic,'Content-Type: application/x-www-form-urlencoded','Accept: application/json'],http_build_query($fields,'','&',PHP_QUERY_RFC3986));$data=json_decode($body,true);$access=is_array($data)?(string)($data['access_token']??''):'';$issuedRefresh=is_array($data)?(string)($data['refresh_token']??''):'';$refresh=$issuedRefresh!==''?$issuedRefresh:(string)$fallbackRefresh;$expires=(int)($data['expires_in']??0);$refreshExpires=(int)($data['refresh_token_expires_in']??0);$scopeRaw=trim((string)($data['scope']??''));$scopes=$scopeRaw===''?[]:(preg_split('/[\s,]+/',$scopeRaw)?:[]);$type=(string)($data['token_type']??'');
        if($status!==200||strcasecmp($type,'bearer')!==0||strlen($access)<20||strlen($access)>4096||preg_match('/[\x00-\x20]/',$access)||strlen($refresh)<20||strlen($refresh)>4096||preg_match('/[\x00-\x20]/',$refresh)||$expires<60||$expires>31536000||$refreshExpires<60||$refreshExpires>63072000||($scopeRaw!==''&&(bool)array_diff(self::SCOPES,$scopes)))throw new RuntimeException('Pinterest did not issue valid publishing credentials.',502);
        return['access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>time()+$expires,'refresh_expires_at'=>time()+$refreshExpires];
    }

    private function user(#[SensitiveParameter]string$token):array
    {
        [$status,$body]=($this->http)('GET','https://api.pinterest.com/v5/user_account',['Authorization: Bearer '.$token,'Accept: application/json'],'');$data=json_decode($body,true);$id=is_array($data)?(string)($data['id']??''):'';$username=is_array($data)?(string)($data['username']??''):'';if($status!==200||!preg_match('/^[0-9]{1,30}$/D',$id)||!preg_match('/^[A-Za-z0-9_]{3,30}$/D',$username))throw new RuntimeException('Pinterest account discovery failed.',502);return['id'=>$id,'username'=>$username];
    }

    private function boards(#[SensitiveParameter]string$token):array
    {
        $boards=[];$bookmark='';for($page=0;$page<10;$page++){$url='https://api.pinterest.com/v5/boards?page_size=250'.($bookmark!==''?'&bookmark='.rawurlencode($bookmark):'');[$status,$body]=($this->http)('GET',$url,['Authorization: Bearer '.$token,'Accept: application/json'],'');$data=json_decode($body,true);if($status!==200||!is_array($data)||!is_array($data['items']??null))throw new RuntimeException('Pinterest board discovery failed.',502);foreach($data['items']as$item){$id=is_array($item)?(string)($item['id']??''):'';$name=is_array($item)?trim((string)($item['name']??'')):'';if(preg_match('/^[0-9]{1,30}$/D',$id)&&$name!==''&&mb_strlen($name)<=180)$boards[$id]=['id'=>$id,'name'=>$name];}$bookmark=(string)($data['bookmark']??'');if($bookmark==='')break;if(strlen($bookmark)>1024||preg_match('/[\x00-\x20]/',$bookmark))throw new RuntimeException('Pinterest returned an invalid board cursor.',502);}return array_values($boards);
    }

    private function authenticate(#[SensitiveParameter]array$server):string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');if(!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match))throw new RuntimeException('A valid CMS installation licence is required.',401);try{$domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??''));}catch(LicenseException){throw new RuntimeException('Invalid installation identity.',401);}$config=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];$payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$config['product_name'],'ProductModel'=>$config['product_model'],'ProductVersion'=>'1.0'],'','&',PHP_QUERY_RFC3986);[$status,$body]=($this->http)('POST',$config['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);try{(new LicenseClient($config))->response($status,$body);}catch(LicenseException){throw new RuntimeException('The CMS licence does not authorize this installation.',403);}return$domain;
    }

    private function rate(string$subject,int$limit,int$window):void{$record=$this->read('rate',$subject);$now=time();if(($record['since']??0)>$now)throw new RuntimeException('Pinterest rate-limit clock mismatch.',503);if(($record['since']??0)+$window<=$now)$record=['since'=>$now,'count'=>0];if(($record['count']??0)>=$limit)throw new RuntimeException('Too many Pinterest connection requests.',429);$record['count']=($record['count']??0)+1;$this->write('rate',$subject,$record);}
    private function locked(Closure$operation):mixed{$path=$this->root.'/broker.lock';if(is_link($path))throw new RuntimeException('Invalid Pinterest lock.',503);$mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Pinterest service is busy. Try again.',503);}try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}}
    private function read(string$type,string$subject):array{return$this->readName($this->name($type,$subject));}
    private function readName(string$name):array{$stored=(new Runtime($this->root))->read($name);if(!$stored)return[];$raw=base64_decode((string)($stored['sealed']??''),true);if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid private Pinterest state.',503);$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);if(!is_string($plain))throw new RuntimeException('Pinterest state integrity check failed.',503);try{$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}finally{sodium_memzero($plain);}if(!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null))throw new RuntimeException('Invalid private Pinterest state.',503);return$data['data'];}
    private function write(string$type,string$subject,#[SensitiveParameter]array$data):void{$name=$this->name($type,$subject);$nonce=random_bytes(24);$plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($plain)>524288)throw new RuntimeException('Pinterest state limit reached.',503);try{(new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]);}finally{sodium_memzero($plain);}}
    private function delete(string$type,string$subject):void{$path=$this->root.'/storage/'.$this->name($type,$subject).'.json';if(is_link($path))throw new RuntimeException('Invalid private Pinterest state.',503);if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot consume private Pinterest state.',503);}
    private function name(string$type,string$subject):string{return$type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key);}
    private function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function assertToken(string$token):void{if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Invalid Pinterest connection token.',422);}
    private function assertReady():void{if(!$this->ready())throw new RuntimeException('The Sense CMS Pinterest application is not configured.',503);}
    private function request(string$method,string$url,array$headers,#[SensitiveParameter]string$payload):array{$body='';$curl=curl_init($url);if(!$curl)throw new RuntimeException('Pinterest transport unavailable.',503);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>524288)return 0;$body.=$chunk;return strlen($chunk);}];if($method==='POST')$options[CURLOPT_POSTFIELDS]=$payload;curl_setopt_array($curl,$options);try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}if($ok===false)throw new RuntimeException('Pinterest transport unavailable.',503);return[$status,$body];}
}
