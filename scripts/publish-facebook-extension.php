<?php
declare(strict_types=1);

// Operator-only publication of the Facebook Publisher marketplace preview.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply', '--rollback'], true)) {
    exit("Usage: php publish-facebook-extension.php <installation> --apply|--rollback <private-backup>\n");
}
umask(0077);
$root = realpath($argv[1]);
$backup = realpath($argv[3]);
if (!$root || !$backup || !str_starts_with($backup, '/root/')) {
    throw new RuntimeException('Private existing operator paths required.');
}
require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root);
$runtime->license()->enforce($runtime->baseUrl());
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
$owner = (int) $db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
if (!$owner || $cms->defaultLocale() !== 'en') {
    throw new RuntimeException('Expected licensed English product website.');
}
$theme = (new App\Core\Packages\ThemeManager($runtime))->activePath();
$catalog = App\Core\PageBuilder::catalog(json_decode((string) file_get_contents($theme . '/theme.json'), true, 32, JSON_THROW_ON_ERROR));
$file = $backup . '/facebook-extension-before.json';
$lock = fopen($backup . '/facebook-extension.lock', 'c+b');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Facebook extension publication already running.');
}
$save = static function (array $doc, array $blocks) use ($cms, $catalog, $owner): void {
    $current = $cms->builderDocument((int) $doc['id']);
    if (!$current) throw new RuntimeException('A marketplace page disappeared during publication.');
    $cms->saveBuilderDocument(
        (int) $doc['id'],
        (int) $current['builder_version'],
        $owner,
        'sensecms',
        App\Core\PageBuilder::sanitizeBlocks($blocks, $catalog, array_keys($doc['translations'])),
        array_keys($catalog),
    );
};
$persist = static function (array $state) use ($file): void {
    if (file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Cannot save the Facebook publication journal.');
    }
};
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
};

if ($argv[2] === '--rollback') {
    $state = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    foreach ($state['pages'] as $doc) $save($doc, $doc['blocks']);
    if (!empty($state['detail_id'])) $cms->setPageStatus((int) $state['detail_id'], 'archived', $owner);
    if (!empty($state['global_id'])) $cms->archiveGlobalSection((int) $state['global_id'], $owner);
    echo "Facebook Publisher marketplace preview withdrawn; original catalogue pages restored.\n";
    exit;
}
if (file_exists($file)) throw new RuntimeException('Existing Facebook publication journal; inspect before rerun.');

$path = '/extensions/catalog/plugin/facebook-publisher';
if ($cms->pageAtPath($path)) throw new RuntimeException('Facebook Publisher detail route already exists.');
foreach ($cms->globalSections() as $section) {
    if (($section['name'] ?? '') === 'Marketplace: Facebook Publisher') {
        throw new RuntimeException('Facebook Publisher shared section already exists.');
    }
}
$state = ['pages' => [], 'global_id' => null, 'detail_id' => null];
$expected = ['/extensions' => 13, '/extensions/catalog' => 13, '/extensions/catalog/plugin' => 8];
foreach ($expected as $url => $cards) {
    $page = $cms->pageAtPath($url);
    if (!$page) throw new RuntimeException('Missing marketplace route: ' . $url);
    $doc = $cms->builderDocument((int) $page['id']);
    $actual = 0;
    foreach ($doc['blocks'] as $block) {
        $cta = (string) ($block['localized']['en']['cta_url'] ?? '');
        if (preg_match('~^/extensions/catalog/(system|theme|plugin|addon|module|application)/[a-z0-9-]+$~D', $cta)) $actual++;
        if ($cta === $path) throw new RuntimeException('Facebook Publisher is already linked.');
    }
    if ($actual !== $cards) throw new RuntimeException('Marketplace baseline changed; review before publication.');
    $state['pages'][] = $doc;
}
$persist($state);

$rawCard = [[
    'uid' => $uuid(),
    'type' => 'text',
    'visible' => true,
    'shared' => [],
    'localized' => ['en' => [
        'title' => 'Facebook Publisher',
        'text' => '<p><strong>Free</strong> · Plugins</p><p>Publish reviewed Sense CMS posts to one or more connected Facebook Pages through the official Sense CMS Meta application.</p><p>Development Preview · public download not open</p>',
        'cta_label' => 'View package →',
        'cta_url' => $path,
    ]],
]];
$card = App\Core\PageBuilder::sanitizeBlocks($rawCard, $catalog, ['en'])[0];
$global = $cms->createGlobalSection('Marketplace: Facebook Publisher', $card, $owner);
$state['global_id'] = (int) $global['id'];
$persist($state);
$card['global_section_id'] = (int) $global['id'];
$card['global_version'] = 0;
foreach ($state['pages'] as $doc) {
    $linked = $card;
    $linked['uid'] = $uuid();
    $save($doc, array_merge($doc['blocks'], [$linked]));
}

$facility = (new App\Core\FacilityRepository($db))->primary('en', 'en');
if (!$facility) throw new RuntimeException('Primary facility is unavailable.');
$input = ['id' => 0, 'facility_id' => (int) $facility['id'], 'template' => 'default', 'status' => 'draft', 'visibility' => 'public', 'published_at' => null, 'public_path' => null];
$description = 'A free Development Preview for publishing reviewed Sense CMS posts to one or more Facebook Pages.';
$translations = ['en' => [
    'title' => 'Facebook Publisher',
    'slug' => 'extensions-catalog-plugin-facebook-publisher',
    'excerpt' => $description,
    'seo_title' => 'Facebook Publisher for Sense CMS',
    'seo_description' => $description,
]];
$detailId = $cms->savePage($input, $translations, $owner);
$state['detail_id'] = $detailId;
$persist($state);
$sections = [
    ['Free', '<p>This development preview is planned as a free extension. Public package download is not open yet.</p><p>When downloads open, the free package will require a valid Sense CMS system licence and will not be an anonymous download.</p>'],
    ['Development Preview', '<p>Version 0.2.0 is installed and has passed production acceptance with an authorised Meta app account.</p><p>Public package download is not open. Meta Business Verification is In review, Access Verification is unavailable until it completes, and Meta App Review is not complete.</p><p>At present, only app roles and authorised business assets can connect. Public customer access must not be assumed.</p><p>Requires the Sense CMS Social Publishing addon, installed as a technical dependency.</p>'],
    ['Publish to the Pages you choose', '<p>Connect one or more Facebook Pages through one or more Meta user accounts. Editors explicitly select destinations for each reviewed post and can customise the message per destination.</p><p>Sense CMS queues each delivery, prevents duplicate publication and records success, failure and bounded retry history.</p>'],
    ['Privacy and control', '<p>Page access tokens are encrypted inside the CMS installation. The Meta App Secret remains on the official Sense CMS service and is not copied into customer installations.</p><p>Personal profiles are not publishing destinations. Publication always follows explicit editorial review and connected Pages can be disconnected from the workspace.</p>'],
    ['Explore the ecosystem', '<p>Compare themes, plugins, addons and applications with clear pricing and release availability.</p>', 'All extensions →', '/extensions'],
];
$blocks = [];
foreach ($sections as $section) {
    $blocks[] = ['uid' => $uuid(), 'type' => 'text', 'visible' => true, 'shared' => [], 'localized' => ['en' => [
        'title' => $section[0], 'text' => $section[1], 'cta_label' => $section[2] ?? '', 'cta_url' => $section[3] ?? '',
    ]]];
}
$blocks = App\Core\PageBuilder::sanitizeBlocks($blocks, $catalog, ['en']);
$cms->saveBuilderDocument($detailId, 0, $owner, 'sensecms', $blocks, array_keys($catalog));
$cms->savePage(array_replace($input, ['id' => $detailId, 'status' => 'published', 'public_path' => $path]), $translations, $owner);
echo "Published free Facebook Publisher Development Preview across three marketplace collections.\n";
