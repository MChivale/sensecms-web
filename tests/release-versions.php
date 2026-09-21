<?php
declare(strict_types=1);
// Read-only source contract checks. Does not build installation archives or contact production.
require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$root = dirname(__DIR__); $count = 0;
$check = static function (bool $ok, string $label) use (&$count): void { if (!$ok) throw new RuntimeException($label); $count++; echo "PASS $label\n"; };
$product = require $root . '/.cms/source/config/product.php';
$check($product['core_version'] === '1.0.0', 'Canonical Core release is 1.0.0');
$check($product['license']['product_name'] === 'Sense CMS' && $product['license']['product_model'] === 'Sense CMS System' && $product['license']['product_version'] === '1.0', 'Licence identity and protocol unchanged');
$expected = ['addon:calendar'=>'1.0.0','theme:sensecms'=>'1.0.14','plugin:telegram-notifications'=>'1.0.0','plugin:google-analytics'=>'0.1.2','plugin:google-calendar'=>'0.1.1','plugin:microsoft-365-calendar'=>'0.1.1','plugin:apple-calendar'=>'0.1.1'];
foreach ($expected as $identity=>$version) {
    [$type,$slug] = explode(':',$identity); $path = $root . '/.' . $type . 's/' . $slug;
    $manifest = App\Core\Packages\Manifest::validate(json_decode((string)file_get_contents($path . '/sense-package.json'),true,16,JSON_THROW_ON_ERROR));
    $runtime = json_decode((string)file_get_contents($path . '/' . $type . '.json'),true,64,JSON_THROW_ON_ERROR);
    // Supply a real descriptor hash for the signed-manifest shape required by compatibility validation.
    $manifest['files'] = [$type . '.json'=>hash_file('sha256',$path . '/' . $type . '.json')];
    $check($manifest['version'] === $version && $runtime['version'] === $version, 'Signed/runtime versions agree: ' . $identity);
    foreach (['0.1.0','1.0.0'] as $core) {
        App\Core\Packages\Manifest::compatible($manifest,$core,PHP_VERSION,['addon:calendar'=>$core]);
        $check(true, 'Bridge compatibility ' . $core . ': ' . $identity);
    }
    $blocked = false;
    try { App\Core\Packages\Manifest::compatible($manifest,'2.0.0',PHP_VERSION,$expected); } catch (RuntimeException) { $blocked = true; }
    $check($blocked, 'Unverified Core major is rejected: ' . $identity);
    $check($runtime['release_channel'] === 'development', 'No premature Stable promotion: ' . $identity);
}
$catalog = require $root . '/.src/package-catalog.php';
$catalogExpected = $expected; $catalogExpected['theme:sensecms'] = '1.0.0'; // 1.0.14 is the product-site presentation, not yet a public distribution promotion.
foreach ($catalog as $item) if (isset($catalogExpected[$item['type'].':'.$item['slug']])) $check($item['version'] === $catalogExpected[$item['type'].':'.$item['slug']], 'Website inventory version: ' . $item['slug']);
$workspace = (string)file_get_contents($root . '/.cms/source/config/workspace.php');
$installer = (string)file_get_contents($root . '/.cms/source/app/Installer/Installer.php');
$builder = (string)file_get_contents($root . '/scripts/build-installer.php');
$template = (string)file_get_contents($root . '/scripts/web-installer.php');
$check(str_contains($workspace,"'/product.php')['core_version']") && str_contains($installer,"'/config/product.php')['core_version']"), 'Runtime and new installations use canonical Core version');
$check(str_contains($builder,'__SENSE_VERSION__') && str_contains($template,'Development installer · __SENSE_VERSION__') && !str_contains($template,'Development installer · 0.1.0'), 'Future bootstrap build receives current Core version');
echo "$count release version checks passed; no archives built.\n";
