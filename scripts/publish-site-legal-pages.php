<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array($argc, [4, 5], true) || !in_array($argv[2], ['--apply', '--rollback'], true)) {
    exit("Usage: php publish-site-legal-pages.php <installation> --apply|--rollback <private-backup> [private-legal-source]\n");
}

umask(0077);
$root = realpath($argv[1]);
$backup = realpath($argv[3]);
if (!$root || !is_file($root . '/bootstrap.php') || !$backup || !str_starts_with(str_replace('\\', '/', $backup), '/root/')) {
    throw new RuntimeException('Existing private operator paths are required.');
}

require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root);
$runtime->license()->enforce($runtime->baseUrl());
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
$owner = (int) $db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
$facility = (new App\Core\FacilityRepository($db))->primary('en', 'en');
$manager = new App\Core\Packages\ThemeManager($runtime);
$theme = $manager->active();
$themeRoot = $manager->activePath();
$legalSource = $argc === 5 ? realpath($argv[4]) : realpath($themeRoot . '/legal-pages.php');
if (!$owner || !$facility || $cms->defaultLocale() !== 'en' || ($theme['slug'] ?? '') !== 'sensecms' || !$legalSource || !is_file($legalSource) || is_link($legalSource)) {
    throw new RuntimeException('Expected the licensed English Sense CMS product website and reviewed legal copy.');
}
if ($argc === 5 && !str_starts_with(str_replace('\\', '/', $legalSource), '/root/')) {
    throw new RuntimeException('The reviewed legal copy must use private operator storage.');
}

$legal = require $legalSource;
$paths = ['/privacy-policy', '/terms-and-conditions', '/cookies'];
if (array_keys($legal) !== $paths) throw new RuntimeException('The legal-page inventory is incomplete or out of order.');
$descriptor = json_decode((string) file_get_contents($themeRoot . '/theme.json'), true, 64, JSON_THROW_ON_ERROR);
$catalog = App\Core\PageBuilder::catalog($descriptor);
$locales = array_column($cms->languages(), 'locale');
$journal = $backup . '/site-legal-pages.json';
$write = static function (array $state) use ($journal): void {
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (file_put_contents($journal, $json, LOCK_EX) !== strlen($json) || !chmod($journal, 0600)) throw new RuntimeException('Cannot write the legal-page recovery journal.');
};
$uuid = static function (): string {
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
};
$blocks = static function (array $page) use ($uuid, $catalog): array {
    $raw = [];
    foreach ($page['sections'] as [$title, $text]) $raw[] = ['uid'=>$uuid(), 'type'=>'text', 'visible'=>true, 'shared'=>[], 'localized'=>['en'=>['title'=>$title, 'text'=>'<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 'cta_label'=>'', 'cta_url'=>'']]];
    return App\Core\PageBuilder::sanitizeBlocks($raw, $catalog, ['en']);
};
$input = static fn(array $page): array => [
    'id'=>(int) $page['id'], 'parent_id'=>(int) ($page['parent_id'] ?? 0) ?: null, 'facility_id'=>(int) $page['facility_id'],
    'assigned_user_id'=>(int) ($page['assigned_user_id'] ?? 0) ?: null, 'editorial_note'=>(string) ($page['editorial_note'] ?? ''),
    'public_path'=>$page['public_path'] ?? null, 'template'=>(string) ($page['template'] ?? 'default'), 'status'=>(string) $page['status'],
    'visibility'=>(string) $page['visibility'], 'published_at'=>$page['published_at'] ?? null,
];
$translations = static function (array $rows): array {
    $result = [];
    foreach ($rows as $locale => $row) $result[(string) $locale] = ['title'=>(string) $row['title'], 'slug'=>(string) $row['slug'], 'excerpt'=>(string) ($row['excerpt'] ?? ''), 'seo_title'=>(string) ($row['seo_title'] ?? ''), 'seo_description'=>(string) ($row['seo_description'] ?? '')];
    return $result;
};

if ($argv[2] === '--rollback') {
    if (!is_file($journal)) throw new RuntimeException('Legal-page recovery journal is missing.');
    $state = json_decode((string) file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
    foreach (array_reverse((array) ($state['created'] ?? []), true) as $path => $created) {
        $page = $cms->pageAtPath((string) $path); $document = $page ? $cms->builderDocument((int) $page['id']) : null;
        if (!$page || (int) $page['id'] !== (int) $created['id'] || (int) ($document['builder_version'] ?? -1) !== (int) $created['version']) throw new RuntimeException('A created legal page changed; rollback stopped: ' . $path);
        $cms->setPageStatus((int) $page['id'], 'archived', $owner); $cms->deletePagePermanently((int) $page['id'], $owner);
        echo "Removed created page: {$path}\n";
    }
    foreach (array_reverse((array) ($state['updated'] ?? []), true) as $path => $saved) {
        $page = $cms->pageAtPath((string) $path); $document = $page ? $cms->builderDocument((int) $page['id']) : null;
        if (!$page || (int) $page['id'] !== (int) $saved['page']['id'] || !$document) throw new RuntimeException('An updated legal page changed; rollback stopped: ' . $path);
        $expected = $saved['applied_version']; $beforeVersion = (int) $saved['document']['builder_version']; $currentVersion = (int) $document['builder_version'];
        if ($expected === null && $currentVersion === $beforeVersion) continue;
        if (($expected === null && $currentVersion !== $beforeVersion + 1) || ($expected !== null && $currentVersion !== (int) $expected)) throw new RuntimeException('An updated legal page changed; rollback stopped: ' . $path);
        $beforeBlocks = App\Core\PageBuilder::sanitizeBlocks((array) $saved['document']['blocks'], $catalog, $locales);
        $cms->saveBuilderDocument((int) $page['id'], $currentVersion, $owner, 'sensecms', $beforeBlocks, array_keys($catalog));
        $cms->savePage($input($saved['page']), $translations($saved['page']['translations']), $owner);
        echo "Restored page: {$path}\n";
    }
    $state['status'] = 'rolled-back'; $write($state); exit;
}

if (file_exists($journal)) throw new RuntimeException('Existing legal-page journal requires review.');
foreach ($legal as $path => $_page) {
    $existing = $cms->pageAtPath($path);
    if ($existing && (int) $existing['facility_id'] !== (int) $facility['id']) throw new RuntimeException('Legal path belongs to another facility: ' . $path);
    if (!$existing) {
        $slug = ltrim(str_replace('/', '-', $path), '-');
        $check = $db->prepare('SELECT 1 FROM page_translations WHERE facility_id=? AND locale=? AND slug=?'); $check->execute([(int) $facility['id'], 'en', $slug]);
        if ($check->fetchColumn()) throw new RuntimeException('Existing legal-page slug requires manual review: ' . $slug);
    }
}

$state = ['status'=>'applying', 'updated'=>[], 'created'=>[]]; $write($state);
foreach ($legal as $path => $page) {
    $existing = $cms->pageAtPath($path); $slug = ltrim(str_replace('/', '-', $path), '-');
    $pageTranslations = ['en'=>['title'=>$page['title'], 'slug'=>$slug, 'excerpt'=>$page['description'], 'seo_title'=>$page['title'] . ' · Sense CMS', 'seo_description'=>$page['description']]];
    if ($existing) {
        $id = (int) $existing['id']; $before = $cms->pageAdmin($id); $document = $cms->builderDocument($id);
        if (!$before || !$document) throw new RuntimeException('Cannot load existing legal page: ' . $path);
        $state['updated'][$path] = ['page'=>$before, 'document'=>$document, 'applied_version'=>null]; $write($state);
        $version = $cms->saveBuilderDocument($id, (int) $document['builder_version'], $owner, 'sensecms', $blocks($page), array_keys($catalog));
        $state['updated'][$path]['applied_version'] = $version; $write($state);
        $cms->savePage(['id'=>$id, 'parent_id'=>$before['parent_id'], 'facility_id'=>(int) $facility['id'], 'assigned_user_id'=>$before['assigned_user_id'], 'editorial_note'=>$before['editorial_note'], 'public_path'=>$path, 'template'=>'default', 'status'=>'published', 'visibility'=>'public', 'published_at'=>$before['published_at']], $pageTranslations, $owner);
        echo "Updated managed page {$id}: {$path}\n";
        continue;
    }
    $id = $cms->savePage(['id'=>0, 'facility_id'=>(int) $facility['id'], 'public_path'=>$path, 'template'=>'default', 'status'=>'draft', 'visibility'=>'public', 'published_at'=>null], $pageTranslations, $owner);
    $state['created'][$path] = ['id'=>$id, 'version'=>0]; $write($state);
    $version = $cms->saveBuilderDocument($id, 0, $owner, 'sensecms', $blocks($page), array_keys($catalog));
    $state['created'][$path]['version'] = $version; $write($state);
    $cms->savePage(['id'=>$id, 'facility_id'=>(int) $facility['id'], 'public_path'=>$path, 'template'=>'default', 'status'=>'published', 'visibility'=>'public', 'published_at'=>null], $pageTranslations, $owner);
    echo "Published managed page {$id}: {$path}\n";
}
$state['status'] = 'applied'; $write($state);
echo 'Published ' . count($legal) . " site legal pages.\n";
