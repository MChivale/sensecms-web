<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
try{
    $root=dirname(__DIR__);require $root.'/bootstrap.php';require __DIR__.'/TelegramBrokerService.php';
    $private=$root.'/storage/private/telegram';
    foreach(['config.json','key.bin'] as $file){$path=$private.'/'.$file;if(is_link($path)||!is_file($path)||filesize($path)>8192||(PHP_OS_FAMILY!=='Windows'&&(fileperms($path)&0077)))throw new RuntimeException('Invalid private service configuration.');}
    $cfg=json_decode((string)file_get_contents($private.'/config.json'),true,8,JSON_THROW_ON_ERROR);
    $key=(string)file_get_contents($private.'/key.bin');
    $broker=new SenseCMS\Website\TelegramBrokerService($private,$key,$cfg);
    sodium_memzero($key);unset($cfg);
    echo json_encode($broker->cleanup(),JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDERR,"Telegram maintenance failed; inspect private state integrity.\n");exit(1);}
