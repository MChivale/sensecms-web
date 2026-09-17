<?php

declare(strict_types=1);

use App\Core\Runtime;
use SenseCMS\Social\SocialDispatcher;
use SenseCMS\Social\SocialIntegrationManager;
use SenseCMS\Social\SocialRepository;

if(PHP_SAPI!=='cli')exit("CLI only\n");
$root=rtrim((string)($argv[1]??dirname(__DIR__,3)),'/\\');
$lock=fopen(sys_get_temp_dir().'/sensecms-social-'.substr(hash('sha256',$root),0,16).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit("Social Publishing worker is already running.\n");
require $root.'/bootstrap.php';
require_once dirname(__DIR__).'/src/SocialIntegrationManager.php';
require_once dirname(__DIR__).'/src/SocialRepository.php';
require_once dirname(__DIR__).'/src/SocialDispatcher.php';
$runtime=new Runtime($root);$installed=$runtime->read('installed');$baseUrl=$runtime->baseUrl();
if(!is_array($installed)||!is_array($installed['database']??null))throw new RuntimeException('Sense CMS is not installed.');
$config=require$root.'/config/workspace.php';$db=Runtime::connect($installed['database']);
$manager=new SocialIntegrationManager($db,$root,(string)$config['secrets_key']);$repository=new SocialRepository($db,$manager,(string)$config['base_url']);
$result=(new SocialDispatcher($db,$manager,$repository))->run((int)($argv[2]??25));
echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
