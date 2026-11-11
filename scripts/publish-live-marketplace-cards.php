<?php
declare(strict_types=1);

// Replaces only the public marketplace landing and category documents with the
// current signed Stable inventory. The private journal enables one exact rollback.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply', '--rollback'], true)) exit("Usage: php publish-live-marketplace-cards.php <installation-root> --apply|--rollback <private-backup>\n");
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
$journal = $backup . '/live-marketplace-cards.json';
$save = static function (array $document) use ($cms, $catalog, $owner): void {
    $current = $cms->builderDocument((int) $document['id']);
    if (!$current) throw new RuntimeException('A marketplace page disappeared during publication.');
    $cms->saveBuilderDocument((int) $document['id'], (int) $current['builder_version'], $owner, 'sensecms', App\Core\PageBuilder::sanitizeBlocks($document['blocks'], $catalog, array_keys($document['translations'])), array_keys($catalog));
};
if ($argv[2] === '--rollback') {
    $state = json_decode((string) file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
    foreach ($state['documents'] ?? [] as $document) $save($document);
    foreach ($state['pages'] ?? [] as $page) $cms->savePage($page, $page['translations'], $owner);
    echo "Restored marketplace cards and metadata.\n"; exit;
}
if (file_exists($journal)) throw new RuntimeException('Existing marketplace-card journal; inspect or roll it back first.');
$products = require dirname(__DIR__) . '/.src/package-catalog.php';
$distribution = new App\Core\Packages\Distribution($runtime);
$types = ['theme'=>'Themes', 'plugin'=>'Plugins', 'addon'=>'Add-ons'];
foreach ($products as $product) {
    $identity = $product['type'] . ':' . $product['slug']; $offer = $distribution->entry($identity);
    if (!isset($types[$product['type']]) || ($offer['channel'] ?? '') !== 'stable' || ($offer['pricing'] ?? '') !== 'free' || ($offer['version'] ?? '') !== ($product['version'] ?? '')) throw new RuntimeException('Package card does not match signed distribution: ' . $identity);
}
$pages = [
    '/extensions' => ['title'=>'Sense CMS Marketplace', 'description'=>'Browse signed Stable themes, plugins and add-ons available with a valid Sense CMS Core licence.'],
    '/extensions/catalog' => ['title'=>'Sense CMS Marketplace Catalogue', 'description'=>'Explore signed Stable packages available for installation with a valid Sense CMS Core licence.'],
    '/extensions/catalog/theme' => ['title'=>'Themes for Sense CMS', 'description'=>'Signed Stable themes available with a valid Sense CMS Core licence.'],
    '/extensions/catalog/plugin' => ['title'=>'Plugins for Sense CMS', 'description'=>'Signed Stable plugins available with a valid Sense CMS Core licence.'],
    '/extensions/catalog/addon' => ['title'=>'Add-ons for Sense CMS', 'description'=>'Signed Stable add-ons available with a valid Sense CMS Core licence.'],
];
$documents = []; $beforePages = [];
foreach ($pages as $path => $_) {
    $page = $cms->pageAtPath($path);
    if (!$page) throw new RuntimeException('Missing public marketplace page: ' . $path);
    $documents[$path] = $cms->builderDocument((int) $page['id']);
    $beforePages[$path] = $cms->pageAdmin((int) $page['id']);
}
file_put_contents($journal, json_encode(['documents'=>$documents, 'pages'=>$beforePages], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$uuid = static function (): string { $raw = bin2hex(random_bytes(16)); return substr($raw,0,8).'-'.substr($raw,8,4).'-4'.substr($raw,13,3).'-a'.substr($raw,17,3).'-'.substr($raw,20); };
foreach ($pages as $path => $copy) {
    $subset = $path === '/extensions' || $path === '/extensions/catalog' ? $products : array_values(array_filter($products, static fn(array $product): bool => $product['type'] === basename($path)));
    $blocks = [];
    foreach ($subset as $product) {
        $localized = [];
        $text = '<p><strong>Free with a valid Sense CMS Core licence</strong> · ' . $types[$product['type']] . '</p><p>' . $e((string) $product['description']) . '</p><p>Signed Stable release · version ' . $e((string) $product['version']) . '.</p>';
        foreach ($locales as $locale) $localized[$locale] = ['title'=>$product['name'], 'text'=>$text, 'cta_label'=>'View package →', 'cta_url'=>'/extensions/catalog/' . $product['type'] . '/' . $product['slug']];
        $blocks[] = ['uid'=>$uuid(), 'type'=>'text', 'visible'=>true, 'shared'=>[], 'localized'=>$localized];
    }
    $document = $documents[$path]; $document['blocks'] = $blocks; $save($document);
    $page = $beforePages[$path];
    foreach ($page['translations'] as $locale => $translation) $page['translations'][$locale] = array_replace($translation, ['title'=>$copy['title'], 'excerpt'=>$copy['description'], 'seo_title'=>$copy['title'], 'seo_description'=>$copy['description']]);
    $cms->savePage($page, $page['translations'], $owner);
}
echo 'Published Stable marketplace cards for ' . count($products) . " packages.\n";
