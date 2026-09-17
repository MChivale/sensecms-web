<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows'||$argc!==3){fwrite(STDERR,"Usage on private MariaDB host: php tests/social-package-release.php <release-directory> <trusted-public-keys.json>\n");exit(2);}
set_error_handler(static function(int$level,string$message,string$file,int$line):never{throw new ErrorException($message,0,$level,$file,$line);});
$core=dirname(__DIR__).'/.cms/source';require$core.'/bootstrap.php';$release=realpath($argv[1]);$encoded=json_decode((string)file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR);$keys=array_map(static fn(string$value):string=>base64_decode($value,true),$encoded);$count=0;
$check=static function(bool$ok,string$label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo"PASS $label\n";};
$archives=[];foreach(['addon-social-publishing-0.2.0.zip','plugin-facebook-publisher-0.2.0.zip']as$file){$path=$release.'/'.$file;$manifest=App\Core\Packages\Archive::verify($path,$keys);$identity=App\Core\Packages\Manifest::identity($manifest);$check(hash_equals(hash_file('sha256',$path),json_decode((string)file_get_contents($release.'/social-release.json'),true,16,JSON_THROW_ON_ERROR)[$identity]),'Exact signed archive '.$identity);$archives[$identity]=[$path,$manifest];}
$check(array_keys($archives)===['addon:social-publishing','plugin:facebook-publisher'],'Dependency-ordered social release set');
$dbName='sensesocial_'.bin2hex(random_bytes(6));$testRoot=sys_get_temp_dir().'/sense-social-'.bin2hex(random_bytes(12));$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try{
    $db->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$dbName`");foreach(explode(';',(string)file_get_contents($core.'/database/001_core.sql'))as$sql)if(trim($sql)!=='')$db->exec($sql);
    $db->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute(['Social release QA','owner@example.test',password_hash(bin2hex(random_bytes(24)),PASSWORD_ARGON2ID)]);$db->exec("INSERT INTO roles (slug,name) VALUES ('owner','Owner'); INSERT INTO user_roles VALUES (1,1)");$db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,UTC_TIMESTAMP())')->execute(['001_core',hash_file('sha256',$core.'/database/001_core.sql')]);(new App\Installer\WorkspaceMigration($db,$core))->apply();
    foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\R|$)/',(string)file_get_contents(dirname(__DIR__).'/.addons/social-publishing/migrations/up.sql'))?:[]))as$sql)$db->exec($sql);
    $db->exec("INSERT INTO social_connections (plugin_slug,external_account_id,display_name,encrypted_credentials,enabled,last_verified_at,created_at,updated_at) VALUES ('facebook-publisher','1070508802821329','Cambo Jumbo','encrypted-fixture',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->exec("INSERT INTO social_post_targets (post_id,plugin_slug,enabled,message,revision,last_enqueued_revision,updated_at) VALUES (77,'facebook-publisher',1,'Existing target',2,1,UTC_TIMESTAMP())");
    $db->exec("INSERT INTO social_deliveries (post_id,plugin_slug,target_revision,payload,payload_hash,status,attempts,available_at,created_at,updated_at) VALUES (77,'facebook-publisher',1,'{}',REPEAT('a',64),'published',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    mkdir($testRoot.'/config',0700,true);copy($core.'/config/product.php',$testRoot.'/config/product.php');$manager=new App\Core\PackageManager($db,$testRoot,'1.0.0');
    foreach($archives as[$path,$manifest]){$id=$manifest['publisher']['key_id'];$manager->trustPublisher($id,$manifest['publisher']['name'],'',$encoded[$id],1);$stage=$manager->stageLocalFile($path,1);$check($stage['signature']==='verified','Verified package staging '.$manifest['slug']);$installed=$manager->install($stage['token'],1);$check($installed['version']===$manifest['version'],'Installed social package '.$manifest['slug']);}
    foreach(['social_connections','social_post_targets','social_deliveries']as$table)$check($db->query("SHOW TABLES LIKE '$table'")->fetchColumn()===$table,'Installed schema '.$table);
    $legacy=$db->query("SELECT c.id,t.connection_id,d.connection_id delivery_connection,d.destination_external_id,d.destination_display_name FROM social_connections c INNER JOIN social_post_targets t ON t.plugin_slug=c.plugin_slug INNER JOIN social_deliveries d ON d.plugin_slug=c.plugin_slug WHERE c.external_account_id='1070508802821329'")->fetch();
    $check((int)$legacy['id']>0&&(int)$legacy['connection_id']===(int)$legacy['id']&&(int)$legacy['delivery_connection']===(int)$legacy['id'],'Existing Facebook connection, target and delivery migrate together');
    $check($legacy['destination_external_id']==='1070508802821329'&&$legacy['destination_display_name']==='Cambo Jumbo','Migrated delivery retains its destination snapshot');
    $db->exec("INSERT INTO social_connections (plugin_slug,external_account_id,display_name,encrypted_credentials,enabled,last_verified_at,created_at,updated_at) VALUES ('facebook-publisher','123456789','Second Page','encrypted-fixture-2',1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $check((int)$db->query("SELECT COUNT(*) FROM social_connections WHERE plugin_slug='facebook-publisher'")->fetchColumn()===2,'Provider accepts multiple independent Facebook Pages');
    $addon=$manager->package('addon','social-publishing');$plugin=$manager->package('plugin','facebook-publisher');
    $addonRuntime=json_decode((string)file_get_contents($testRoot.'/addons/social-publishing/addon.json'),true,32,JSON_THROW_ON_ERROR);$pluginRuntime=json_decode((string)file_get_contents($testRoot.'/plugins/facebook-publisher/plugin.json'),true,32,JSON_THROW_ON_ERROR);
    $check(($addon['manifest']['navigation']['url']??'')==='/social-publishing'&&($addonRuntime['runtime']??'')==='runtime.php','Signed addon runtime and navigation retained');
    $check(($pluginRuntime['social_publisher']['platform']??'')==='facebook'&&($pluginRuntime['runtime']??'')==='runtime.php','Signed Facebook provider contract retained');
    $failed=false;try{$manager->uninstall('addon','social-publishing',1);}catch(RuntimeException){$failed=true;}$check($failed,'Active dependent plugin blocks addon removal');
    $manager->uninstall('plugin','facebook-publisher',1);$manager->uninstall('addon','social-publishing',1);$check(!is_dir($testRoot.'/plugins/facebook-publisher')&&!is_dir($testRoot.'/addons/social-publishing'),'Package files removed in dependency order');
    $check($db->query("SHOW TABLES LIKE 'social_connections'")->fetchColumn()==='social_connections','Uninstall preserves social data tables');
    foreach($archives as[$path]){$stage=$manager->stageLocalFile($path,1);$manager->install($stage['token'],1);}$check($manager->package('plugin','facebook-publisher')['active']&&$manager->package('addon','social-publishing')['active'],'Social packages reinstall active with retained schema');
    echo"$count signed social package checks passed.\n";
}finally{
    if(!preg_match('/^sensesocial_[a-f0-9]{12}$/D',$dbName))throw new RuntimeException('Unsafe QA database cleanup.');$db->exec("DROP DATABASE IF EXISTS `$dbName`");
    if(is_dir($testRoot)){if(!preg_match('#^'.preg_quote(sys_get_temp_dir(),'#').'/sense-social-[a-f0-9]{24}$#D',$testRoot))throw new RuntimeException('Unsafe QA directory cleanup.');foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file)$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($testRoot);}
}
