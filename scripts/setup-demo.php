<?php

declare(strict_types=1);

use App\Core\CmsRepository;
use App\Core\EventBus;
use App\Core\FacilityRepository;
use App\Core\PageBuilder;
use App\Core\Runtime;

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '/home/demo.sensecms.com/web') {
    throw new RuntimeException('This one-time setup is restricted to the demo installation.');
}
$root = $argv[1];
require $root . '/bootstrap.php';
$runtime = new Runtime($root);
$installed = $runtime->read('installed');
if ($runtime->baseUrl() !== 'https://demo.sensecms.com' || ($installed['database']['name'] ?? '') !== 'sensecms_demo') {
    throw new RuntimeException('Demo identity does not match.');
}
$db = Runtime::connect($installed['database']);
foreach (['facilities', 'pages', 'posts', 'categories', 'menus'] as $table) {
    if ((int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
        throw new RuntimeException('Demo content already exists; no seed was applied.');
    }
}
$owner = (int) $db->query('SELECT id FROM users WHERE is_demo=0 AND active=1 ORDER BY id LIMIT 1')->fetchColumn();
if ($owner < 1) throw new RuntimeException('An active Owner account is required.');
$manager = new App\Core\Packages\ThemeManager($runtime);
$theme = $manager->active();
if ($theme === null) {
    $archive = (string) ($argv[2] ?? '');
    if ($archive === '') throw new RuntimeException('Provide the signed demo theme archive.');
    $product = require $root . '/config/product.php';
    $keys = (new App\Core\PackageManager($db, $root, $product['core_version']))->trustedKeys();
    $theme = $manager->install($archive, $keys);
}
if (($theme['slug'] ?? '') !== 'sense-demo' || ($theme['version'] ?? '') !== '1.0.1') {
    throw new RuntimeException('The active theme is not the reviewed demo theme.');
}

$facilities = new FacilityRepository($db);
$cms = new CmsRepository($db, new EventBus(), $runtime);
$facility = [
    'id' => 0, 'city_slug' => 'example', 'facility_slug' => 'sense-demo', 'status' => 'active',
    'timezone' => 'UTC', 'search_enabled' => false, 'homepage_page_id' => 0,
];
$facilityTranslations = [
    'en' => ['name' => 'Sense CMS Demo', 'city_name' => 'Example', 'short_description' => 'Example workspace for exploring Sense CMS.', 'address' => '', 'seo_title' => 'Sense CMS Demo', 'seo_description' => 'A sample workspace for exploring Sense CMS.'],
    'pl' => ['name' => 'Demo Sense CMS', 'city_name' => 'Przykład', 'short_description' => 'Przykładowa przestrzeń do poznawania Sense CMS.', 'address' => '', 'seo_title' => 'Demo Sense CMS', 'seo_description' => 'Przykładowa przestrzeń do poznawania Sense CMS.'],
];
$facilityId = $facilities->save($facility, $facilityTranslations, $owner);
$facilities->setPrimary($facilityId, $owner);

$pages = [
    ['/', 'home', [
        'en' => ['See Sense CMS in action.', 'home', 'An example website in a separate Sense CMS installation. Explore the pages, then take a look at the administration workspace.'],
        'pl' => ['Zobacz Sense CMS w działaniu.', 'home', 'Przykładowa witryna w osobnej instalacji Sense CMS. Przeglądaj strony, a następnie poznaj panel administracyjny.'],
    ], []],
    ['/explore', 'default', [
        'en' => ['Explore the workspace', 'explore', 'A practical look at pages, languages, publishing and independent themes.'],
        'pl' => ['Poznaj możliwości', 'explore', 'Praktyczne spojrzenie na strony, języki, publikację i niezależne motywy.'],
    ], [
        [['Pages that belong to your workspace', 'A page is saved in the CMS with its own status, language versions and public address. Editors can prepare a draft and publish it when ready.'], ['Strony zapisane w przestrzeni', 'Strona jest zapisana w CMS ze swoim statusem, wersjami językowymi i adresem publicznym. Redaktor może przygotować szkic i opublikować go po sprawdzeniu.']],
        [['A separate presentation layer', 'This example uses a signed theme package. The administration workspace and the content records remain separate from its visual design.'], ['Osobna warstwa wizualna', 'Ten przykład używa podpisanego pakietu motywu. Panel administracyjny i zapisane treści pozostają niezależne od wyglądu witryny.']],
    ]],
    ['/about', 'default', [
        'en' => ['About this demo', 'about', 'A safe place to explore the CMS without changing its content or configuration.'],
        'pl' => ['O tym demo', 'about', 'Miejsce do poznania CMS bez zmiany jego treści i konfiguracji.'],
    ], [
        [['What you are viewing', 'This is a dedicated Sense CMS installation with example content, a separate database and its own public theme. The example does not represent a real organisation.'], ['Co oglądasz', 'To osobna instalacja Sense CMS z przykładową treścią, oddzielną bazą danych i własnym motywem publicznym. Przykład nie przedstawia rzeczywistej organizacji.']],
        [['The Demo account', 'The Demo account can inspect the administration workspace. Server-side controls reject its changes, so everyone sees the same example.'], ['Konto Demo', 'Konto Demo pozwala oglądać panel administracyjny. Zabezpieczenia po stronie serwera odrzucają jego zmiany, dzięki czemu wszyscy widzą ten sam przykład.']],
    ]],
];
$descriptor = json_decode((string) file_get_contents((new App\Core\Packages\ThemeManager($runtime))->activePath() . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
$catalog = PageBuilder::catalog($descriptor);
foreach ($pages as [$path, $template, $copy, $sections]) {
    $translations = [];
    foreach ($copy as $locale => [$title, $slug, $excerpt]) {
        $translations[$locale] = compact('title', 'slug', 'excerpt') + ['seo_title' => $title . ' · Sense CMS Demo', 'seo_description' => $excerpt];
    }
    $pageId = $cms->savePage([
        'id' => 0, 'facility_id' => $facilityId, 'parent_id' => 0, 'public_path' => $path,
        'template' => $template, 'status' => 'published', 'visibility' => 'public', 'published_at' => null,
    ], $translations, $owner);
    if ($template === 'home') {
        $facility['id'] = $facilityId;
        $facility['homepage_page_id'] = $pageId;
        $facilities->save($facility, $facilityTranslations, $owner);
    }
    if ($sections) {
        $blocks = [];
        foreach ($sections as $pair) {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
            $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
            $hex = bin2hex($bytes);
            $uid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
            $blocks[] = ['uid' => $uid, 'type' => 'text', 'visible' => true, 'shared' => [], 'localized' => [
                'en' => ['eyebrow' => 'SENSE CMS', 'title' => $pair[0][0], 'text' => $pair[0][1]],
                'pl' => ['eyebrow' => 'SENSE CMS', 'title' => $pair[1][0], 'text' => $pair[1][1]],
            ]];
        }
        $cms->saveBuilderDocument($pageId, 0, $owner, 'sense-demo', PageBuilder::sanitizeBlocks($blocks, $catalog, ['en', 'pl']), ['text']);
    }
}
$category = $cms->saveCategory(0, 'demo-updates', [
    'en' => ['name' => 'Demo updates', 'description' => 'Example posts for the demonstration.'],
    'pl' => ['name' => 'Aktualności demo', 'description' => 'Przykładowe wpisy na potrzeby demonstracji.'],
], $owner);
$cms->savePost([
    'id' => 0, 'author_id' => $owner, 'facility_id' => $facilityId, 'category_id' => $category,
    'featured_media_id' => 0, 'audio_media_id' => 0, 'video_media_id' => 0,
    'status' => 'published', 'published_at' => null, 'tags' => [],
], [
    'en' => ['title' => 'Welcome to the Sense CMS demo', 'slug' => 'welcome-to-the-demo', 'excerpt' => 'A sample post showing multilingual publishing.', 'content' => '<p>This example post is stored in the CMS. It demonstrates how a published update appears on the website.</p>', 'seo_title' => 'Welcome to the Sense CMS demo', 'seo_description' => 'A sample post showing multilingual publishing.'],
    'pl' => ['title' => 'Witamy w demo Sense CMS', 'slug' => 'witamy-w-demo', 'excerpt' => 'Przykładowy wpis pokazujący publikację w dwóch językach.', 'content' => '<p>Ten przykładowy wpis jest zapisany w CMS. Pokazuje, jak opublikowana aktualność wygląda w witrynie.</p>', 'seo_title' => 'Witamy w demo Sense CMS', 'seo_description' => 'Przykładowy wpis pokazujący publikację w dwóch językach.'],
], $owner);
echo "Demo content created: 1 facility, 3 pages, 1 category, 1 post.\n";
