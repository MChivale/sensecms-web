<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class WebPushSender
{
    private const DEFAULT_HOSTS=['fcm.googleapis.com','updates.push.services.mozilla.com','push.services.mozilla.com','web.push.apple.com','notify.windows.com'];
    public function __construct(private readonly WebPushKeyStore $keys,private readonly array $config) {}

    public function assertEndpoint(string $endpoint): void { $this->publicAddress($this->host($endpoint)); }

    public function send(array $subscription,array $payload): array
    {
        $endpoint=(string)($subscription['endpoint']??'');$host=$this->host($endpoint);$ip=$this->publicAddress($host);
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $crypto=new WebPushCrypto();$body=$crypto->encrypt($json,(string)$subscription['p256dh'],(string)$subscription['auth']);
        $curl=curl_init($endpoint);if(!$curl)throw new RuntimeException('The Web Push request could not be initialized.');
        $responseBytes=0;$resolved=str_contains($ip,':')?'['.$ip.']':$ip;
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Authorization: '.$crypto->authorization($endpoint,$this->keys),'Content-Encoding: aes128gcm','Content-Type: application/octet-stream','TTL: 86400','Urgency: normal'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>[$host.':443:'.$resolved],CURLOPT_USERAGENT=>'SenseCMS-WebPush/1.0',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$responseBytes):int{$responseBytes+=strlen($chunk);return $responseBytes<=65536?strlen($chunk):0;}]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
        if ($ok===false) throw new RuntimeException('The push service response was indeterminate'.($error!==''?': '.mb_substr($error,0,100):'.'));
        return ['status'=>$status,'accepted'=>in_array($status,[200,201,202],true),'expired'=>in_array($status,[404,410],true),'retryable'=>$status===429||$status>=500];
    }

    private function host(string $endpoint): string
    {
        if (strlen($endpoint)>2048||!filter_var($endpoint,FILTER_VALIDATE_URL)) throw new RuntimeException('The push endpoint is invalid.');
        $parts=parse_url($endpoint);$host=strtolower((string)($parts['host']??''));
        if (($parts['scheme']??'')!=='https'||$host===''||isset($parts['user'])||isset($parts['pass'])||(isset($parts['port'])&&(int)$parts['port']!==443)) throw new RuntimeException('The push endpoint is invalid.');
        $allowed=array_values(array_filter(array_map(static fn($value)=>strtolower(trim((string)$value)),(array)($this->config['allowed_hosts']??self::DEFAULT_HOSTS))));
        foreach ($allowed as $suffix) if ($host===$suffix||str_ends_with($host,'.'.$suffix)) return $host;
        throw new RuntimeException('The browser push service is not trusted by this installation.');
    }

    private function publicAddress(string $host): string
    {
        $records=dns_get_record($host,DNS_A|DNS_AAAA);foreach($records?:[] as $record){$ip=(string)($record['ip']??$record['ipv6']??'');if($ip!==''&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return $ip;}
        throw new RuntimeException('The browser push service address could not be verified.');
    }
}
