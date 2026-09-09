<?php

declare(strict_types=1);

// Product-site data migration only. Never included in the clean Core distribution.
if (PHP_SAPI !== 'cli' || ($argv[2] ?? '') !== '--publish') exit("Usage: php publish-product-pages.php /absolute/installation/root --publish\n");
$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root . '/bootstrap.php')) throw new RuntimeException('Choose an existing Sense installation.');
require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root);
$runtime->license()->enforce($runtime->baseUrl());
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
if (!(new App\Installer\WorkspaceMigration($db, $root))->status()['ready']) throw new RuntimeException('Apply the reviewed schema migration first.');
$manager = new App\Core\Packages\ThemeManager($runtime);
if (($manager->active()['slug'] ?? '') !== 'sensecms' || ($manager->active()['version'] ?? '') !== '0.1.6') throw new RuntimeException('Activate the reviewed Sense product theme 0.1.6 first.');
$themeRoot = $manager->activePath();
$pages = require $themeRoot . '/pages.php';
$theme = json_decode((string) file_get_contents($themeRoot . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
if ($cms->defaultLocale() !== 'en') throw new RuntimeException('This starter copy is English; review the target default language first.');
$owner = (int) $db->query("SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id=u.id INNER JOIN roles r ON r.id=ur.role_id WHERE r.slug='owner' AND u.active=1 ORDER BY u.id LIMIT 1")->fetchColumn();
if (!$owner) throw new RuntimeException('No active installation owner.');
$facilities = new App\Core\FacilityRepository($db);
$facility = $facilities->primary('en', 'en');
if (!$facility) {
    if ($facilities->adminList()) throw new RuntimeException('Select a primary facility before importing product pages.');
    $id = $facilities->save(['id'=>0,'city_slug'=>'london','facility_slug'=>'sensecms','status'=>'active','timezone'=>'Europe/London','email'=>'info@SenseCMS.com','website_url'=>$runtime->baseUrl()],
        ['en'=>['name'=>'Sense CMS','city_name'=>'London','short_description'=>'Sense CMS product website','address'=>'','seo_title'=>'Sense CMS','seo_description'=>'']], $owner);
    $facilities->setPrimary($id, $owner);
    $facility = $facilities->primary('en', 'en');
}
$catalog = App\Core\PageBuilder::catalog($theme);
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
foreach ($pages as $path => $copy) {
    if ($cms->pageAtPath($path)) { echo "Preserved existing page: $path\n"; continue; }
    $slug = $path === '/' ? 'home' : str_replace('/', '-', ltrim($path, '/'));
    $check = $db->prepare('SELECT 1 FROM page_translations WHERE facility_id=? AND locale=? AND slug=?');
    $check->execute([(int) $facility['id'], 'en', $slug]);
    if ($check->fetchColumn()) throw new RuntimeException('Existing localized content needs manual reconciliation: ' . $path);
    $raw = [];
    foreach ($copy['sections'] ?? [] as $section) {
        $uuid = bin2hex(random_bytes(16));
        $uuid = substr($uuid,0,8).'-'.substr($uuid,8,4).'-4'.substr($uuid,13,3).'-a'.substr($uuid,17,3).'-'.substr($uuid,20,12);
        $text = '<p>' . $escape($section[1]) . '</p>';
        if (isset($section[2])) $text .= '<pre><code>' . $escape($section[2]) . '</code></pre>';
        $raw[] = ['uid'=>$uuid, 'type'=>'text', 'visible'=>true, 'shared'=>[], 'localized'=>['en'=>[
            'title'=>$section[0], 'text'=>$text, 'cta_label'=>$section[3]??'', 'cta_url'=>$section[4]??'',
        ]]];
    }
    $blocks = App\Core\PageBuilder::sanitizeBlocks($raw, $catalog, ['en']);
    $input = ['id'=>0,'facility_id'=>(int)$facility['id'],'template'=>$path==='/'?'home':'default','status'=>'draft','visibility'=>'public','published_at'=>null];
    $translations = ['en'=>['title'=>$copy['title'],'slug'=>$slug,'excerpt'=>$copy['description'],'seo_title'=>$copy['title'],'seo_description'=>$copy['description']]];
    $id = $cms->savePage($input, $translations, $owner);
    $cms->saveBuilderDocument($id, 0, $owner, 'sensecms', $blocks, array_keys($catalog));
    $cms->savePage(array_replace($input, ['id'=>$id,'status'=>'published','public_path'=>$path]), $translations, $owner);
    echo "Published managed page $id: $path\n";
}
$menus = [
    'primary'=>['/platform'=>'Platform','/extensions'=>'Extensions','/docs'=>'Documentation','/contact'=>'Contact','/download'=>'Get Sense CMS'],
    'footer'=>['/platform'=>'Platform','/extensions'=>'Extensions','/download'=>'Release status'],
    'footer-connect'=>['/contact'=>'Contact','mailto:info@SenseCMS.com'=>'info@SenseCMS.com'],
];
$existing = array_column($cms->navigation(), 'location');
foreach ($menus as $location => $links) {
    if (in_array($location, $existing, true)) { echo "Preserved existing navigation: $location\n"; continue; }
    $items = [];
    foreach ($links as $url => $label) $items[] = ['target'=>'_self','visible'=>true,'translations'=>['en'=>compact('url','label')]];
    $cms->saveNavigation($location, ucfirst($location) . ' navigation', $items, $owner);
    echo "Created editable navigation: $location\n";
}
