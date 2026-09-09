<?php
declare(strict_types=1);

// Operator-only provisioning. Credentials arrive on stdin, never command arguments.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
ini_set('display_errors','0');
try {
    $action=$argv[1]??'';$root=$argv[2]??'';
    if (!in_array($action,['provision','activate','check'],true)||$root!=='/home/sensecms.com/web') throw new RuntimeException();
    require $root.'/bootstrap.php';
    $private=$root.'/storage/private/telegram';
    $request=static function(string $url,array $headers,string $body):array {
        $response='';$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>65536)return 0;$response.=$chunk;return strlen($chunk);}]);
        try {$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
        $data=json_decode($response,true);
        if ($ok===false||$status!==200||!is_array($data)||($data['ok']??null)!==true) throw new RuntimeException();
        return $data;
    };
    if ($action==='provision') {
        if (file_exists($private)||is_link($private)) throw new RuntimeException();
        $cfg=json_decode((string)stream_get_contents(STDIN,8193),true,8,JSON_THROW_ON_ERROR);
    } else {
        if (is_link($private)||!is_dir($private)||(fileperms($private)&0077)) throw new RuntimeException();
        $path=$private.'/config.json';
        if (is_link($path)||!is_file($path)||filesize($path)>8192||(fileperms($path)&0077)) throw new RuntimeException();
        $cfg=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    }
    if (($cfg['bot_username']??'')!=='SenseCMSBot'||!preg_match('/^[0-9]{6,15}:[A-Za-z0-9_-]{30,100}$/D',(string)($cfg['bot_token']??''))) throw new RuntimeException();
    $api=static fn(string $method,array $input=[]):array=>$request('https://api.telegram.org/bot'.$cfg['bot_token'].'/'.$method,['Content-Type: application/json'],json_encode((object)$input,JSON_THROW_ON_ERROR));
    $bot=$api('getMe')['result']??[];
    if (($bot['is_bot']??false)!==true||($bot['username']??'')!==$cfg['bot_username']) throw new RuntimeException();
    $hook=$api('getWebhookInfo')['result']??[];
    $url='https://www.sensecms.com/api/telegram/v1/webhook';
    if (!isset($hook['url'])||!in_array($hook['url'],['',$url],true)) throw new RuntimeException();
    if ($action==='provision') {
        if ($hook['url']!=='') throw new RuntimeException();
        $cfg=['bot_username'=>$bot['username'],'bot_token'=>$cfg['bot_token'],'webhook_secret'=>bin2hex(random_bytes(32))];
        $mask=umask(0077);
        try {
            if (!mkdir($private,0700,true)) throw new RuntimeException();
            foreach (['config.json'=>json_encode($cfg,JSON_THROW_ON_ERROR),'key.bin'=>random_bytes(32)] as $name=>$value) {
                $file=fopen($private.'/'.$name,'xb');
                if (!$file) throw new RuntimeException();
                try {if(fwrite($file,$value)!==strlen($value))throw new RuntimeException();}finally{fclose($file);}
            }
        } finally {umask($mask);}
        echo "Verified @SenseCMSBot; private configuration provisioned. No webhook changed.\n";exit;
    }
    if (!preg_match('/^[a-f0-9]{64}$/D',(string)($cfg['webhook_secret']??''))) throw new RuntimeException();
    $request($url,['Content-Type: application/json','X-Telegram-Bot-Api-Secret-Token: '.$cfg['webhook_secret']],'{}');
    if ($action==='activate') {
        if (($api('setWebhook',['url'=>$url,'secret_token'=>$cfg['webhook_secret'],'max_connections'=>1,'allowed_updates'=>['message'],'drop_pending_updates'=>false])['result']??null)!==true) throw new RuntimeException();
        $hook=$api('getWebhookInfo')['result']??[];
    }
    if (($hook['url']??'')!==$url||($hook['max_connections']??0)!==1||($hook['allowed_updates']??null)!==['message']) throw new RuntimeException();
    echo json_encode(['bot'=>'@'.$bot['username'],'webhook_verified'=>true,'authenticated_ingress'=>true,'pending_updates'=>(int)($hook['pending_update_count']??0),'last_error_present'=>!empty($hook['last_error_date'])],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {fwrite(STDERR,"Telegram setup/check failed; credentials and upstream errors suppressed. Inspect configuration and service health.\n");exit(1);}
