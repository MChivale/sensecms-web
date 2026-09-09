<?php
declare(strict_types=1);

// Isolated protocol fixtures: no network, DNS or real Apple credentials.
namespace SenseCMS\AppleCalendar {
    function gethostbynamel(string $host): array|false { return $GLOBALS['addresses']; }
    function curl_init(string $url): object { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
    function curl_setopt_array(object $curl, array $options): bool { $curl->options=$options; return true; }
    function curl_exec(object $curl): bool {
        $GLOBALS['requests'][]=$curl; $next=array_shift($GLOBALS['responses']);
        if (!$next) throw new \RuntimeException('Unexpected isolated request.');
        $curl->status=$next[0];
        foreach ($next[2]??[] as $header) ($curl->options[CURLOPT_HEADERFUNCTION])($curl,$header."\r\n");
        return ($curl->options[CURLOPT_WRITEFUNCTION])($curl,$next[1])===strlen($next[1]) && ($next[3]??true);
    }
    function curl_getinfo(object $curl, int $kind): int { return $curl->status; }
    function curl_close(object $curl): void {}
}
namespace {
    $provider=require dirname(__DIR__).'/.plugins/apple-calendar/src/provider.php'; $checks=0;
    function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
    function rejects(callable $fn,string $label,?int $code=null):void{try{$fn();}catch(RuntimeException $e){check(($code===null||$code===$e->getCode())&&!str_contains($e->getMessage(),'secret-marker'),$label);return;}throw new RuntimeException($label);}
    function responses(array $items):void{$GLOBALS['responses']=$items;$GLOBALS['requests']=[];$GLOBALS['addresses']=['17.248.191.1'];}
    $settings=['calendar_url'=>'https://p123-caldav.icloud.com/123456/calendars/home/','apple_id'=>'qa@example.test','app_password'=>'secret-marker'];
    $event=['uid'=>'qa-event','title'=>'Team meeting','description'=>"Test, semi; slash\\ and\r\nATTENDEE:fake",'location'=>'London','start_at'=>'2026-09-09 09:00:00','end_at'=>'2026-09-09 10:00:00','timezone'=>'Europe/London'];
    $ics=$provider->calendarData($event); $id='sensecms-'.hash('sha256',$event['uid']);
    $unfold=fn(string $s)=>preg_replace('/\r\n[ \t]/','',$s);
    check(str_contains($ics,'PRODID:-//Sense CMS//Calendar//EN')&&!str_contains($ics,'Eduvixo'),'Sense CMS branding');
    check(str_contains($ics,"DTSTART:20260909T090000Z\r\nDTEND:20260909T100000Z"),'timed dates preserve UTC instants');
    check(str_contains($unfold($ics),'UID:'.$id),'stable namespaced event UID');
    check(str_contains($unfold($ics),'Test\\, semi\\; slash\\\\ and\\nATTENDEE:fake')&&!str_contains($ics,"\r\nATTENDEE:"),'text escaped without calendar property injection');
    $long=$provider->calendarData(array_replace($event,['title'=>str_repeat('Żółw 🐢 ',60)]));
    check(max(array_map('strlen',explode("\r\n",$long)))<=75&&mb_check_encoding($long,'UTF-8'),'UTF-8 folding respects 75-octet limit');
    check(str_contains($unfold($long),'SUMMARY:'.str_repeat('Żółw 🐢 ',60)),'line unfolding preserves unicode text');
    $day=array_replace($event,['all_day'=>true,'timezone'=>'Asia/Bangkok','start_at'=>'2026-09-08 17:00:00','end_at'=>'2026-09-09 17:00:00']);
    check(str_contains($provider->calendarData($day),"DTSTART;VALUE=DATE:20260909\r\nDTEND;VALUE=DATE:20260910"),'all-day local dates and exclusive end');
    foreach([['start_at'=>'2026-02-31 09:00:00'],['end_at'=>$event['start_at']],['uid'=>''],['title'=>''],['description'=>"bad\0text"]] as $n=>$bad) rejects(fn()=>$provider->calendarData(array_replace($event,$bad)),'invalid event rejected '.$n);
    $xml='<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/123456/calendars/home/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:current-user-privilege-set><d:privilege><d:read/></d:privilege><d:privilege><d:write/></d:privilege></d:current-user-privilege-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
    responses([[207,$xml]]);$provider->verify($settings);$opt=$GLOBALS['requests'][0]->options;
    check($opt[CURLOPT_CUSTOMREQUEST]==='PROPFIND'&&in_array('Depth: 0',$opt[CURLOPT_HTTPHEADER]),'depth-zero collection and privilege verification');
    check($opt[CURLOPT_RESOLVE]===['p123-caldav.icloud.com:443:17.248.191.1']&&$opt[CURLOPT_PROXY]==='','validated DNS pinned and proxy disabled');
    check($opt[CURLOPT_SSL_VERIFYPEER]&&$opt[CURLOPT_SSL_VERIFYHOST]===2&&!$opt[CURLOPT_FOLLOWLOCATION],'verified TLS without credential-bearing redirects');
    foreach([str_replace('<d:write/>','<d:read/>',$xml),str_replace('<c:calendar/>','',$xml),str_replace('200 OK','403 Forbidden',$xml),str_replace('/calendars/home/','/calendars/other/',$xml)] as $n=>$bad){responses([[207,$bad]]);rejects(fn()=>$provider->verify($settings),'unwritable or mismatched collection refused '.$n,403);}
    foreach(['not XML','<!DOCTYPE root SYSTEM "file:///secret-marker"><root/>',mb_convert_encoding('<!DOCTYPE root SYSTEM "file:///secret-marker"><root/>','UTF-16LE','UTF-8'),str_repeat('x',262145)] as $n=>$bad){responses([[207,$bad]]);rejects(fn()=>$provider->verify($settings),'unsafe response refused '.$n);}
    foreach(['http://p123-caldav.icloud.com/123/calendars/home/','https://caldav.icloud.com.evil.test/123/calendars/home/','https://localhost/123/calendars/home/','https://user:pass@caldav.icloud.com/123/calendars/home/','https://caldav.icloud.com:444/123/calendars/home/','https://caldav.icloud.com/123/calendars/../','https://caldav.icloud.com/123/calendars/%2e%2e/','https://caldav.icloud.com/123/calendars/home/?x=1'] as $n=>$url){responses([]);rejects(fn()=>$provider->verify(array_replace($settings,['calendar_url'=>$url])),'unsafe destination refused '.$n);check(!$GLOBALS['requests'],'destination rejected before credentials sent '.$n);}
    foreach([['127.0.0.1'],['10.1.1.1'],['169.254.169.254'],['100.64.0.1'],['17.248.191.1','192.168.1.1'],false] as $n=>$ips){responses([]);$GLOBALS['addresses']=$ips;rejects(fn()=>$provider->verify($settings),'nonpublic or missing DNS refused '.$n);}
    responses([[404,''],[201,'']]);check($provider->upsert($settings,$event,null)===$id,'create returns stable resource ID');
    check(str_ends_with($GLOBALS['requests'][1]->url,'/'.$id.'.ics')&&in_array('If-None-Match: *',$GLOBALS['requests'][1]->options[CURLOPT_HTTPHEADER]),'creation cannot overwrite existing resource');
    responses([[200,$ics,['ETag: "first"']],[204,'']]);$provider->upsert($settings,$event,null);
    check(in_array('If-Match: "first"',$GLOBALS['requests'][1]->options[CURLOPT_HTTPHEADER]),'retry finds owned event and conditionally updates');
    responses([[200,$ics,['ETag: "first"']],[412,'secret-marker']]);rejects(fn()=>$provider->upsert($settings,$event,$id),'concurrent edit conflict is not overwritten',412);
    check(count($GLOBALS['requests'])===2,'no immediate conflict retry');
    foreach([['ETag: W/"weak"'],[],['ETag: invalid']] as $headers){responses([[200,$ics,$headers]]);rejects(fn()=>$provider->upsert($settings,$event,$id),'unsafe or missing ETag blocks mutation',409);}
    responses([[200,str_replace($id,'another-event',$unfold($ics)),['ETag: "first"']]]);rejects(fn()=>$provider->upsert($settings,$event,$id),'foreign UID blocks mutation',409);
    responses([]);rejects(fn()=>$provider->upsert($settings,$event,'other-id'),'mapping mismatch rejected');
    responses([[404,'']]);$provider->delete($settings,$id);check(count($GLOBALS['requests'])===1,'absent event delete is idempotent');
    responses([[200,$ics,['ETag: "first"']],[204,'']]);$provider->delete($settings,$id);
    check($GLOBALS['requests'][1]->options[CURLOPT_CUSTOMREQUEST]==='DELETE'&&in_array('If-Match: "first"',$GLOBALS['requests'][1]->options[CURLOPT_HTTPHEADER]),'delete uses verified UID and ETag');
    responses([]);rejects(fn()=>$provider->delete($settings,'../foreign'),'invalid delete target refused');
    foreach([301,401,403,429,500] as $status){responses([[$status,'secret-marker']]);rejects(fn()=>$provider->verify($settings),'HTTP '.$status.' remains sanitized failure',$status);}
    responses([[207,$xml,[],false]]);rejects(fn()=>$provider->verify($settings),'transport failure does not become verified');
    echo "$checks Apple Calendar checks passed; no live iCloud requests.\n";
}
