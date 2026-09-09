<?php
declare(strict_types=1);

// Operator-only installation of this project's verified release set; licence keys via stdin.
if (PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows') exit(1);
try {
    $root=$argv[1]??'';$telegram=$argv[2]??'';$verify=($argv[3]??'')==='--verify-only';
    if (!in_array($root,['/home/sensecms.com/web','/root/sense-workspace-test.SC495Gg0/.cms/source'],true)||!is_file($telegram)||is_link($telegram)) throw new RuntimeException('Invalid installation target or archive.');
    require $root.'/bootstrap.php';
    $runtime=new App\Core\Runtime($root);$installed=$runtime->read('installed');$domain=$runtime->baseUrl();
    $db=App\Core\Runtime::connect($installed['database']);
    $manager=new App\Core\PackageManager($db,$root,'0.1.0');
    $trusted=array_map(static fn(string $key):string=>base64_decode($key,true),json_decode((string)file_get_contents('/root/sensecms-private/trust.json'),true,8,JSON_THROW_ON_ERROR));
    $existing=$manager->trustedKeys();
    if (isset($existing['sensecms-release'])&&!hash_equals($existing['sensecms-release'],$trusted['sensecms-release'])) throw new RuntimeException('Existing publisher key differs.');
    $cms=(require $root.'/config/product.php')['license'];
    $keys=json_decode((string)stream_get_contents(STDIN,16385),true,8,JSON_THROW_ON_ERROR);
    $distribution=new App\Core\Packages\Distribution(new App\Core\Runtime('/home/sensecms.com/web'));
    $actor=(int)$db->query('SELECT id FROM users WHERE active=1 AND is_demo=0 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$actor) throw new RuntimeException('No active installation owner.');
    $plans=[];
    foreach (['addon:calendar','plugin:google-analytics','plugin:google-calendar','plugin:microsoft-365-calendar','plugin:apple-calendar','plugin:telegram-notifications'] as $id) {
        if ($id==='plugin:telegram-notifications') {
            $entry=['type'=>'plugin','slug'=>'telegram-notifications','version'=>'0.1.0','pricing'=>'paid','license'=>['product_name'=>'Sense CMS Telegram Notifications','product_model'=>'Sense CMS Telegram Notifications Plugin']];$path=$telegram;
        } else {
            [$entry]=$distribution->archive($id);
            $path='/home/sensecms.com/web/storage/distribution/releases/'.$entry['file'];
        }
        $config=App\Core\Packages\Entitlement::licenseConfig($entry,$cms);
        try { (new App\Core\LicenseClient($config))->validate((string)($keys[$config['product_name']]??''),$domain); }
        catch (Throwable) {throw new RuntimeException('Licence verification failed for '.$config['product_name']);}
        $signed=App\Core\Packages\Archive::verify($path,$trusted);
        if (App\Core\Packages\Manifest::identity($signed)!==$id||$signed['version']!==$entry['version']) throw new RuntimeException('Archive identity mismatch.');
        $plans[]=[$entry,$path];echo "VERIFIED $id\n";
    }
    unset($keys);
    if ($verify) exit;
    if (!isset($existing['sensecms-release'])) $manager->trustPublisher('sensecms-release','QUANT Software House Limited','https://www.sensecms.com',base64_encode($trusted['sensecms-release']),$actor);
    foreach ($plans as [$entry,$path]) {
        $current=$manager->package($entry['type'],$entry['slug']);
        if ($current && version_compare($current['version'],$entry['version'],'>=')) {echo 'PRESERVED '.$entry['slug']."\n";continue;}
        $stage=$manager->stageLocalFile($path,$actor);$result=$manager->install($stage['token'],$actor);
        echo 'INSTALLED '.$result['type'].':'.$result['slug'].' '.$result['version']."\n";
    }
    // Recover navigation omitted by the earlier signed-manifest projection.
    foreach ($plans as [$entry,$path]) {
        if ($entry['type']!=='addon') continue;
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::RDONLY)!==true)throw new RuntimeException();
        try {$manifest=json_decode((string)$zip->getFromName('payload/addon.json'),true,32,JSON_THROW_ON_ERROR);}finally{$zip->close();}
        if (empty($manifest['navigation'])) continue;
        $query=$db->prepare('SELECT manifest FROM extension_packages WHERE type=? AND slug=? AND version=?');$query->execute([$entry['type'],$entry['slug'],$entry['version']]);$prior=$query->fetchColumn();
        if (!is_string($prior)) throw new RuntimeException();
        $current=json_decode($prior,true,32,JSON_THROW_ON_ERROR);if(isset($current['navigation']))continue;
        $current['navigation']=$manifest['navigation'];
        $db->prepare('UPDATE extension_packages SET manifest=? WHERE type=? AND slug=? AND version=? AND manifest=?')->execute([json_encode($current,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$entry['type'],$entry['slug'],$entry['version'],$prior]);
        echo 'RESTORED NAVIGATION '.$entry['slug']."\n";
    }
    echo "Packages installed. External delivery settings were not enabled or overwritten.\n";
} catch (Throwable $error) {
    // Only this script's allowlisted diagnostics may reach the operator; never provider bodies.
    $message=$error->getMessage();
    fwrite(STDERR,str_starts_with($message,'Licence verification failed for ')?$message."\n":'Package installation failed at '.basename($error->getFile()).':'.$error->getLine().' ('.$error::class."). Inspect target state before retrying.\n");exit(1);
}
