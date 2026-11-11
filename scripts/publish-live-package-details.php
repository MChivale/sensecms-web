<?php
declare(strict_types=1);

// Operator-only reconciliation of official package detail copy with the signed
// Stable inventory. It keeps page identity/translations and records each former
// Builder document for a targeted rollback.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply', '--rollback'], true)) exit("Usage: php publish-live-package-details.php <installation-root> --apply|--rollback <private-backup>\n");
umask(0077);
$root = realpath($argv[1]); $backup = realpath($argv[3]);
if (!$root || !$backup || !str_starts_with($backup, '/root/')) throw new RuntimeException('Private existing operator paths required.');
require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root); $runtime->license()->enforce($runtime->baseUrl());
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
$owner = (int) $db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
$themeRoot = (new App\Core\Packages\ThemeManager($runtime))->activePath();
if (!$owner || !$themeRoot) throw new RuntimeException('Expected active owner and product theme.');
$catalog = App\Core\PageBuilder::catalog(json_decode((string) file_get_contents($themeRoot . '/theme.json'), true, 64, JSON_THROW_ON_ERROR));
$locales = array_column($cms->languages(), 'locale');
if (!$locales || !in_array('en', $locales, true)) throw new RuntimeException('Expected English to be active.');
$journal = $backup . '/live-package-details.json';
$save = static function (array $document) use ($cms, $catalog, $owner): void {
    $current = $cms->builderDocument((int) $document['id']);
    if (!$current) throw new RuntimeException('A package page disappeared during publication.');
    $cms->saveBuilderDocument((int) $document['id'], (int) $current['builder_version'], $owner, 'sensecms', App\Core\PageBuilder::sanitizeBlocks($document['blocks'], $catalog, array_keys($document['translations'])), array_keys($catalog));
};
if ($argv[2] === '--rollback') {
    $state = json_decode((string) file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
    foreach ($state['documents'] ?? [] as $document) $save($document);
    foreach ($state['pages'] ?? [] as $page) $cms->savePage($page, $page['translations'], $owner);
    foreach ($state['seo'] ?? [] as $key => $setting) {
        $db->prepare('DELETE FROM settings WHERE `key`=?')->execute([$key]);
        if (($setting['exists'] ?? false) === true) $db->prepare('INSERT INTO settings (`key`,value) VALUES (?,?)')->execute([$key, $setting['raw']]);
    }
    echo "Restored official package detail documents and metadata.\n"; exit;
}
if (file_exists($journal)) throw new RuntimeException('Existing detail-publication journal; inspect or roll it back first.');
$products = require dirname(__DIR__) . '/.src/package-catalog.php';
$distribution = new App\Core\Packages\Distribution($runtime);
$documents = []; $pages = []; $seo = [];
foreach ($products as $product) {
    $identity = $product['type'] . ':' . $product['slug']; $offer = $distribution->entry($identity);
    if (($offer['channel'] ?? '') !== 'stable' || ($offer['pricing'] ?? '') !== 'free' || ($offer['version'] ?? '') !== ($product['version'] ?? '')) throw new RuntimeException('Package detail does not match signed distribution: ' . $identity);
    $page = $cms->pageAtPath('/extensions/catalog/' . $product['type'] . '/' . $product['slug']);
    if (!$page) throw new RuntimeException('Missing public package detail page: ' . $identity);
    $documents[$identity] = $cms->builderDocument((int) $page['id']);
    $pages[$identity] = $cms->pageAdmin((int) $page['id']);
    foreach ($pages[$identity]['translations'] as $locale => $_) {
        $key = 'seo_page_' . $page['id'] . '_' . $locale;
        $statement = $db->prepare('SELECT value FROM settings WHERE `key`=?'); $statement->execute([$key]); $raw = $statement->fetchColumn();
        $seo[$key] = ['exists'=>$raw !== false, 'raw'=>$raw === false ? null : (string) $raw];
    }
}
file_put_contents($journal, json_encode(['documents'=>$documents, 'pages'=>$pages, 'seo'=>$seo], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
$uuid = static function (): string { $raw = bin2hex(random_bytes(16)); return substr($raw,0,8).'-'.substr($raw,8,4).'-4'.substr($raw,13,3).'-a'.substr($raw,17,3).'-'.substr($raw,20); };
foreach ($products as $product) {
    $identity = $product['type'] . ':' . $product['slug']; $document = $documents[$identity];
    $requires = trim((string) ($product['requires'] ?? 'Sense CMS Core 1.0 or newer'));
    $sections = [
        ['Available now', '<p>Signed Stable package available through the Sense CMS Marketplace.</p><p>Version ' . htmlspecialchars((string) $product['version'], ENT_QUOTES, 'UTF-8') . ' is covered by a valid Sense CMS Core licence for the installation domain.</p>', 'Open Marketplace →', '/marketplace'],
        ['Compatibility', '<p>Requires ' . htmlspecialchars($requires, ENT_QUOTES, 'UTF-8') . '.</p><p>Before installation, Sense CMS verifies the publisher signature, archive checksum, compatibility and dependencies.</p>'],
        ['Installation', '<p>Open Marketplace in your Sense CMS administration, review the verified package, then install it through the protected package lifecycle.</p>'],
        ['Explore the ecosystem', '<p>Compare signed themes, add-ons and plugins for your Sense CMS installation.</p>', 'All packages →', '/extensions/catalog'],
    ];
    $blocks = [];
    foreach ($sections as $section) {
        $localized = [];
        foreach ($locales as $locale) $localized[$locale] = ['title'=>$section[0], 'text'=>$section[1], 'cta_label'=>$section[2] ?? '', 'cta_url'=>$section[3] ?? ''];
        $blocks[] = ['uid'=>$uuid(), 'type'=>'text', 'visible'=>true, 'shared'=>[], 'localized'=>$localized];
    }
    $document['blocks'] = $blocks; $save($document);
    $page = $pages[$identity];
    $description = (string) $product['description'];
    foreach ($page['translations'] as $locale => $translation) {
        $page['translations'][$locale] = array_replace($translation, [
            'excerpt' => $description,
            'seo_title' => (string) $product['name'] . ' for Sense CMS',
            'seo_description' => $description . ' Signed Stable package available through the Sense CMS Marketplace.',
        ]);
    }
    $cms->savePage($page, $page['translations'], $owner);
    foreach ($page['translations'] as $locale => $translation) {
        $key = 'seo_page_' . $page['id'] . '_' . $locale;
        $metadata = array_replace((array) $cms->setting($key, []), [
            'title' => $translation['seo_title'],
            'description' => $translation['seo_description'],
            'og_title' => $translation['seo_title'],
            'og_description' => $description,
            'twitter_title' => $translation['seo_title'],
            'twitter_description' => $description,
        ]);
        $cms->saveSetting($key, App\Core\SeoMeta::sanitizeDocument($metadata, 'page'));
    }
}
echo 'Published Stable detail copy and metadata for ' . count($products) . " packages.\n";
