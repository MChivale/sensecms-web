<?php
declare(strict_types=1);

namespace SenseCMS\Website;

use App\Core\LicenseClient;
use App\Core\LicenseException;
use App\Core\Runtime;
use Closure;
use RuntimeException;

/** Official-site service only; not part of a theme or a portable customer Core. */
final class TelegramBrokerService
{
    private readonly string $key;
    private readonly Closure $http;
    private readonly string $root;

    public function __construct(string $root, #[\SensitiveParameter] string $key, private readonly array $config, ?Closure $http = null)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || is_link($root) || !is_dir($root)) throw new RuntimeException('Private Telegram storage is unavailable.',503);
        $this->root = realpath($root) ?: throw new RuntimeException('Private Telegram storage is unavailable.',503);
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->root) & 0077)) throw new RuntimeException('Telegram storage must be private.',503);
        $this->key = $key;
        $this->http = $http ?? $this->request(...);
    }

    public function ready(): bool
    {
        return preg_match('/^[0-9]{6,15}:[A-Za-z0-9_-]{30,100}$/D',(string)($this->config['bot_token']??''))===1
            && preg_match('/^[A-Za-z0-9_]{5,32}$/D',(string)($this->config['bot_username']??''))===1
            && preg_match('/^[A-Za-z0-9_-]{32,256}$/D',(string)($this->config['webhook_secret']??''))===1;
    }

    public function handle(string $action, #[\SensitiveParameter] array $server, array $input): array
    {
        if (!in_array($action,['start','status','recipients','disconnect','deliver','webhook'],true)) throw new RuntimeException('Unknown Telegram action.',404);
        if (strlen(json_encode($input,JSON_THROW_ON_ERROR))>65536) throw new RuntimeException('Telegram request is too large.',413);
        if (!$this->ready()) throw new RuntimeException('The Sense CMS Telegram bot is not configured.',503);
        // A single non-blocking operator lock serializes binding changes with delivery.
        $path = $this->root . '/broker.lock';
        if (is_link($path)) throw new RuntimeException('Invalid Telegram lock.',503);
        $mask = umask(0077);
        try { $lock = fopen($path,'c+b'); } finally { umask($mask); }
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if (is_resource($lock)) fclose($lock); throw new RuntimeException('Telegram service is busy. Try again.',503); }
        try {
            if ($action==='webhook') return $this->webhook($server,$input);
            $domain = $this->authenticate($server);
            $this->rate($domain,120,60);
            if ($action==='recipients') return ['user_ids'=>array_map('intval',array_keys($this->read('bindings',$domain)['users']??[]))];
            $user = $this->user($input['user_id']??null);
            $subject = $domain . "\0" . $user;
            $bindings = $this->read('bindings',$domain); $binding = $bindings['users'][$user]??null;
            if ($action==='start') {
                $this->rate($subject,12,3600);
                $token = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                // Only the newest request for this user remains valid.
                $this->write('pending',$subject,['token_hash'=>hash('sha256',$token),'expires'=>time()+600]);
                $this->write('starts',$token,['domain'=>$domain,'user_id'=>$user,'expires'=>time()+600]);
                return ['connect_url'=>'https://t.me/'.$this->config['bot_username'].'?start='.$token,'request_token'=>$token,'expires_in'=>600];
            }
            if ($action==='status') {
                $token = (string)($input['request_token']??''); $state = '';
                if ($token!=='') {
                    $this->token($token); $request=$this->read('starts',$token);
                    if ($request && (($request['domain']??null)!==$domain || ($request['user_id']??null)!==$user)) throw new RuntimeException('This connection belongs to another user or installation.',403);
                    $pending=$this->read('pending',$subject);
                    $state=!$request || ($request['expires']??0)<=time() ? 'expired' : ((($request['connected']??false)===true) ? 'connected' : (hash_equals((string)($pending['token_hash']??''),hash('sha256',$token))?'pending':'expired'));
                }
                return ['connected'=>$binding!==null,'account'=>$binding?array_intersect_key($binding,array_flip(['name','username','connected_at'])):null,'request_status'=>$state];
            }
            if ($action==='disconnect') {
                unset($bindings['users'][$user]); $this->write('bindings',$domain,$bindings);
                $this->write('pending',$subject,[]);
                return ['connected'=>false];
            }
            if (!$binding) throw new RuntimeException('This user has not connected Telegram.',404);
            return $this->deliver($subject,$binding,$input);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function authenticate(#[\SensitiveParameter] array $server): string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');
        if (!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match)) throw new RuntimeException('A valid CMS installation licence is required.',401);
        try { $domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??'')); }
        catch (LicenseException) { throw new RuntimeException('Invalid installation identity.',401); }
        // The caller cannot choose another product, model, endpoint or licence version.
        $config=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];
        $payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$config['product_name'],'ProductModel'=>$config['product_model'],'ProductVersion'=>'1.0'],'','&',PHP_QUERY_RFC3986);
        [$status,$body]=($this->http)($config['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);
        try { (new LicenseClient($config))->response($status,$body); }
        catch (LicenseException) { throw new RuntimeException('The CMS licence does not authorize this installation.',403); }
        return $domain;
    }

    private function webhook(#[\SensitiveParameter] array $server, array $input): array
    {
        if (!hash_equals($this->config['webhook_secret'],(string)($server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))) throw new RuntimeException('Invalid Telegram webhook authorization.',403);
        $message=$input['message']??[];
        if (!is_array($message)) throw new RuntimeException('Invalid Telegram message.',422);
        $chat=$message['chat']??[]; $from=$message['from']??[];
        if (!is_array($chat)||!is_array($from)) throw new RuntimeException('Invalid Telegram account.',422);
        $text=$message['text']??'';
        if (!is_string($text) || ($chat['type']??'')!=='private' || ($from['is_bot']??true)!==false || !preg_match('/^\/start(?:@([A-Za-z0-9_]{5,32}))? ([A-Za-z0-9_-]{43})$/D',$text,$match)) return ['ok'=>true];
        if ($match[1]!=='' && strcasecmp($match[1],$this->config['bot_username'])!==0) return ['ok'=>true];
        $chatId=$this->user($chat['id']??null);
        if ($chatId!==$this->user($from['id']??null)) throw new RuntimeException('Telegram private account mismatch.',422);
        $token=$match[2]; $request=$this->read('starts',$token);
        if (!$request || ($request['expires']??0)<=time() || !empty($request['connected'])) return ['ok'=>true];
        $domain=$request['domain']; $user=$request['user_id']; $subject=$domain."\0".$user;
        $pending=$this->read('pending',$subject);
        if (!hash_equals((string)($pending['token_hash']??''),hash('sha256',$token))) return ['ok'=>true];
        $bindings=$this->read('bindings',$domain);
        if (!isset($bindings['users'][$user]) && count($bindings['users']??[])>=100) throw new RuntimeException('Installation recipient limit reached.',429);
        $bindings['users'][$user]=['chat_id'=>$chatId,'name'=>mb_substr(trim((string)($from['first_name']??'').' '.(string)($from['last_name']??'')),0,120),'username'=>mb_substr((string)($from['username']??''),0,32),'connected_at'=>gmdate('Y-m-d H:i:s')];
        // Consume before saving the binding; interrupted processing never reuses a token.
        $this->write('pending',$subject,[]);
        $this->write('bindings',$domain,$bindings);
        $request['connected']=true; $this->write('starts',$token,$request);
        // No extra confirmation message: the signed-in CMS polls the connection state.
        return ['ok'=>true];
    }

    private function deliver(string $subject,array $binding,array $input): array
    {
        $event=$input['event_key']??''; $title=$input['title']??''; $body=$input['body']??'';
        if (!is_string($event)||!preg_match('/^[a-f0-9]{64}$/D',$event)||!is_string($title)||!is_string($body)||trim($title)===''||trim($body)===''||mb_strlen($title)>180||mb_strlen($body)>3500||!mb_check_encoding($title.$body,'UTF-8')) throw new RuntimeException('Invalid Telegram notification.',422);
        $identity=$subject."\0".$event; $digest=hash('sha256',$title."\0".$body);
        $prior=$this->read('deliveries',$identity);
        if ($prior) {
            if (($prior['digest']??'')!==$digest) throw new RuntimeException('Notification identity has different content.',409);
            if (($prior['status']??'')==='sent') return ['sent'=>true,'duplicate'=>true];
            throw new RuntimeException('Delivery outcome requires review before retry.',409);
        }
        $record=['status'=>'processing','digest'=>$digest,'created_at'=>time()];
        $this->write('deliveries',$identity,$record);
        try {
            $payload=json_encode(['chat_id'=>(string)$binding['chat_id'],'text'=>$title."\n".$body,'link_preview_options'=>['is_disabled'=>true]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            [$status,$body]=($this->http)('https://api.telegram.org/bot'.$this->config['bot_token'].'/sendMessage',['Content-Type: application/json'],$payload);
            $data=json_decode($body,true); $result=$data['result']??null;
            if ($status!==200||($data['ok']??null)!==true||!is_array($result)||!is_int($result['message_id']??null)||$result['message_id']<1||($result['chat']['type']??null)!=='private'||(string)($result['chat']['id']??'')!==(string)$binding['chat_id']) throw new RuntimeException('Telegram did not confirm delivery.',502);
            $record['status']='sent'; $this->write('deliveries',$identity,$record);
        } catch (\Throwable) { $record['status']='unknown'; $this->write('deliveries',$identity,$record); throw new RuntimeException('Telegram delivery requires review.',502); }
        return ['sent'=>true,'duplicate'=>false];
    }

    private function read(string $type,string $subject): array
    {
        return $this->readName($this->name($type,$subject));
    }

    private function readName(string $name): array
    {
        $stored=(new Runtime($this->root))->read($name);
        if (!$stored) return [];
        $raw=base64_decode((string)($stored['sealed']??''),true);
        if (!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Invalid private Telegram state.',503);
        $plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);
        if (!is_string($plain)) throw new RuntimeException('Telegram state integrity check failed.',503);
        try { $data=json_decode($plain,true,16,JSON_THROW_ON_ERROR); }
        finally { sodium_memzero($plain); }
        if (!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null)) throw new RuntimeException('Invalid private Telegram state.',503);
        return $data['data'];
    }

    private function write(string $type,string $subject,array $data): void
    {
        $name=$this->name($type,$subject); $nonce=random_bytes(24);
        $plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if (strlen($plain)>45000) throw new RuntimeException('Telegram state limit reached.',503);
        try { (new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]); }
        finally { sodium_memzero($plain); }
    }

    private function name(string $type,string $subject): string { return $type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key); }

    /** Local maintenance only. Keep bindings and deduplication tombstones indefinitely. */
    public function cleanup(): array
    {
        $path=$this->root.'/broker.lock';
        if(is_link($path))throw new RuntimeException('Invalid Telegram lock.',503);
        $mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);return ['busy'=>true,'removed'=>0];}
        $counts=['removed'=>0,'retained'=>0,'invalid'=>0];$now=time();
        try {
            $dir=$this->root.'/storage';if(!is_dir($dir)||is_link($dir))return $counts;
            foreach(new \DirectoryIterator($dir) as $file){
                if(!$file->isFile()||$file->isLink()||!preg_match('/^(starts|pending|rate)-[a-f0-9]{64}\.json$/D',$file->getFilename(),$match))continue;
                try{$data=$this->readName(substr($file->getFilename(),0,-5));}catch(\Throwable){$counts['invalid']++;continue;}
                $expiry=$match[1]==='rate'?($data['since']??null):($data['expires']??null);
                // Empty consumed pending records have no expiry; use their last write plus grace.
                if($match[1]==='pending'&&$data===[])$expiry=$file->getMTime();
                if(!is_int($expiry)||$expiry<1||$expiry>$now-86400){$counts['retained']++;continue;}
                if(!unlink($file->getPathname()))throw new RuntimeException('Cannot remove expired Telegram state.');
                $counts['removed']++;
            }
            return $counts;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private function user(mixed $id): int { if (!is_int($id)||$id<1) throw new RuntimeException('Invalid user identity.',422);return $id; }
    private function token(string $token): void { if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)) throw new RuntimeException('Invalid connection token.',422); }

    private function rate(string $subject,int $limit,int $window): void
    {
        $record=$this->read('rate',$subject); $now=time();
        if (($record['since']??0)>$now) throw new RuntimeException('Telegram rate-limit clock mismatch.',503);
        if (($record['since']??0)+$window<=$now) $record=['since'=>$now,'count'=>0];
        if (($record['count']??0)>=$limit) throw new RuntimeException('Too many Telegram requests.',429);
        $record['count']=($record['count']??0)+1; $this->write('rate',$subject,$record);
    }

    private function request(string $url,array $headers,#[\SensitiveParameter] string $payload): array
    {
        $body=''; $curl=curl_init($url);
        if (!$curl) throw new RuntimeException('Telegram transport unavailable.',503);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
        try { $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); }
        finally { curl_close($curl); }
        if ($ok===false) throw new RuntimeException('Telegram transport unavailable.',503);
        return [$status,$body];
    }
}
