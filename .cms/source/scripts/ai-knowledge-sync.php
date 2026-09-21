<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';

try {
    $root=dirname(__DIR__);$runtime=new App\Core\Runtime($root);$installed=$runtime->read('installed');
    if(!$installed)throw new RuntimeException('Complete the licensed Core installation first.');
    $runtime->license()->enforce($runtime->baseUrl());
    $service=new App\Core\AiKnowledgeBase(App\Core\Runtime::connect($installed['database']),$root);
    if(($argv[1]??'')==='--reconcile')$result=$service->rebuild(0);
    elseif(count($argv)>1)throw new RuntimeException('Usage: php scripts/ai-knowledge-sync.php [--reconcile]');
    else$result=$service->processQueue(50);
    if(($argv[1]??'')==='--reconcile'||!empty($result['processed'])||!empty($result['failed'])||!empty($result['locked']))echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    error_log('AI Knowledge Base worker failed: '.$error->getMessage());
    fwrite(STDERR,"AI Knowledge Base synchronization failed; inspect the private worker log.\n");
    exit(1);
}
