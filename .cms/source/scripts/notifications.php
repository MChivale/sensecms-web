<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
try {
    $root=dirname(__DIR__);$runtime=new App\Core\Runtime($root);
    $installed=$runtime->read('installed');$baseUrl=$runtime->baseUrl();
    $runtime->license()->enforce($baseUrl);
    $config=require $root.'/config/workspace.php';
    $result=(new App\Core\NotificationDispatcher(App\Core\Runtime::connect($installed['database']),$config,$root))->run();
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {fwrite(STDERR,"Sense CMS notification worker failed; inspect service configuration and queue status.\n");exit(1);}
