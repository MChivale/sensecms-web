<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || PHP_OS_FAMILY==='Windows' || !in_array($argc,[2,3],true)) exit("Private Linux QA: php google-calendar-package.php <signed-archive>\n");
$slug=match($argv[2]??''){''=>'google-calendar','--microsoft'=>'microsoft-365-calendar','--apple'=>'apple-calendar',default=>throw new RuntimeException('Unknown provider flag.')};
$root='/root/sense-workspace-test.SC495Gg0/.cms/source';
require $root.'/bootstrap.php';
require $root.'/addons/calendar/src/CalendarIntegrationManager.php';
$r=new App\Core\Runtime($root);
$db=App\Core\Runtime::connect($r->read('installed')['database']);
$manager=new App\Core\PackageManager($db,$root,'0.1.0');
if($manager->package('plugin',$slug)||$db->query("SELECT 1 FROM calendar_integration_settings WHERE plugin_slug='$slug'")->fetchColumn()) throw new RuntimeException('QA acceptance requires a fresh calendar integration identity.');
$count=0;
$check=static function(bool $ok,string $name)use(&$count):void{if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";};
$others=fn()=>$db->query("SELECT type,slug,version,active FROM extension_packages WHERE NOT(type='plugin' AND slug='$slug') ORDER BY type,slug")->fetchAll();
$before=$others();
$keys=array_map(fn($key)=>base64_decode($key,true),json_decode(file_get_contents('/root/sensecms-private/trust.json'),true));
$manifest=App\Core\Packages\Archive::verify($argv[1],$keys);
$check($manifest['slug']===$slug&&$manifest['version']==='0.1.0','signed identity verified');
try{App\Core\Packages\Manifest::compatible($manifest,'0.1.0',PHP_VERSION);throw new LogicException('Missing dependency accepted');}
catch(RuntimeException $error){$check(str_contains($error->getMessage(),'addon:calendar'),'missing Calendar dependency rejected');}
$stage=$manager->stageLocalFile($argv[1],1);$installed=$manager->install($stage['token'],1);
$check($installed['version']==='0.1.0','signed package installs beside Calendar');
$secret=$r->read('workspace')['secret'];
$integrations=new SenseCMS\Calendar\CalendarIntegrationManager($db,$root,$secret);
$find=static fn(array $rows)=>array_values(array_filter($rows,fn($row)=>$row['slug']===$slug));
$row=$find($integrations->catalog(true))[0];
$check(!$row['enabled']&&$row['verified_at']===null,'new integration disabled and unverified');
$check(count($row['fields'])===($slug==='apple-calendar'?3:($slug==='google-calendar'?4:5)),'existing Calendar panel exposes declared configuration fields');
$testValues=$slug==='google-calendar'?['client_id'=>'test-client','client_secret'=>'test-secret','refresh_token'=>'test-refresh','calendar_id'=>'qa@example.test']:['tenant_id'=>'11111111-2222-3333-4444-555555555555','client_id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee','client_secret'=>'test-secret','user_id'=>'qa@example.test','calendar_id'=>''];
if($slug==='apple-calendar')$testValues=['calendar_url'=>'https://p123-caldav.icloud.com/123456/calendars/home/','apple_id'=>'qa@example.test','app_password'=>'test-secret'];
$saved=$integrations->save($slug,['enabled'=>false]+$testValues);
$check(!$saved['enabled']&&$saved['verified_at']===null,'disabled settings save without provider contact');
$encrypted=$db->query("SELECT encrypted_settings FROM calendar_integration_settings WHERE plugin_slug='$slug'")->fetchColumn();
$check(!str_contains($encrypted,'test-secret')&&!str_contains($encrypted,'test-refresh'),'credentials encrypted at rest');
$fields=array_column($find($integrations->catalog(true))[0]['fields'],null,'name');
$secretField=$slug==='apple-calendar'?'app_password':'client_secret';
$check($fields[$secretField]['value']===''&&$fields[$secretField]['configured']&&(!isset($fields['refresh_token'])||$fields['refresh_token']['value']===''),'API never returns stored secrets');
try{$manager->uninstall('addon','calendar',1);throw new LogicException('Dependency removed');}
catch(RuntimeException $error){$check(str_contains($error->getMessage(),'depends')||str_contains($error->getMessage(),'Bundled'),'existing Calendar is protected from uninstall');}
$manager->setActive('plugin',$slug,false,1);
$check(!$find($integrations->catalog()),'disabled plugin not offered for delivery');
$manager->setActive('plugin',$slug,true,1);
$manager->uninstall('plugin',$slug,1);
$check(!is_dir($root.'/plugins/'.$slug),'uninstall removes own runtime');
$stage=$manager->stageLocalFile($argv[1],1);$manager->install($stage['token'],1);
$check($encrypted===$db->query("SELECT encrypted_settings FROM calendar_integration_settings WHERE plugin_slug='$slug'")->fetchColumn(),'reinstall preserves stored integration data');
$check($before===$others(),'unrelated packages unchanged');
// Remove only fabricated test values using the real settings API, not a table purge.
$db->prepare("UPDATE calendar_integration_settings SET encrypted_settings=? WHERE plugin_slug=? AND encrypted_settings=? AND enabled=0")->execute([(new App\Core\Secrets($secret))->encrypt('{}'),$slug,$encrypted]);
$check(!$find($integrations->catalog())[0]['last_error'],'clean unconfigured QA state remains decryptable');
echo "$count package checks passed; live provider delivery and paid licence remain unverified.\n";
