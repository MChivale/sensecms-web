<?php
declare(strict_types=1);

// Test-only transport replacement, confined to the provider's namespace.
namespace SenseCMS\MicrosoftCalendar {
    function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
    function curl_setopt_array(object $curl, array $options): bool { $curl->options=$options; return true; }
    function curl_exec(object $curl): bool {
        $GLOBALS['requests'][]=$curl;$next=array_shift($GLOBALS['responses']);
        if (!$next) throw new \RuntimeException('Unexpected request in isolated test.');
        $curl->status=$next[0];$body=is_array($next[1])?json_encode($next[1]):$next[1];
        return ($curl->options[CURLOPT_WRITEFUNCTION])($curl,$body)===strlen($body);
    }
    function curl_getinfo(object $curl, int $kind): int { return $curl->status; }
    function curl_close(object $curl): void {}
}
namespace {
    $provider=require dirname(__DIR__).'/.plugins/microsoft-365-calendar/src/provider.php';
    $checks=0;
    function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
    function rejects(callable $fn,string $label,?int $code=null):void{try{$fn();}catch(RuntimeException $error){check(($code===null||$code===$error->getCode())&&!str_contains($error->getMessage(),'secret-marker'),$label);return;}throw new RuntimeException($label);}
    function responses(array $items):void{$GLOBALS['responses']=$items;$GLOBALS['requests']=[];}
    $settings=['tenant_id'=>'11111111-2222-3333-4444-555555555555','client_id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee','client_secret'=>'secret-marker','user_id'=>'qa@example.test','calendar_id'=>''];
    $event=['uid'=>'qa-event','title'=>'Team meeting','description'=>'<b>Plain text</b>','location'=>'London','start_at'=>'2026-09-09 09:00:00','end_at'=>'2026-09-09 10:00:00','timezone'=>'Europe/London'];
    $payload=$provider->payload($event);
    check($payload['start']===['dateTime'=>'2026-09-09T09:00:00','timeZone'=>'UTC'],'UTC instant preserved');
    check($payload['body']['contentType']==='text'&&!isset($payload['attendees']),'text body and no guest invitations');
    $day=array_replace($event,['all_day'=>true,'timezone'=>'Asia/Bangkok','start_at'=>'2026-09-08 17:00:00','end_at'=>'2026-09-09 17:00:00']);
    check($provider->payload($day)['start']===['dateTime'=>'2026-09-09T00:00:00','timeZone'=>'Asia/Bangkok'],'all-day uses local midnight');
    check($provider->payload($day)['end']['dateTime']==='2026-09-10T00:00:00','all-day end exclusive');
    rejects(fn()=>$provider->payload(array_replace($event,['start_at'=>'2026-02-31 09:00:00'])),'invalid date rejected');
    rejects(fn()=>$provider->payload(array_replace($event,['end_at'=>$event['start_at']])),'zero duration rejected');
    $token=[200,['access_token'=>'test-access']];
    responses([$token,[200,['canEdit'=>true]]]);$provider->verify($settings);
    check(count($GLOBALS['requests'])===2,'app token then calendar verification');
    check(str_ends_with($GLOBALS['requests'][1]->url,'/qa%40example.test/calendar'),'default mailbox calendar path');
    check($GLOBALS['requests'][1]->options[CURLOPT_FOLLOWLOCATION]===false&&$GLOBALS['requests'][1]->options[CURLOPT_SSL_VERIFYPEER],'TLS verification and no redirects');
    check(in_array('Prefer: IdType="ImmutableId"',$GLOBALS['requests'][1]->options[CURLOPT_HTTPHEADER],true),'immutable Graph event IDs requested');
    responses([$token,[200,['canEdit'=>false]]]);rejects(fn()=>$provider->verify($settings),'read-only calendar refused',403);
    responses([]);rejects(fn()=>$provider->verify(array_replace($settings,['tenant_id'=>'common'])),'generic tenant refused');
    check(!$GLOBALS['requests'],'invalid config rejected before network');
    responses([$token,[200,['canEdit'=>true]]]);$provider->verify(array_replace($settings,['calendar_id'=>'id/with+chars']));
    check(str_ends_with($GLOBALS['requests'][1]->url,'/calendars/id%2Fwith%2Bchars'),'selected calendar encoded as one path segment');
    responses([$token,[201,['id'=>'immutable-event']]]);check($provider->upsert($settings,$event,null)==='immutable-event','creation returns Graph event ID');
    $first=json_decode($GLOBALS['requests'][1]->options[CURLOPT_POSTFIELDS],true);
    responses([$token,[201,['id'=>'immutable-event']]]);$provider->upsert($settings,$event,null);
    check($first['transactionId']===json_decode($GLOBALS['requests'][1]->options[CURLOPT_POSTFIELDS],true)['transactionId'],'creation retries reuse transaction ID');
    responses([$token,[200,['id'=>'immutable-event']]]);$provider->upsert($settings,$event,'immutable-event');
    check($GLOBALS['requests'][1]->options[CURLOPT_CUSTOMREQUEST]==='PATCH'&&!isset(json_decode($GLOBALS['requests'][1]->options[CURLOPT_POSTFIELDS],true)['transactionId']),'update uses PATCH without creation transaction');
    foreach([204,404] as $status){responses([$token,[$status,'']]);$provider->delete($settings,'immutable-event');check(true,'idempotent delete HTTP '.$status);}
    foreach([401,403,429,500] as $status){responses([$token,[$status,['error'=>'secret-marker']]]);rejects(fn()=>$provider->upsert($settings,$event,null),'HTTP '.$status.' remains failure without leaking body',$status);check(count($GLOBALS['requests'])===2,'no immediate retry for HTTP '.$status);}
    responses([[400,['error'=>'secret-marker']]]);rejects(fn()=>$provider->verify($settings),'token rejection sanitized',400);
    responses([[200,['access_token'=>"bad\r\ntoken"]]]);rejects(fn()=>$provider->verify($settings),'unsafe bearer token rejected');
    responses([$token,[200,'not json']]);rejects(fn()=>$provider->verify($settings),'invalid JSON rejected');
    responses([$token,[200,str_repeat('x',262145)]]);rejects(fn()=>$provider->verify($settings),'oversized response rejected');
    responses([$token,[200,['id'=>'changed-id']]]);rejects(fn()=>$provider->upsert($settings,$event,'immutable-event'),'changed immutable identity rejected');
    echo "$checks Microsoft Calendar checks passed; no live Graph requests.\n";
}
