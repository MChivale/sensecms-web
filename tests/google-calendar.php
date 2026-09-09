<?php
declare(strict_types=1);

// Isolated protocol tests: replace cURL only in the provider namespace, never in production.
namespace SenseCMS\GoogleCalendar {
    function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
    function curl_setopt_array(object $curl, array $options): bool { $curl->options=$options; return true; }
    function curl_exec(object $curl): bool
    {
        $GLOBALS['requests'][]=$curl;
        $next=array_shift($GLOBALS['responses']);
        if (!$next) throw new \RuntimeException('Unexpected outbound request in test.');
        $curl->status=$next[0]; $body=is_array($next[1])?json_encode($next[1]):$next[1];
        return ($curl->options[CURLOPT_WRITEFUNCTION])($curl,$body)===strlen($body);
    }
    function curl_getinfo(object $curl, int $kind): int { return $curl->status; }
    function curl_close(object $curl): void {}
}
namespace {
    $provider=require dirname(__DIR__).'/.plugins/google-calendar/src/provider.php';
    $checks=0;
    function check(bool $ok, string $label): void { global $checks; if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n"; }
    function rejects(callable $fn, string $label, ?int $code=null): void {
        try{$fn();}catch(RuntimeException $error){check($code===null||$error->getCode()===$code,$label);return;}throw new RuntimeException($label);
    }
    function responses(array $items): void { $GLOBALS['responses']=$items;$GLOBALS['requests']=[]; }
    $settings=['calendar_id'=>'team@example.test','client_id'=>'test-client','client_secret'=>'test-secret','refresh_token'=>'test-refresh'];
    $event=['uid'=>'test-event','title'=>'Project meeting','description'=>'Details','location'=>'London','start_at'=>'2026-09-09 09:00:00','end_at'=>'2026-09-09 10:00:00','timezone'=>'Europe/London'];
    $payload=$provider->payload($event);
    check($payload['start']['dateTime']==='2026-09-09T09:00:00+00:00','timed events preserve UTC instant');
    check(!isset($payload['attendees']),'no implicit guest invitations');
    $day=array_replace($event,['all_day'=>true,'start_at'=>'2026-09-08 17:00:00','end_at'=>'2026-09-09 17:00:00','timezone'=>'Asia/Bangkok']);
    check($provider->payload($day)['start']['date']==='2026-09-09','all-day start uses local date');
    check($provider->payload($day)['end']['date']==='2026-09-10','all-day end remains exclusive');
    rejects(fn()=>$provider->payload(array_replace($event,['start_at'=>'2026-02-31 09:00:00'])),'invalid date rejected');
    rejects(fn()=>$provider->payload(array_replace($event,['end_at'=>$event['start_at']])),'zero duration rejected');
    responses([[200,['access_token'=>'test-access']],[200,['accessRole'=>'writer']]]);
    $provider->verify($settings);check(count($GLOBALS['requests'])===2,'OAuth refresh then writable-calendar verification');
    $request=$GLOBALS['requests'][1];
    check(str_ends_with($request->url,'team%40example.test'),'destination encoded as one path segment');
    check($request->options[CURLOPT_FOLLOWLOCATION]===false&&$request->options[CURLOPT_SSL_VERIFYPEER]===true,'redirects disabled and TLS verified');
    responses([[200,['access_token'=>'test-access']],[200,['accessRole'=>'reader']]]);
    rejects(fn()=>$provider->verify($settings),'read-only calendar refused',403);
    responses([]);rejects(fn()=>$provider->verify(array_replace($settings,['calendar_id'=>"bad\r\nvalue"])),'invalid calendar rejected before network');
    check(!$GLOBALS['requests'],'invalid configuration sends no request');
    $id='sc'.hash('sha256',$event['uid']);
    responses([[200,['access_token'=>'test-access']],[201,['id'=>$id]]]);
    check($provider->upsert($settings,$event,null)===$id,'create uses stable Sense CMS event identity');
    check($GLOBALS['requests'][1]->options[CURLOPT_CUSTOMREQUEST]==='POST','new event uses POST');
    responses([[200,['access_token'=>'test-access']],[409,['error'=>'conflict']],[200,['access_token'=>'test-access']],[200,['id'=>$id]]]);
    check($provider->upsert($settings,$event,null)===$id,'duplicate creation retries the same event');
    check($GLOBALS['requests'][3]->options[CURLOPT_CUSTOMREQUEST]==='PUT'&&str_ends_with($GLOBALS['requests'][3]->url,$id),'conflict recovery updates deterministic ID');
    responses([[200,['access_token'=>'test-access']],[200,['id'=>$id]]]);
    check($provider->upsert($settings,$event,$id)===$id,'mapped event updates');
    foreach([204,404,410] as $status){responses([[200,['access_token'=>'test-access']],[$status,'']]);$provider->delete($settings,$id);check(true,'delete is idempotent for HTTP '.$status);}
    responses([[200,['access_token'=>'test-access']],[429,['error'=>'limited']]]);
    rejects(fn()=>$provider->upsert($settings,$event,null),'rate limit is a failure, not success',429);
    check(count($GLOBALS['requests'])===2,'rate limit not retried immediately');
    responses([[400,['error'=>'invalid_grant','secret'=>'do-not-expose']]]);
    rejects(fn()=>$provider->verify($settings),'OAuth failure propagated without upstream content',400);
    responses([[200,['access_token'=>"token\r\ninjection"]]]);
    rejects(fn()=>$provider->verify($settings),'unsafe bearer token refused');
    responses([[200,['access_token'=>'test-access']],[200,'not json']]);
    rejects(fn()=>$provider->verify($settings),'malformed JSON rejected');
    responses([[200,['access_token'=>'test-access']],[200,str_repeat('x',262145)]]);
    rejects(fn()=>$provider->verify($settings),'oversized upstream response rejected');
    responses([[200,['access_token'=>'test-access']],[201,['id'=>'different']]]);
    rejects(fn()=>$provider->upsert($settings,$event,null),'unexpected external identity rejected');
    echo "$checks Google Calendar protocol checks passed; no live Google requests.\n";
}
