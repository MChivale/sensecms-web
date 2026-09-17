<?php
declare(strict_types=1);

require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$mysql = ($argv[1] ?? '') === '--mysql';
if ($mysql && (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows')) throw new RuntimeException('Use isolated Linux MariaDB QA.');
$db = new PDO($mysql ? 'mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4' : 'sqlite::memory:', $mysql ? 'root' : null, $mysql ? '' : null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$schema = 'senseqa_maintenance_' . bin2hex(random_bytes(6)); $created = false; $count = 0;
$root = sys_get_temp_dir() . '/sense-maintenance-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$check = static function (bool $ok, string $label) use (&$count): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); $count++; echo 'PASS ' . $label . PHP_EOL; };
$reject = static function (callable $action, string $label) use ($check): void {
    $failed = false; try { $action(); } catch (RuntimeException) { $failed = true; }
    $check($failed, $label);
};
try {
    mkdir($root . '/config', 0700); copy(dirname(__DIR__) . '/.cms/source/config/product.php', $root . '/config/product.php');
    $service = new App\Core\SystemUpdate($db, $root, ['engine_version'=>'99.0.0']);
    $product = require $root . '/config/product.php';
    $check($service->version() === $product['core_version'], 'Version comes from canonical product metadata, not caller or legacy fallback');
    $state = $service->status();
    $check(!$state['supported'] && !$state['available'] && !$state['verified'] && $state['reason'] !== '', 'Unsupported automatic updates have an explicit truthful state');
    $check(!is_dir($root . '/storage'), 'Constructing or reading status does not create runtime files');
    mkdir($root . '/storage/system-updates', 0700, true);
    file_put_contents($root . '/storage/system-updates/job.json', '{"status":"queued","version":"9.0.0","user_id":1}');
    file_put_contents($root . '/storage/system-updates/state.json', '{"checked_at":9999999999,"worker_at":9999999999}');
    file_put_contents($root . '/storage/system-updates/catalog.json', '{"core":{"version":"9.0.0"}}');
    $inventory = static function () use ($root): array { $result=[]; foreach (glob($root . '/storage/system-updates/*') as $file) $result[basename($file)]=hash_file('sha256',$file); return $result; };
    $before = $inventory(); $state=$service->status();
    $check(!$state['available'] && !$state['checked_at'] && $state['error'] !== null && $state['job'] === [], 'Legacy timestamps, forged catalog and queued job cannot imply update readiness');
    foreach ([fn()=>$service->requestCheck(),fn()=>$service->requestInstall('9.0.0',1),fn()=>$service->run(),fn()=>$service->verifyArchive('/does-not-exist.zip',[])] as $action) {
        $failed=false; try{$action();}catch(RuntimeException $error){$failed=$error->getCode()===503;}
        $check($failed, 'Legacy operation fails closed with an unavailable status');
    }
    $check($before === $inventory() && !is_file($root . '/storage/system-updates/check.json'), 'Blocked operations preserve existing state and never queue work');
    $check(!App\Core\SystemUpdate::allowed('app/Core/Auth.php'), 'No legacy Core replacement target remains enabled');
    $updateState=$state; $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); $csrf='fixture';
    ob_start(); require dirname(__DIR__) . '/.cms/source/app/Views/console-system-update.php'; $html=ob_get_clean();
    $check(str_contains($html,'Release status needs verification') && !str_contains($html,'Your installation is up to date') && !str_contains($html,'Base CMS'), 'Server-rendered update screen does not claim success or old branding');
    $check(preg_match('/data-update-install disabled/', $html) === 1 && preg_match('/data-update-check disabled/', $html) === 1, 'Actions are disabled even without JavaScript');

    if($mysql){$db->exec("CREATE DATABASE `$schema`");$created=true;$db->exec("USE `$schema`");}
    foreach(['pages','posts'] as $table) $db->exec("CREATE TABLE $table(id INTEGER PRIMARY KEY,facility_id INTEGER,status VARCHAR(30),workflow_state VARCHAR(30))");
    foreach(['pages','posts'] as $table){$insert=$db->prepare("INSERT INTO $table VALUES(?,?,?,?)");for($i=1;$i<=300;$i++)$insert->execute([$i,1,'draft','in_review']);}
    $db->exec("INSERT INTO pages VALUES(301,2,'draft','in_review'),(302,1,'draft','approved'),(303,1,'published','approved'),(304,1,'archived','in_review'),(305,1,'draft','draft'),(306,1,'draft','changes_requested')");
    $workflow=new App\Core\WorkflowRepository($db,new App\Core\AccessControl($db,new App\Core\Auth($db)));
    $check($workflow->counts()['in_review']===601 && $workflow->counts()['all']===604, 'Totals include more than 250 pages and posts per state');
    $check($workflow->counts([1])===['all'=>603,'in_review'=>600,'changes_requested'=>1,'approved'=>1,'draft'=>1], 'Scoped totals exclude archived and already-published approved content');
    $check($workflow->counts([2])['all']===1 && $workflow->counts([])['all']===0 && $workflow->counts([999])['all']===0, 'Empty and restricted facility scopes remain isolated');
    $check($workflow->counts([1,1])===$workflow->counts([1]), 'Repeated facility IDs do not duplicate totals');

    if($mysql){
        foreach([
            'CREATE TABLE extension_packages(id INTEGER PRIMARY KEY,type VARCHAR(20),slug VARCHAR(80),version VARCHAR(20),active INTEGER,manifest TEXT,updated_at DATETIME)',
            'CREATE TABLE extension_package_releases(package_id INTEGER,restored_at DATETIME)',
            'CREATE TABLE installed_plugins(slug VARCHAR(80) PRIMARY KEY,version VARCHAR(20),active INTEGER,settings TEXT,installed_at DATETIME)',
            'CREATE TABLE settings(`key` VARCHAR(100) PRIMARY KEY,value TEXT)',
            'CREATE TABLE activity_log(user_id INTEGER,event VARCHAR(100),subject_type VARCHAR(30),subject_id INTEGER,context TEXT,created_at DATETIME)',
            "INSERT INTO extension_packages VALUES(1,'addon','calendar','0.1.0',1,'{}',NOW()),(2,'plugin','forms','0.1.0',1,'{}',NOW()),(3,'plugin','ordinary','0.1.0',1,'{}',NOW())",
            "INSERT INTO installed_plugins VALUES('forms','0.1.0',1,'{\"preserved\":true}',NOW()),('ordinary','0.1.0',1,'{}',NOW())",
            "INSERT INTO settings VALUES('extension_states','{\"calendar\":true,\"forms\":true,\"unrelated\":true}')",
        ] as $sql)$db->exec($sql);
        $packages=new App\Core\PackageManager($db,$root,'0.1.0');
        $snapshot=static function()use($db):array{$rows=[];foreach(['extension_packages','installed_plugins','settings','activity_log'] as $table)$rows[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();return$rows;};
        $before=$snapshot();
        $db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected isolated failure'");
        foreach([['addon','calendar'],['plugin','forms'],['plugin','ordinary']] as [$type,$slug]){
            $reject(fn()=>$packages->setActive($type,$slug,false,1),'Audit failure rejects activation for '.$type.':'.$slug);
            $check($snapshot()===$before && !$db->inTransaction(),'Failed toggle rolls back all registries, settings and audit');
        }
        $db->exec('DROP TRIGGER fail_audit');
        foreach([['addon','calendar'],['plugin','forms'],['plugin','ordinary']] as [$type,$slug])$packages->setActive($type,$slug,false,1);
        $flags=json_decode($db->query("SELECT value FROM settings WHERE `key`='extension_states'")->fetchColumn(),true);
        $check(!$flags['calendar'] && !$flags['forms'] && $flags['unrelated'], 'Addon and Forms flags update together without losing unrelated settings');
        $check((int)$db->query('SELECT SUM(active) FROM extension_packages')->fetchColumn()===0 && (int)$db->query('SELECT SUM(active) FROM installed_plugins')->fetchColumn()===0, 'Package and plugin activation registries agree');
        $check((int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn()===3, 'Every successful toggle has one audit record');
        $check(json_decode($db->query("SELECT settings FROM installed_plugins WHERE slug='forms'")->fetchColumn(),true)['preserved'], 'Plugin configuration survives toggling');
        $other=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.$schema,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $lock='sensecms:packages:'.substr(hash('sha256',$schema),0,32);
        $other->prepare('SELECT GET_LOCK(?,0)')->execute([$lock]);$before=$snapshot();
        $reject(fn()=>$packages->setActive('addon','calendar',true,1),'Activation shares the install/rollback/uninstall lock');
        $check($snapshot()===$before,'Concurrent activation leaves all data unchanged');
        $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        $packages->setActive('addon','calendar',true,1);
        $check($packages->package('addon','calendar')['active'],'Activation succeeds after lock release');
    }
    echo $count.' maintenance checks passed ('.($mysql?'isolated MariaDB':'SQLite memory').').'.PHP_EOL;
}finally{
    if($created && preg_match('/^senseqa_maintenance_[a-f0-9]{12}$/D',$schema))$db->exec("DROP DATABASE `$schema`");
    if(!preg_match('#/sense-maintenance-[a-f0-9]{24}$#D',str_replace('\\','/',$root)))throw new RuntimeException('Unsafe fixture cleanup');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($root);
}
