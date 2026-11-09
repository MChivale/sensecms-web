<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
try {
    $root=dirname(__DIR__);$runtime=new App\Core\Runtime($root);
    $installed=$runtime->read('installed');$baseUrl=$runtime->baseUrl();
    $runtime->license()->enforce($baseUrl);
    $config=require $root.'/config/workspace.php';
    $db=App\Core\Runtime::connect($installed['database']);
    $cms=new App\Core\CmsRepository($db,new App\Core\EventBus(),$runtime);
    $email=new App\Core\EmailSystem($db,$cms,new App\Core\Secrets((string)$config['secrets_key']),$config,$baseUrl);
    $jobs=[
        'callback_teams'=>fn()=>(new App\Core\ChatCallbackTeam($db,$cms))->route(),
        'chat_receipts'=>fn()=>$email->deliverChatReceipts(),
        'callback_team_emails'=>fn()=>$email->deliverChatReceipts(true),
        'callback_calendar'=>fn()=>(new App\Core\ExtensionRuntime($db,$root))->recordChatCalendarTasks(),
        'notifications'=>fn()=>(new App\Core\NotificationDispatcher($db,$config,$root))->run(),
    ];
    $result=[];$failed=false;
    foreach ($jobs as $name=>$job) {
        try {
            $outcome=$job();
            if ($name==='notifications') $result+=$outcome;
            else $result[$name]=$outcome;
        } catch (Throwable) {
            $result[$name]=['error'=>'Worker failed.'];$failed=true;
        }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    if ($failed) exit(1);
} catch (Throwable) {fwrite(STDERR,"Sense CMS notification worker failed; inspect service configuration and queue status.\n");exit(1);}
