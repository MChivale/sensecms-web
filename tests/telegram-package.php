<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows') exit(1);
$root='/root/sense-workspace-test.SC495Gg0/.cms/source';
require $root.'/bootstrap.php';
$runtime=new App\Core\Runtime($root);$db=App\Core\Runtime::connect($runtime->read('installed')['database']);
$manager=new App\Core\PackageManager($db,$root,'0.1.0');$slug='telegram-notifications';
if ($manager->package('plugin',$slug)) throw new RuntimeException('Use a fresh Telegram QA package identity.');
$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$count++;};
$signed=App\Core\Packages\Archive::verify($argv[1],$manager->trustedKeys());
$check($signed['slug']===$slug&&empty($signed['dependencies']),'Telegram signed package has no Calendar dependency');
$before=$db->query('SELECT type,slug,version,active FROM extension_packages ORDER BY type,slug')->fetchAll();
$stage=$manager->stageLocalFile($argv[1],1);$manager->install($stage['token'],1);
$channels=new App\Core\NotificationChannels($db,$root,$runtime->read('workspace')['secret']);
$find=static fn()=>array_values(array_filter($channels->catalog(true),static fn(array $channel):bool=>$channel['slug']==='telegram-notifications'));
$row=$find()[0];
$check(!$row['enabled']&&!$row['verified_at']&&$row['fields']===[],'Installed channel visible without exposed bot credentials or fake verification');
$channels->save($slug,['enabled'=>false]);
$check(!$find()[0]['enabled'],'Disabled channel save does not require central service');
try{$channels->save($slug,['enabled'=>true]);throw new LogicException('Consent accepted implicitly');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'Confirm'),'Channel activation requires consent');}
$manager->setActive('plugin',$slug,false,1);$check($find()===[],'Deactivated plugin hidden from delivery catalog');
$manager->setActive('plugin',$slug,true,1);$manager->uninstall('plugin',$slug,1);
$check(!is_dir($root.'/plugins/'.$slug),'Uninstall removes only the Telegram runtime');
$stage=$manager->stageLocalFile($argv[1],1);$manager->install($stage['token'],1);
$check(!$find()[0]['enabled'],'Reinstall preserves disabled settings');
$after=$db->query("SELECT type,slug,version,active FROM extension_packages WHERE slug<>'telegram-notifications' ORDER BY type,slug")->fetchAll();
$check($before===$after,'Other installed packages unchanged');
echo "$count Telegram package lifecycle checks passed.\n";
