<?php
declare(strict_types=1);

// Operator-only publication of the X and LinkedIn Publisher marketplace previews.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply', '--rollback'], true)) {
    exit("Usage: php publish-social-publisher-extensions.php <installation> --apply|--rollback <private-backup>\n");
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
$file = $backup . '/social-publisher-extensions-before.json';
$lock = fopen($backup . '/social-publisher-extensions.lock', 'c+b');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Social publisher extension publication already running.');
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
        throw new RuntimeException('Cannot save the social publisher publication journal.');
    }
};
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
};
$products = [
    [
        'slug' => 'x-publisher',
        'name' => 'X Publisher',
        'description' => 'A free Development Preview for publishing reviewed Sense CMS posts to one or more X accounts.',
        'card' => 'Publish reviewed Sense CMS posts to one or more connected X accounts through the official Sense CMS application.',
        'sections' => [
            ['Free', '<p>This development preview is planned as a free extension. Public package download is not open yet.</p><p>When downloads open, the free package will require a valid Sense CMS system licence and will not be an anonymous download.</p>'],
            ['Development Preview', '<p>Version 0.1.0 is installed and has passed production acceptance with an authorised X developer application and a connected account.</p><p>Public package download is not open. Customer connections remain subject to X developer access, terms, quotas and any applicable provider charges.</p><p>Requires the Sense CMS Social Publishing addon, installed as a technical dependency.</p>'],
            ['Publish to the accounts you choose', '<p>Connect one or more X accounts with OAuth 2.0 Authorization Code and PKCE. Editors explicitly select destinations for each reviewed post and can customise the message per destination.</p><p>Sense CMS queues each delivery, prevents duplicate publication and records success, failure and bounded retry history.</p>'],
            ['Privacy and control', '<p>Access and refresh credentials are encrypted inside the CMS installation. The X Client Secret remains on the official Sense CMS service and is not copied into customer installations.</p><p>Publication always follows explicit editorial review. Connected accounts can be managed and disconnected independently.</p>'],
        ],
    ],
    [
        'slug' => 'linkedin-publisher',
        'name' => 'LinkedIn Publisher',
        'description' => 'A free Development Preview for publishing reviewed Sense CMS posts to one or more LinkedIn member profiles.',
        'card' => 'Publish reviewed Sense CMS posts to one or more connected LinkedIn member profiles through the official Sense CMS application.',
        'sections' => [
            ['Free', '<p>This development preview is planned as a free extension. Public package download is not open yet.</p><p>When downloads open, the free package will require a valid Sense CMS system licence and will not be an anonymous download.</p>'],
            ['Development Preview', '<p>Version 0.1.0 is installed and has passed production acceptance with the LinkedIn OpenID Connect and Share on LinkedIn products and a connected member profile.</p><p>Public package download is not open. This release supports member profiles. Company Page publishing is not available until LinkedIn grants the required Community Management API access.</p><p>Requires the Sense CMS Social Publishing addon, installed as a technical dependency.</p>'],
            ['Publish to the profiles you choose', '<p>Connect one or more LinkedIn member profiles. Editors explicitly select destinations for each reviewed post and can customise the message per destination.</p><p>Sense CMS queues each delivery, prevents duplicate publication and records success, failure and bounded retry history.</p>'],
            ['Privacy and control', '<p>Member access tokens are encrypted inside the CMS installation. The LinkedIn Client Secret remains on the official Sense CMS service and is not copied into customer installations.</p><p>Publication always follows explicit editorial review. Connected profiles can be managed and disconnected independently.</p>'],
        ],
    ],
];
$rollback = static function (array $state) use ($cms, $save, $owner): void {
    foreach ($state['pages'] ?? [] as $doc) $save($doc, $doc['blocks']);
    foreach ($state['products'] ?? [] as $product) {
        if (!empty($product['detail_id'])) $cms->setPageStatus((int) $product['detail_id'], 'archived', $owner);
        if (!empty($product['global_id'])) $cms->archiveGlobalSection((int) $product['global_id'], $owner);
    }
};

if ($argv[2] === '--rollback') {
    $state = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    $rollback($state);
    echo "X and LinkedIn Publisher marketplace previews withdrawn; original catalogue pages restored.\n";
    exit;
}
if (file_exists($file)) throw new RuntimeException('Existing social publisher publication journal; inspect before rerun.');

$state = ['pages' => [], 'products' => []];
$existingDetails = [];
$expected = ['/extensions' => 14, '/extensions/catalog' => 14, '/extensions/catalog/plugin' => 9];
foreach ($expected as $url => $cards) {
    $page = $cms->pageAtPath($url);
    if (!$page) throw new RuntimeException('Missing marketplace route: ' . $url);
    $doc = $cms->builderDocument((int) $page['id']);
    $actual = 0;
    foreach ($doc['blocks'] as $block) {
        $cta = (string) ($block['localized']['en']['cta_url'] ?? '');
        if (preg_match('~^/extensions/catalog/(system|theme|plugin|addon|module|application)/[a-z0-9-]+$~D', $cta)) $actual++;
        foreach ($products as $product) {
            if ($cta === '/extensions/catalog/plugin/' . $product['slug']) throw new RuntimeException($product['name'] . ' is already linked.');
        }
    }
    if ($actual !== $cards) throw new RuntimeException('Marketplace baseline changed; review before publication.');
    $state['pages'][] = $doc;
}
foreach ($products as $product) {
    $path = '/extensions/catalog/plugin/' . $product['slug'];
    $existing = $cms->pageAtPath($path);
    if ($existing) {
        $page = $cms->pageAdmin((int) $existing['id']);
        if (($page['status'] ?? '') !== 'archived') throw new RuntimeException($product['name'] . ' detail route already exists.');
        $existingDetails[$product['slug']] = (int) $existing['id'];
    }
    foreach ($cms->globalSections() as $section) {
        if (($section['name'] ?? '') === 'Marketplace: ' . $product['name']) throw new RuntimeException($product['name'] . ' shared section already exists.');
    }
}
$persist($state);

try {
    $cards = [];
    foreach ($products as $product) {
        $path = '/extensions/catalog/plugin/' . $product['slug'];
        $rawCard = [[
            'uid' => $uuid(),
            'type' => 'text',
            'visible' => true,
            'shared' => [],
            'localized' => ['en' => [
                'title' => $product['name'],
                'text' => '<p><strong>Free</strong> · Plugins</p><p>' . $product['card'] . '</p><p>Development Preview · public download not open</p>',
                'cta_label' => 'View package →',
                'cta_url' => $path,
            ]],
        ]];
        $card = App\Core\PageBuilder::sanitizeBlocks($rawCard, $catalog, ['en'])[0];
        $global = $cms->createGlobalSection('Marketplace: ' . $product['name'], $card, $owner);
        $state['products'][$product['slug']] = ['global_id' => (int) $global['id'], 'detail_id' => null];
        $persist($state);
        $card['global_section_id'] = (int) $global['id'];
        $card['global_version'] = 0;
        $cards[] = $card;
    }
    foreach ($state['pages'] as $doc) {
        $linked = [];
        foreach ($cards as $card) {
            $item = $card;
            $item['uid'] = $uuid();
            $linked[] = $item;
        }
        $save($doc, array_merge($doc['blocks'], $linked));
    }

    $facility = (new App\Core\FacilityRepository($db))->primary('en', 'en');
    if (!$facility) throw new RuntimeException('Primary facility is unavailable.');
    $input = ['id' => 0, 'facility_id' => (int) $facility['id'], 'template' => 'default', 'status' => 'draft', 'visibility' => 'public', 'published_at' => null, 'public_path' => null];
    foreach ($products as $product) {
        $path = '/extensions/catalog/plugin/' . $product['slug'];
        $translations = ['en' => [
            'title' => $product['name'],
            'slug' => 'extensions-catalog-plugin-' . $product['slug'],
            'excerpt' => $product['description'],
            'seo_title' => $product['name'] . ' for Sense CMS',
            'seo_description' => $product['description'],
        ]];
        $detailInput = array_replace($input, ['id' => $existingDetails[$product['slug']] ?? 0]);
        $detailId = $cms->savePage($detailInput, $translations, $owner);
        $state['products'][$product['slug']]['detail_id'] = $detailId;
        $persist($state);
        $sections = array_merge($product['sections'], [[
            'Explore the ecosystem',
            '<p>Compare themes, plugins, addons and applications with clear pricing and release availability.</p>',
            'All extensions →',
            '/extensions',
        ]]);
        $blocks = [];
        foreach ($sections as $section) {
            $blocks[] = ['uid' => $uuid(), 'type' => 'text', 'visible' => true, 'shared' => [], 'localized' => ['en' => [
                'title' => $section[0], 'text' => $section[1], 'cta_label' => $section[2] ?? '', 'cta_url' => $section[3] ?? '',
            ]]];
        }
        $blocks = App\Core\PageBuilder::sanitizeBlocks($blocks, $catalog, ['en']);
        $detailDocument = $cms->builderDocument($detailId);
        if (!$detailDocument) throw new RuntimeException($product['name'] . ' detail document is unavailable.');
        $cms->saveBuilderDocument($detailId, (int) $detailDocument['builder_version'], $owner, 'sensecms', $blocks, array_keys($catalog));
        $cms->savePage(array_replace($input, ['id' => $detailId, 'status' => 'published', 'public_path' => $path]), $translations, $owner);
    }
} catch (Throwable $error) {
    $rollback($state);
    throw $error;
}
echo "Published free X and LinkedIn Publisher Development Previews across three marketplace collections.\n";
