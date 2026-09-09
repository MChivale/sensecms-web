<?php
declare(strict_types=1);

// Verify the actual signed release ZIPs, not regenerated substitutes. Linux private QA only.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows' || $argc !== 3) {
    fwrite(STDERR, "Usage on private MariaDB host: php tests/package-release.php <release-directory> <independently-trusted-public-keys.json>\n");
    exit(2);
}
set_error_handler(static function (int $level, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $level, $file, $line); });
$root = dirname(__DIR__) . '/.cms/source';
require $root . '/bootstrap.php';
$release = realpath($argv[1]);
$encoded = json_decode((string) file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
$keys = array_map(static fn(string $value): string => base64_decode($value, true), $encoded);
$raw = (string) file_get_contents($release . '/release-set.json');
$sig = base64_decode(trim((string) file_get_contents($release . '/release-set.sig')), true);
$trusted = false;
foreach ($keys as $key) if (strlen($key) === 32 && is_string($sig) && strlen($sig) === 64 && sodium_crypto_sign_verify_detached($sig, $raw, $key)) $trusted = true;
if (!$trusted) throw new RuntimeException('Release inventory signature is not trusted.');
$inventory = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
$count = 0;
$assert = static function (bool $ok, string $name) use (&$count): void {
    if (!$ok) throw new RuntimeException($name);
    $count++; echo "PASS $name\n";
};
$archives = [];
foreach ($inventory['products'] as $entry) {
    App\Core\Packages\Manifest::path($entry['file']);
    if (basename($entry['file']) !== $entry['file']) throw new RuntimeException('Archive must be in the release directory.');
    $path = $release . '/' . $entry['file'];
    $assert(hash_file('sha256', $path) === $entry['sha256'] && filesize($path) === $entry['bytes'], 'Exact published bytes: ' . $entry['identity']);
    $manifest = App\Core\Packages\Archive::verify($path, $keys);
    $assert(App\Core\Packages\Manifest::identity($manifest) === $entry['identity'] && $manifest['version'] === $entry['version'], 'Signed identity: ' . $entry['identity']);
    $archives[$entry['identity']] = [$path, $manifest];
}
$assert(count($archives) === 2 && isset($archives['addon:calendar'], $archives['theme:sensecms']), 'Both real project packages included');
$dbName = 'sensepkg_' . bin2hex(random_bytes(6));
$testRoot = sys_get_temp_dir() . '/sense-release-' . bin2hex(random_bytes(12));
$db = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
try {
    $db->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE `$dbName`");
    foreach (explode(';', (string) file_get_contents($root . '/database/001_core.sql')) as $sql) if (trim($sql) !== '') $db->exec($sql);
    $db->prepare('INSERT INTO users (name,email,password,created_at) VALUES (?,?,?,UTC_TIMESTAMP())')->execute(['Release QA','owner@example.test',password_hash(bin2hex(random_bytes(24)), PASSWORD_ARGON2ID)]);
    $db->exec("INSERT INTO roles (slug,name) VALUES ('owner','Owner'); INSERT INTO user_roles VALUES (1,1)");
    $db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,UTC_TIMESTAMP())')->execute(['001_core',hash_file('sha256', $root . '/database/001_core.sql')]);
    (new App\Installer\WorkspaceMigration($db, $root))->apply();
    mkdir($testRoot . '/config', 0700, true);
    copy($root . '/config/product.php', $testRoot . '/config/product.php');
    $manager = new App\Core\PackageManager($db, $testRoot, '0.1.0');
    foreach ($archives as [$path, $manifest]) {
        $id = $manifest['publisher']['key_id'];
        $manager->trustPublisher($id, $manifest['publisher']['name'], '', $encoded[$id], 1);
        $stage = $manager->stageLocalFile($path, 1);
        $assert($stage['signature'] === 'verified', 'Panel staging: ' . $manifest['slug']);
        $installed = $manager->install($stage['token'], 1);
        $assert($installed['version'] === $manifest['version'], 'Clean installation: ' . $manifest['slug']);
    }
    $assert($db->query("SHOW TABLES LIKE 'calendar_categories'")->fetchColumn() === 'calendar_categories', 'Calendar schema installed by package');
    $assert(($manager->package('addon', 'calendar')['manifest']['navigation']['url'] ?? '') === '/calendar', 'Signed Calendar navigation survives installation');
    require $testRoot . '/addons/calendar/src/CalendarConflictException.php';
    require $testRoot . '/addons/calendar/src/CalendarRepository.php';
    $calendar = new SenseCMS\Calendar\CalendarRepository($db, 1, true);
    $category = $calendar->saveCategory(['slug'=>'release-check','name'=>'Release check','color'=>'#145cde','active'=>true], 1);
    $assert($category['slug'] === 'release-check', 'Installed calendar creates custom category');
    $before = $db->query('SELECT * FROM calendar_categories ORDER BY id')->fetchAll();
    $manager->uninstall('addon', 'calendar', 1);
    $assert(!is_dir($testRoot . '/addons/calendar') && $before === $db->query('SELECT * FROM calendar_categories ORDER BY id')->fetchAll(), 'Uninstall preserves category data');
    $stage = $manager->stageLocalFile($archives['addon:calendar'][0], 1);
    $manager->install($stage['token'], 1);
    $assert($before === $db->query('SELECT * FROM calendar_categories ORDER BY id')->fetchAll(), 'Reinstall preserves category identities');
    $themes = $manager->themeManager();
    $assert($themes->active() === null, 'Theme upload does not silently switch the site');
    $theme = array_values($themes->releases())[0];
    $themes->activate($theme['directory'], $keys);
    $assert($themes->active()['version'] === $archives['theme:sensecms'][1]['version'], 'Exact signed theme activates');
    $renderer = new App\Core\PublicTheme($themes->activePath(), 'https://example.test');
    foreach (['/', '/platform', '/docs', '/contact', '/theme-assets/product.css'] as $route) {
        $assert($renderer->response($route)[0] === 200, 'Installed theme renders ' . $route);
    }
    echo "Completed $count signed release acceptance checks.\n";
} finally {
    if (!preg_match('/^sensepkg_[a-f0-9]{12}$/D', $dbName)) throw new RuntimeException('Unsafe test database cleanup.');
    $db->exec("DROP DATABASE IF EXISTS `$dbName`");
    if (is_dir($testRoot)) {
        if (!preg_match('#^' . preg_quote(sys_get_temp_dir(), '#') . '/sense-release-[a-f0-9]{24}$#D', $testRoot)) throw new RuntimeException('Unsafe test directory cleanup.');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($testRoot);
    }
}
