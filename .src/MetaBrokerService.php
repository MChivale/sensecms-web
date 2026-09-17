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

/** Official-site OAuth broker. App credentials never enter a customer package. */
final class MetaBrokerService
{
    private const ORIGIN = 'https://www.sensecms.com';
    private const CALLBACK = self::ORIGIN . '/api/social/meta/v1/callback';
    private readonly Closure $http;
    private readonly string $key;
    private readonly string $root;

    public function __construct(string $root, #[SensitiveParameter] string $key, private readonly array $config, ?Closure $http = null)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || is_link($root) || !is_dir($root)) throw new RuntimeException('Private Meta storage is unavailable.', 503);
        $this->root = realpath($root) ?: throw new RuntimeException('Private Meta storage is unavailable.', 503);
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($this->root) & 0077)) throw new RuntimeException('Meta storage must be private.', 503);
        $this->key = $key;
        $this->http = $http ?? $this->request(...);
    }

    public function ready(): bool
    {
        return preg_match('/^[0-9]{5,30}$/D', (string) ($this->config['app_id'] ?? '')) === 1
            && preg_match('/^[A-Za-z0-9]{32,128}$/D', (string) ($this->config['app_secret'] ?? '')) === 1
            && preg_match('/^[0-9]{5,30}$/D', (string) ($this->config['configuration_id'] ?? '')) === 1
            && preg_match('/^v[0-9]{1,2}\.[0-9]$/D', (string) ($this->config['api_version'] ?? '')) === 1;
    }

    public function start(#[SensitiveParameter] array $server, array $input): array
    {
        $this->assertReady();
        $domain = $this->authenticate($server);
        $return = (string) ($input['return_url'] ?? '');
        if ($return !== $domain . '/social-publishing/facebook/callback') throw new RuntimeException('Invalid Meta connection return address.', 422);
        return $this->locked(function () use ($domain, $return): array {
            $this->rate($domain, 20, 3600);
            $request = $this->token();
            $this->write('request', $request, ['domain'=>$domain, 'return_url'=>$return, 'expires'=>time()+600, 'used'=>false]);
            return ['authorize_url'=>self::ORIGIN . '/api/social/meta/v1/authorize?request=' . rawurlencode($request), 'expires_in'=>600];
        });
    }

    public function authorize(string $request): string
    {
        $this->assertReady();
        $this->assertToken($request);
        $record = $this->read('request', $request);
        if (!$record || ($record['expires'] ?? 0) <= time() || !empty($record['used'])) throw new RuntimeException('This Meta connection request has expired.', 410);
        $query = http_build_query([
            'client_id'=>$this->config['app_id'],
            'redirect_uri'=>self::CALLBACK,
            'state'=>$request,
            'response_type'=>'code',
            'config_id'=>$this->config['configuration_id'],
            'override_default_response_type'=>'true',
        ], '', '&', PHP_QUERY_RFC3986);
        return 'https://www.facebook.com/' . $this->config['api_version'] . '/dialog/oauth?' . $query;
    }

    /** @return array{redirect?:string,selection?:array{token:string,pages:array<int,array{id:string,name:string}>}} */
    public function callback(array $query): array
    {
        $this->assertReady();
        $state = (string) ($query['state'] ?? '');
        $this->assertToken($state);
        return $this->locked(function () use ($state, $query): array {
            $record = $this->read('request', $state);
            if (!$record || ($record['expires'] ?? 0) <= time() || !empty($record['used'])) throw new RuntimeException('This Meta connection request has expired.', 410);
            $record['used'] = true;
            $this->write('request', $state, $record);
            if (isset($query['error'])) return ['redirect'=>(string) $record['return_url']];
            $code = (string) ($query['code'] ?? '');
            if ($code === '' || strlen($code) > 2048 || preg_match('/[\x00-\x20]/', $code)) throw new RuntimeException('Meta did not return a valid authorization code.', 422);
            [$token, $user] = $this->exchange($code);
            try { $pages = $this->pages($token); }
            finally { sodium_memzero($token); }
            if (!$pages) throw new RuntimeException('No Facebook Page with publishing access was returned.', 422);
            if (count($pages) === 1) return ['redirect'=>$this->claimRedirect($record, $pages[0], $user)];
            $selection = $this->token();
            $this->write('selection', $selection, ['domain'=>$record['domain'], 'return_url'=>$record['return_url'], 'user_id'=>$user, 'pages'=>$pages, 'expires'=>time()+600]);
            return ['selection'=>['token'=>$selection, 'pages'=>array_map(static fn(array $page): array => ['id'=>$page['id'], 'name'=>$page['name']], $pages)]];
        });
    }

    public function select(string $selection, string $pageId): string
    {
        $this->assertReady();
        $this->assertToken($selection);
        if (!preg_match('/^[0-9]{5,30}$/D', $pageId)) throw new RuntimeException('Select a valid Facebook Page.', 422);
        return $this->locked(function () use ($selection, $pageId): string {
            $record = $this->read('selection', $selection);
            if (!$record || ($record['expires'] ?? 0) <= time()) throw new RuntimeException('This Facebook Page selection has expired.', 410);
            $page = null;
            foreach ((array) ($record['pages'] ?? []) as $candidate) if (hash_equals((string) ($candidate['id'] ?? ''), $pageId)) { $page=$candidate; break; }
            if (!$page) throw new RuntimeException('The selected Facebook Page is unavailable.', 422);
            $this->delete('selection', $selection);
            return $this->claimRedirect($record, $page, (string) ($record['user_id'] ?? ''));
        });
    }

    public function claim(#[SensitiveParameter] array $server, string $claim): array
    {
        $this->assertReady();
        $domain = $this->authenticate($server);
        $this->assertToken($claim);
        return $this->locked(function () use ($domain, $claim): array {
            $record = $this->read('claim', $claim);
            if (!$record || ($record['expires'] ?? 0) <= time()) throw new RuntimeException('This Meta connection result has expired.', 410);
            if (!hash_equals((string) ($record['domain'] ?? ''), $domain)) throw new RuntimeException('This Meta connection belongs to another installation.', 403);
            $this->delete('claim', $claim);
            return [
                'page_id'=>(string) $record['page_id'],
                'page_name'=>(string) $record['page_name'],
                'access_token'=>(string) $record['access_token'],
                'api_version'=>(string) $this->config['api_version'],
            ];
        });
    }

    public function deleteData(string $signedRequest): array
    {
        $this->assertReady();
        if (strlen($signedRequest) > 8192 || !str_contains($signedRequest, '.')) throw new RuntimeException('Invalid data deletion request.', 422);
        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);
        $signature = $this->decode64($encodedSignature); $payload = $this->decode64($encodedPayload);
        if (!hash_equals(hash_hmac('sha256', $encodedPayload, (string) $this->config['app_secret'], true), $signature)) throw new RuntimeException('Invalid data deletion signature.', 403);
        $data = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256' || !preg_match('/^[0-9]{1,30}$/D', (string) ($data['user_id'] ?? ''))) throw new RuntimeException('Invalid data deletion identity.', 422);
        $user = (string) $data['user_id'];
        $removed = $this->locked(fn(): int => $this->purgeUser($user));
        $confirmation = substr(hash_hmac('sha256', $user . "\0" . time() . "\0" . random_bytes(16), $this->key), 0, 24);
        return ['url'=>self::ORIGIN . '/data-deletion?code=' . $confirmation, 'confirmation_code'=>$confirmation, 'removed'=>$removed];
    }

    public function cleanup(): array
    {
        return $this->locked(function (): array {
            $counts=['removed'=>0, 'retained'=>0, 'invalid'=>0]; $now=time();
            $dir=$this->root.'/storage';
            if (!is_dir($dir) || is_link($dir)) return $counts;
            foreach (new \DirectoryIterator($dir) as $file) {
                if (!$file->isFile() || $file->isLink() || !preg_match('/^(request|selection|claim|rate)-[a-f0-9]{64}\.json$/D', $file->getFilename())) continue;
                try { $record=$this->readName(substr($file->getFilename(),0,-5)); }
                catch (Throwable) { $counts['invalid']++; continue; }
                $expiry=(int) ($record['expires'] ?? (($record['since'] ?? 0) + 86400));
                if ($expiry > $now-86400) { $counts['retained']++; continue; }
                if (!unlink($file->getPathname())) throw new RuntimeException('Cannot remove expired Meta state.', 503);
                $counts['removed']++;
            }
            return $counts;
        });
    }

    private function exchange(#[SensitiveParameter] string $code): array
    {
        $payload=http_build_query(['client_id'=>$this->config['app_id'], 'client_secret'=>$this->config['app_secret'], 'redirect_uri'=>self::CALLBACK, 'code'=>$code], '', '&', PHP_QUERY_RFC3986);
        [$status,$body]=($this->http)('POST',$this->graph('/oauth/access_token'),['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);
        $data=json_decode($body,true); $token=is_array($data)?(string)($data['access_token']??''):'';
        if ($status!==200 || strlen($token)<20 || strlen($token)>4096 || preg_match('/[\x00-\x20]/',$token)) throw new RuntimeException('Meta did not issue a valid access token.', 502);
        [$userStatus,$userBody]=($this->http)('GET',$this->graph('/me?fields=id'),['Accept: application/json','Authorization: Bearer '.$token],'');
        $userData=json_decode($userBody,true); $user=$userStatus===200&&is_array($userData)?(string)($userData['id']??''):'';
        if ($user!=='' && !preg_match('/^[0-9]{1,30}$/D',$user)) $user='';
        return [$token,$user];
    }

    private function pages(#[SensitiveParameter] string $token): array
    {
        [$status,$body]=($this->http)('GET',$this->graph('/me/accounts?fields=id%2Cname%2Caccess_token%2Ctasks&limit=25'),['Accept: application/json','Authorization: Bearer '.$token],'');
        $data=json_decode($body,true); $rows=is_array($data)?($data['data']??null):null;
        if ($status!==200 || !is_array($rows) || isset($data['paging']['next'])) throw new RuntimeException('Meta Page discovery failed or exceeded the supported account limit.', 502);
        $pages=[];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id=(string)($row['id']??''); $name=trim((string)($row['name']??'')); $access=(string)($row['access_token']??''); $tasks=$row['tasks']??[];
            if (!preg_match('/^[0-9]{5,30}$/D',$id) || $name==='' || mb_strlen($name)>180 || strlen($access)<20 || strlen($access)>4096 || preg_match('/[\x00-\x20]/',$access)) continue;
            if (is_array($tasks) && $tasks && !in_array('CREATE_CONTENT',$tasks,true) && !in_array('MANAGE',$tasks,true)) continue;
            $pages[]=['id'=>$id,'name'=>$name,'access_token'=>$access];
        }
        return $pages;
    }

    private function claimRedirect(array $record, #[SensitiveParameter] array $page, string $user): string
    {
        $claim=$this->token();
        $this->write('claim',$claim,['domain'=>$record['domain'],'page_id'=>$page['id'],'page_name'=>$page['name'],'access_token'=>$page['access_token'],'user_id'=>$user,'expires'=>time()+600]);
        return (string)$record['return_url'].'?claim='.rawurlencode($claim);
    }

    private function authenticate(#[SensitiveParameter] array $server): string
    {
        $header=(string)($server['HTTP_AUTHORIZATION']??$server['REDIRECT_HTTP_AUTHORIZATION']??'');
        if (!preg_match('/^Bearer ([A-Za-z0-9]{32})$/D',$header,$match)) throw new RuntimeException('A valid CMS installation licence is required.',401);
        try { $domain=LicenseClient::domain((string)($server['HTTP_X_SENSECMS_DOMAIN']??'')); }
        catch (LicenseException) { throw new RuntimeException('Invalid installation identity.',401); }
        $config=['endpoint'=>'https://www.chivale.com/license/','product_name'=>'Sense CMS','product_model'=>'Sense CMS System','product_version'=>'1.0'];
        $payload=http_build_query(['type'=>'software','LicenseKey'=>$match[1],'DomainUrl'=>$domain,'ProductName'=>$config['product_name'],'ProductModel'=>$config['product_model'],'ProductVersion'=>'1.0'],'','&',PHP_QUERY_RFC3986);
        [$status,$body]=($this->http)('POST',$config['endpoint'],['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$payload);
        try { (new LicenseClient($config))->response($status,$body); }
        catch (LicenseException) { throw new RuntimeException('The CMS licence does not authorize this installation.',403); }
        return $domain;
    }

    private function purgeUser(string $user): int
    {
        $removed=0; $dir=$this->root.'/storage';
        if (!is_dir($dir) || is_link($dir)) return 0;
        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile() || $file->isLink() || !preg_match('/^(selection|claim)-[a-f0-9]{64}\.json$/D',$file->getFilename())) continue;
            try { $data=$this->readName(substr($file->getFilename(),0,-5)); }
            catch (Throwable) { continue; }
            if (($data['user_id']??null)===$user && unlink($file->getPathname())) $removed++;
        }
        return $removed;
    }

    private function rate(string $subject,int $limit,int $window): void
    {
        $record=$this->read('rate',$subject); $now=time();
        if (($record['since']??0)>$now) throw new RuntimeException('Meta rate-limit clock mismatch.',503);
        if (($record['since']??0)+$window<=$now) $record=['since'=>$now,'count'=>0];
        if (($record['count']??0)>=$limit) throw new RuntimeException('Too many Meta connection requests.',429);
        $record['count']=($record['count']??0)+1; $this->write('rate',$subject,$record);
    }

    private function locked(Closure $operation): mixed
    {
        $path=$this->root.'/broker.lock'; if(is_link($path))throw new RuntimeException('Invalid Meta lock.',503);
        $mask=umask(0077);try{$lock=fopen($path,'c+b');}finally{umask($mask);}
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('Meta service is busy. Try again.',503);}
        try{return$operation();}finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private function read(string $type,string $subject): array { return $this->readName($this->name($type,$subject)); }

    private function readName(string $name): array
    {
        $stored=(new Runtime($this->root))->read($name); if(!$stored)return[];
        $raw=base64_decode((string)($stored['sealed']??''),true);
        if(!is_string($raw)||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid private Meta state.',503);
        $plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$this->key);
        if(!is_string($plain))throw new RuntimeException('Meta state integrity check failed.',503);
        try{$data=json_decode($plain,true,16,JSON_THROW_ON_ERROR);}finally{sodium_memzero($plain);}
        if(!is_array($data)||($data['identity']??null)!==$name||!is_array($data['data']??null))throw new RuntimeException('Invalid private Meta state.',503);
        return$data['data'];
    }

    private function write(string $type,string $subject,#[SensitiveParameter] array $data): void
    {
        $name=$this->name($type,$subject);$nonce=random_bytes(24);$plain=json_encode(['identity'=>$name,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($plain)>131072)throw new RuntimeException('Meta state limit reached.',503);
        try{(new Runtime($this->root))->write($name,['sealed'=>base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,$this->key))]);}finally{sodium_memzero($plain);}
    }

    private function delete(string $type,string $subject): void
    {
        $path=$this->root.'/storage/'.$this->name($type,$subject).'.json';
        if(is_link($path))throw new RuntimeException('Invalid private Meta state.',503);
        if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot consume private Meta state.',503);
    }

    private function name(string $type,string $subject): string { return$type.'-'.hash_hmac('sha256',$type."\0".$subject,$this->key); }
    private function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
    private function assertToken(string $token): void { if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Invalid Meta connection token.',422); }
    private function assertReady(): void { if(!$this->ready())throw new RuntimeException('The Sense CMS Meta application is not configured.',503); }
    private function graph(string $path): string { return'https://graph.facebook.com/'.$this->config['api_version'].$path; }

    private function decode64(string $value): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$value))throw new RuntimeException('Invalid data deletion encoding.',422);
        $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
        if(!is_string($decoded))throw new RuntimeException('Invalid data deletion encoding.',422);
        return$decoded;
    }

    private function request(string $method,string $url,array $headers,#[SensitiveParameter]string $payload): array
    {
        $body='';$curl=curl_init($url);if(!$curl)throw new RuntimeException('Meta transport unavailable.',503);
        $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}];
        if($method==='POST')$options[CURLOPT_POSTFIELDS]=$payload;
        curl_setopt_array($curl,$options);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
        if($ok===false)throw new RuntimeException('Meta transport unavailable.',503);
        return[$status,$body];
    }
}
