<?php
declare(strict_types=1);

namespace App\Core {
    // Protocol-only licence and transport fixtures, never loaded by the application.
    if (!in_array('--real-license', $argv, true)) {
        class LicenseService { public function telegramHeaders(string $url,string $broker): array { return ['X-Test-Identity: fixture']; } }
    }
    function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
    function curl_setopt_array(object $curl,array $options): bool { $curl->options=$options;return true; }
    function curl_exec(object $curl): bool {
        $GLOBALS['requests'][]=$curl;$next=array_shift($GLOBALS['responses']);
        if (!$next) throw new \RuntimeException('Unexpected test request.');
        $curl->status=$next[0];$body=is_array($next[1])?json_encode($next[1]):$next[1];
        return ($curl->options[CURLOPT_WRITEFUNCTION])($curl,$body)===strlen($body)&&($next[2]??true);
    }
    function curl_getinfo(object $curl,int $kind): int { return $curl->status; }
    function curl_close(object $curl): void {}
}
namespace {
    require dirname(__DIR__).'/.cms/source/bootstrap.php';
    $count=0;
    function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
    function rejects(callable $fn,string $label,int $code):void{try{$fn();}catch(RuntimeException $e){check($e->getCode()===$code&&!str_contains($e->getMessage(),'secret-marker'),$label);return;}throw new RuntimeException($label);}
    function response(array $value):void{$GLOBALS['responses']=[$value];$GLOBALS['requests']=[];}
    $real=in_array('--real-license',$argv,true);
    $license=$real?(new App\Core\Runtime(dirname(__DIR__).'/.cms/source'))->license():new App\Core\LicenseService();
    $client=new App\Core\TelegramConnectionClient($license,['base_url'=>'https://www.sensecms.com/api/telegram/v1'],'https://installation.example.test');
    rejects(fn()=>new App\Core\TelegramConnectionClient($license,['base_url'=>''],'https://installation.example.test'),'missing broker fails closed',503);
    rejects(fn()=>new App\Core\TelegramConnectionClient($license,['base_url'=>'https://other.example/telegram'],'https://installation.example.test'),'third party broker rejected before credential export',503);
    if($real){response([]);rejects(fn()=>$client->status(1),'missing valid installation licence fails closed',403);check(!$GLOBALS['requests'],'no licence or account transmitted without valid identity');exit;}
    $token=str_repeat('a',43);$url='https://t.me/SenseCMSBot?start='.$token;
    response([200,['user_ids'=>[]]]);check($client->recipients()===[]&&$GLOBALS['requests'][0]->options[CURLOPT_POSTFIELDS]==='{}','empty broker request encoded as JSON object');
    response([200,['connect_url'=>$url,'request_token'=>$token,'expires_in'=>600]]);
    check($client->start(1,'QA')['connect_url']===$url,'exact Telegram connection link accepted');
    $options=$GLOBALS['requests'][0]->options;
    check($options[CURLOPT_SSL_VERIFYPEER]&&$options[CURLOPT_SSL_VERIFYHOST]===2&&!$options[CURLOPT_FOLLOWLOCATION],'verified TLS without redirects');
    check($options[CURLOPT_USERAGENT]==='SenseCMS-Telegram-Connection/1.0','Sense CMS transport identity');
    foreach(['https://evil.example/SenseCMSBot?start='.$token,'https://t.me/SenseCMSBot?notstart='.$token,$url.'extra',$url.'&start=other',$url.'#fragment','https://user@t.me/SenseCMSBot?start='.$token,'https://t.me:444/SenseCMSBot?start='.$token] as $n=>$bad){response([200,['connect_url'=>$bad,'request_token'=>$token]]);rejects(fn()=>$client->start(1,'QA'),'unsafe connection link refused '.$n,502);}
    foreach([403,404,409,429,500] as $status){response([$status,['message'=>'secret-marker']]);rejects(fn()=>$client->status(1),'upstream errors sanitized '.$status,$status===500?502:$status);check(count($GLOBALS['requests'])===1,'no automatic retry '.$status);}
    foreach(['[]','not json',str_repeat('x',131073)] as $n=>$bad){response([200,$bad]);rejects(fn()=>$client->status(1),'invalid response refused '.$n,502);}
    response([200,['sent'=>false]]);rejects(fn()=>$client->deliver(1,hash('sha256','qa'),'QA','Test'),'unconfirmed delivery remains failure',502);
    response([200,['sent'=>true],false]);rejects(fn()=>$client->deliver(1,hash('sha256','qa'),'QA','Test'),'transport failure not success',502);
    echo "$count isolated Telegram client checks passed; no live Telegram requests.\n";
}
