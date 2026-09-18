<?php

declare(strict_types=1);

namespace SenseCMS\Bluesky;

use App\Core\Runtime;
use RuntimeException;

require_once __DIR__.'/BlueskyDpop.php';
require_once __DIR__.'/BlueskyOnboardingClient.php';

return new class {
    private string $root='';

    public function initialize(string$root): void{$this->root=$root;}

    public function verify(array$credentials): array
    {
        $credentials=$this->fresh($credentials);[$data,$nonce]=$this->request('GET','/xrpc/com.atproto.server.getSession','',$credentials);$did=(string)($data['did']??'');$handle=strtolower((string)($data['handle']??''));
        if($did!==$credentials['did']||!preg_match('/^[a-z0-9](?:[a-z0-9.-]{1,251}[a-z0-9])?$/D',$handle))throw new RuntimeException('The connected Bluesky account is unavailable to this access token.');
        $credentials['handle']=$handle;$credentials['resource_nonce']=$nonce;return['external_id'=>$did,'display_name'=>'@'.$handle,'credentials'=>$credentials];
    }

    public function publish(array$credentials,array$payload): array
    {
        $credentials=$this->fresh($credentials);$message=trim((string)($payload['message']??''));$url=trim((string)($payload['url']??''));$title=trim((string)($payload['title']??''));$excerpt=trim(strip_tags((string)($payload['excerpt']??'')));
        if($message===''||mb_strlen($message)>300)throw new RuntimeException('The Bluesky message is empty or too long.');if(!$this->https($url))throw new RuntimeException('The Bluesky destination URL is invalid.');
        $record=['$type'=>'app.bsky.feed.post','text'=>$message,'createdAt'=>gmdate('Y-m-d\TH:i:s\Z'),'embed'=>['$type'=>'app.bsky.embed.external','external'=>['uri'=>$url,'title'=>mb_substr($title!==''?$title:$message,0,300),'description'=>mb_substr($excerpt,0,1000)]]];
        $body=json_encode(['repo'=>$credentials['did'],'collection'=>'app.bsky.feed.post','record'=>$record],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);[$data,$nonce]=$this->request('POST','/xrpc/com.atproto.repo.createRecord',$body,$credentials);$uri=(string)($data['uri']??'');
        if(!preg_match('~^at://'.preg_quote($credentials['did'],'~').'/app\.bsky\.feed\.post/([A-Za-z0-9._:-]{1,255})$~D',$uri,$match)||!is_string($data['cid']??null)||$data['cid']==='')throw new RuntimeException('Bluesky returned an invalid publication identifier.');
        $credentials['resource_nonce']=$nonce;return['external_id'=>$match[1],'external_url'=>'https://bsky.app/profile/'.$credentials['did'].'/post/'.$match[1],'credentials'=>$credentials];
    }

    private function fresh(array$credentials,bool$force=false): array
    {
        $this->validate($credentials);if(!$force&&(int)$credentials['expires_at']>time()+120)return$credentials;$fresh=$this->client()->refresh($credentials);
        foreach(['access_token','refresh_token','expires_at','scope','auth_nonce']as$field)if(array_key_exists($field,$fresh))$credentials[$field]=$fresh[$field];$this->validate($credentials);return$credentials;
    }

    private function validate(array$credentials): void
    {
        if(!preg_match('/^did:plc:[a-z2-7]{24}$/D',(string)($credentials['did']??''))||!preg_match('/^[a-z0-9](?:[a-z0-9.-]{1,251}[a-z0-9])?$/D',(string)($credentials['handle']??''))||strlen((string)($credentials['access_token']??''))<20||strlen((string)($credentials['refresh_token']??''))<20||(int)($credentials['expires_at']??0)<1||!str_starts_with((string)($credentials['dpop_private_key']??''),'-----BEGIN PRIVATE KEY-----')||!preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($credentials['dpop_x']??''))||!preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($credentials['dpop_y']??'')))throw new RuntimeException('The Bluesky connection credentials are invalid.');$this->pds($credentials);
    }

    private function client(): BlueskyOnboardingClient
    {
        if($this->root===''||!is_dir($this->root))throw new RuntimeException('The Bluesky provider runtime is unavailable.');$runtime=new Runtime($this->root);$config=require$this->root.'/config/workspace.php';return new BlueskyOnboardingClient($runtime->license(),(string)($config['integrations']['bluesky_social_broker_url']??''),(string)$config['base_url']);
    }

    private function request(string$method,string$path,string$payload,array$credentials): array
    {
        if(!in_array([$method,$path],[['GET','/xrpc/com.atproto.server.getSession'],['POST','/xrpc/com.atproto.repo.createRecord']],true))throw new RuntimeException('The Bluesky API address is invalid.');$url=$this->pds($credentials).$path;$nonce=(string)($credentials['resource_nonce']??'');
        for($attempt=0;$attempt<2;$attempt++){
            $body='';$headers=[];$proof=BlueskyDpop::proof($method,$url,(string)$credentials['dpop_private_key'],(string)$credentials['dpop_x'],(string)$credentials['dpop_y'],$nonce?:null,(string)$credentials['access_token']);$curl=curl_init($url);if($curl===false)throw new RuntimeException('Bluesky publishing could not be initialized.');
            $requestHeaders=['Authorization: DPoP '.$credentials['access_token'],'DPoP: '.$proof,'Accept: application/json'];$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$requestHeaders,CURLOPT_USERAGENT=>'SenseCMS-Bluesky-Publisher/0.1',CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>static function($handle,string$line)use(&$headers):int{$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($handle,string$chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}];if($method==='POST'){$options[CURLOPT_POSTFIELDS]=$payload;$options[CURLOPT_HTTPHEADER][]='Content-Type: application/json';}curl_setopt_array($curl,$options);$ok=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$data=json_decode($body,true);$issued=trim((string)($headers['dpop-nonce']??''));if($issued!=='')$nonce=$issued;
            if($attempt===0&&in_array($status,[400,401],true)&&$issued!=='')continue;if($ok===false||$error!==''||$status<200||$status>=300||!is_array($data))throw new RuntimeException($status===429?'Bluesky temporarily limited publishing.':($status===401?'The Bluesky access token must be refreshed.':'Bluesky rejected the publication request.'),$status===429?429:($status===401?401:502));if($nonce==='')throw new RuntimeException('Bluesky did not return the required proof nonce.',502);return[$data,$nonce];
        }throw new RuntimeException('Bluesky proof negotiation failed.',502);
    }

    private function pds(array$credentials): string
    {
        $url=(string)($credentials['pds']??'');$parts=parse_url($url);if(!is_array($parts))throw new RuntimeException('The Bluesky connection PDS is invalid.');$host=strtolower((string)($parts['host']??''));$official=$host==='bsky.social'||($host!=='host.bsky.network'&&str_ends_with($host,'.host.bsky.network'));if(!filter_var($url,FILTER_VALIDATE_URL)||strtolower((string)($parts['scheme']??''))!=='https'||!$official||filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false||(int)($parts['port']??443)!==443||!in_array((string)($parts['path']??''),['','/'],true)||isset($parts['query'])||isset($parts['fragment'])||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('The Bluesky connection PDS is invalid.');return'https://'.$host;
    }

    private function https(string$url): bool{$parts=parse_url($url);return filter_var($url,FILTER_VALIDATE_URL)!==false&&is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!isset($parts['user'])&&!isset($parts['pass']);}
};
