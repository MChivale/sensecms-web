<?php

declare(strict_types=1);

if ($argc < 3 || !in_array($argv[1], ['inspect', 'install'], true)) {
    throw new RuntimeException('Usage: elingo-theme-lifecycle.php <inspect|install> <bootstrap> [archive]');
}

require $argv[2];
$root = dirname($argv[2]);
$runtime = new App\Core\Runtime($root);
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$manager = new App\Core\PackageManager($db, $root, '1.0.0');
$themes = $manager->themeManager();

if ($argv[1] === 'inspect') {
    $active = $themes->active();
    echo json_encode([
        'active' => $active,
        'releases' => array_values($themes->releases()),
        'legacy' => is_file($root . '/themes/school-blue-red/theme.json'),
        'package' => $manager->package('theme', 'school-blue-red'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($argc !== 4) {
    throw new RuntimeException('Archive path is required.');
}

$owner = (int) $db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.slug='owner' ORDER BY ur.user_id LIMIT 1")->fetchColumn();
if ($owner < 1) {
    throw new RuntimeException('No owner account is available for the package audit.');
}

$staged = $manager->stageLocalFile($argv[3], $owner);
$release = $manager->install($staged['token'], $owner);
$active = $themes->activate($release['directory'], $manager->trustedKeys());
echo json_encode(['installed' => $release, 'active' => $active], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
