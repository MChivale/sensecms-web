<?php
declare(strict_types=1);

// Exact signed old/new artifacts; randomly named disposable MariaDB schema only.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows' || $argc !== 4) {
    fwrite(STDERR, "Usage: php tests/release-transition.php <old-archives> <new-release-set> <independent-trust.json>\n"); exit(2);
}
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$source = dirname(__DIR__) . '/.cms/source';
$trust = json_decode((string) file_get_contents($argv[3]), true, 16, JSON_THROW_ON_ERROR);
$keys = array_map(static fn(string $key): string => base64_decode($key, true), $trust);
$sets = [];
foreach ([$argv[1], $argv[2]] as $directory) {
    $set = [];
    foreach (glob($directory . '/*.zip') as $file) {
        $manifest = App\Core\Packages\Archive::verify($file, $keys);
        if (isset($set[App\Core\Packages\Manifest::identity($manifest)])) throw new RuntimeException('Ambiguous duplicate release identity.');
        $set[App\Core\Packages\Manifest::identity($manifest)] = [$file, $manifest];
    }
    if (count($set) !== 7) throw new RuntimeException('Expected seven verified project archives per set.');
    $sets[] = $set;
}
[$old, $new] = $sets;
if (array_diff_key($old, $new) || array_diff_key($new, $old)) throw new RuntimeException('Release identities changed.');
$name = 'sensetrans_' . bin2hex(random_bytes(6));
$root = sys_get_temp_dir() . '/sense-transition-' . bin2hex(random_bytes(12));
$db = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException($label); $count++; echo "PASS $label\n";
};
$product = require $source . '/config/product.php';
$writeCore = static function (string $version) use ($root, $product): void {
    $config = array_replace($product, ['core_version'=>$version]);
    file_put_contents($root . '/config/product.php', '<?php return ' . var_export($config, true) . ';');
};
try {
    $db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE `$name`");
    foreach (explode(';', (string) file_get_contents($source . '/database/001_core.sql')) as $sql) if (trim($sql) !== '') $db->exec($sql);
    $db->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute(['Release QA','owner@example.test',password_hash(bin2hex(random_bytes(24)), PASSWORD_ARGON2ID)]);
    $db->exec("INSERT INTO roles (slug,name) VALUES ('owner','Owner'); INSERT INTO user_roles VALUES (1,1)");
    $db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,UTC_TIMESTAMP())')->execute(['001_core',hash_file('sha256', $source . '/database/001_core.sql')]);
    (new App\Installer\WorkspaceMigration($db, $source))->apply();
    mkdir($root . '/config', 0700, true); $writeCore('0.1.0');
    $manager = new App\Core\PackageManager($db, $root, '0.1.0');
    foreach ($old as [, $manifest]) $manager->trustPublisher($manifest['publisher']['key_id'], $manifest['publisher']['name'], '', $trust[$manifest['publisher']['key_id']], 1);
    $install = static function (App\Core\PackageManager $manager, array $set, array $order) use ($check): void {
        foreach ($order as $identity) {
            [$file, $manifest] = $set[$identity];
            $stage = $manager->stageLocalFile($file, 1);
            $result = $manager->install($stage['token'], 1);
            if ($manifest['type'] === 'theme') $manager->themeManager()->activate($result['directory'], $manager->trustedKeys());
            $check($result['version'] === $manifest['version'], 'Exact signed installation ' . $identity . ' ' . $result['version']);
        }
    };
    $plugins = ['plugin:google-analytics','plugin:google-calendar','plugin:microsoft-365-calendar','plugin:apple-calendar','plugin:telegram-notifications'];
    $install($manager, $old, array_merge(['addon:calendar'], $plugins, ['theme:sensecms']));
    $db->exec("INSERT INTO settings (`key`,value) VALUES ('release_test','preserve-me')");
    $db->exec("INSERT INTO calendar_categories (slug,name,color,active,created_at,updated_at) VALUES ('release-test','Preserved QA category','#145cde',1,NOW(),NOW())");
    $snapshot = static fn(): array => [$db->query('SELECT * FROM users ORDER BY id')->fetchAll(), $db->query('SELECT * FROM settings ORDER BY `key`')->fetchAll(), $db->query('SELECT * FROM calendar_categories ORDER BY id')->fetchAll(), $db->query('SELECT migration_id,up_checksum,down_checksum FROM extension_migrations WHERE rolled_back_at IS NULL ORDER BY id')->fetchAll()];
    $before = $snapshot();
    $order = array_merge($plugins, ['addon:calendar','theme:sensecms']);
    $install($manager, $new, $order);
    $writeCore($product['core_version']);
    $manager = new App\Core\PackageManager($db, $root, $product['core_version']);
    foreach ($new as $identity => [, $manifest]) {
        App\Core\Packages\Manifest::compatible($manifest, $product['core_version'], PHP_VERSION, array_map(static fn(array $entry): string => $entry[1]['version'], $new));
        $stage = $manager->stageLocalFile($new[$identity][0], 1);
        $check($stage['signature'] === 'verified' && $manager->package($manifest['type'], $manifest['slug'])['version'] === $manifest['version'], 'Runtime compatible on Core ' . $product['core_version'] . ': ' . $identity);
    }
    $check($before === $snapshot(), 'Upgrade preserves users, settings, Calendar data and migration identities');
    $check((new App\Core\PublicTheme($manager->themeManager()->activePath(), 'https://example.test'))->response('/')[0] === 200, 'Upgraded theme renders on Core 1.0');
    $blocked = false;
    try { $manager->rollback('addon', 'calendar', 1); } catch (RuntimeException) { $blocked = true; }
    $check($blocked, 'Old Core-incompatible archive cannot roll back while Core 1.0 is active');
    $writeCore('0.1.0'); $manager = new App\Core\PackageManager($db, $root, '0.1.0');
    $manager->themeManager()->rollback($keys);
    foreach (array_merge(['addon:calendar'], array_reverse($plugins)) as $identity) {
        [$type, $slug] = explode(':', $identity);
        $manager->rollback($type, $slug, 1);
        $check($manager->package($type, $slug)['version'] === $old[$identity][1]['version'], 'Coordinated rollback restores ' . $identity);
    }
    $check($before === $snapshot(), 'Coordinated rollback preserves data and migration history');
    $check($manager->themeManager()->active()['version'] === $old['theme:sensecms'][1]['version'], 'Previous signed theme restored after Core downgrade');
    // Theme archive remains staged after rollback; activate it rather than reinstalling it.
    $install($manager, $new, array_merge($plugins, ['addon:calendar']));
    foreach ($manager->themeManager()->releases() as $release) if ($release['version'] === $new['theme:sensecms'][1]['version']) $manager->themeManager()->activate($release['directory'], $keys);
    $writeCore($product['core_version']);
    $check($before === $snapshot(), 'Re-upgrade preserves all fixture data');
    echo "$count signed release-transition checks passed.\n";
} finally {
    if (!preg_match('/^sensetrans_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('Unsafe QA database cleanup.');
    $db->exec("DROP DATABASE IF EXISTS `$name`");
    if (is_dir($root)) {
        if (!preg_match('#^' . preg_quote(sys_get_temp_dir(), '#') . '/sense-transition-[a-f0-9]{24}$#D', $root)) throw new RuntimeException('Unsafe QA directory cleanup.');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($root);
    }
}
