<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
try{
    $root=dirname(__DIR__);$runtime=new App\Core\Runtime($root);
    $runtime->license()->enforce($runtime->baseUrl());
    $service=new App\Core\SystemUpdate(App\Core\Runtime::connect($runtime->read('installed')['database']),$root,[]);
    $service->checkIfDue();$state=$service->status();
    if(!$state['verified'])throw new RuntimeException('Unverified release state.');
    echo json_encode(['checked_at'=>$state['checked_at'],'available'=>$state['available'],'version'=>$state['version']],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable){fwrite(STDERR,"Release check unavailable; inspect the Update panel and publisher trust.\n");exit(1);}
