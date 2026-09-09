<?php

declare(strict_types=1);

// Private, loopback-only QA installation; never include this script in a release.
$project = dirname(__DIR__);
if (PHP_SAPI !== 'cli' || !preg_match('#^/root/sense-workspace-test\.[A-Za-z0-9]{8}$#D', $project)) throw new RuntimeException('Use an isolated Workspace test directory.');
umask(0077);
$root = $project . '/.cms/source'; require $root . '/bootstrap.php';
$server = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (file_exists($project . '/preview-private.json')) throw new RuntimeException('Preview already provisioned; preserve its state.');
$name = 'senseqa_' . bin2hex(random_bytes(6)); $dbPassword = bin2hex(random_bytes(24)); $ownerPassword = bin2hex(random_bytes(24));
$runtime = new App\Core\Runtime($root);
if ($runtime->read('installed')) throw new RuntimeException('Never overwrite an existing installation.');
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("CREATE USER '$name'@'127.0.0.1' IDENTIFIED BY '$dbPassword'; GRANT ALL ON `$name`.* TO '$name'@'127.0.0.1'");
file_put_contents($project . '/preview-private.json', json_encode(['database'=>$name,'email'=>'owner@example.test','password'=>$ownerPassword], JSON_THROW_ON_ERROR));
$runtime->write('setup', ['base_url'=>'https://www.sensecms.com']);
$key = '';
foreach (file($project . '/license-input.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*License\s*Key\s*[:=]\s*(\S+)\s*$/i', $line, $match)) $key = $match[1];
}
$runtime->license()->install($key, $runtime->baseUrl());
unset($key, $match);
(new App\Installer\Installer($runtime))->install(['db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>$name,'db_user'=>$name,'db_password'=>$dbPassword,'admin_name'=>'Sense CMS Preview','admin_email'=>'owner@example.test','admin_password'=>$ownerPassword,'admin_confirm'=>$ownerPassword,'site_name'=>'Sense CMS QA']);
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
(new App\Installer\WorkspaceMigration($db, $root))->apply();
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus());
// Disable only in this disposable QA installation, so automation never solves CAPTCHA.
$cms->saveSetting('captcha_settings', ['enabled'=>false]);
$calendar = $project . '/.addons/calendar'; $target = $root . '/addons/calendar';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($calendar, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink()) throw new RuntimeException('Unsafe addon source.');
    $destination = $target . '/' . substr($file->getPathname(), strlen($calendar) + 1);
    if (file_exists($destination)) throw new RuntimeException('Refusing to overwrite addon.');
    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0755, true);
    if (!copy($file->getPathname(), $destination)) throw new RuntimeException('Cannot stage addon.');
}
$manifest = json_decode((string) file_get_contents($target . '/addon.json'), true, 32, JSON_THROW_ON_ERROR);
foreach ($manifest['migrations'] as $migration) {
    foreach (explode(';', (string) file_get_contents($target . '/' . $migration['up'])) as $sql) if (trim($sql) !== '') $db->exec($sql);
}
$db->prepare("INSERT INTO extension_packages (type,slug,name,version,publisher,source,signature_status,active,manifest,install_path,installed_at,updated_at) VALUES ('addon','calendar',?,?,'QUANT Software House Limited','bundled','development-source',1,?,'addons/calendar',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$manifest['name'],$manifest['version'],json_encode($manifest,JSON_THROW_ON_ERROR)]);
$runtime->write('workspace', ['enabled'=>true,'secret'=>bin2hex(random_bytes(32))]);
unlink($project . '/license-input.txt');
echo "Private Workspace preview provisioned. No production database or site changed.\n";
