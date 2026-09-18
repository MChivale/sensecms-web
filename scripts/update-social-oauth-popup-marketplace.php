<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply', '--rollback'], true)) {
    exit("Usage: php update-social-oauth-popup-marketplace.php <installation> --apply|--rollback <private-backup>\n");
}
umask(0077);
$root = realpath($argv[1]);
$backup = realpath($argv[3]);
if (!$root || !$backup || !str_starts_with($backup, '/root/')) throw new RuntimeException('Private existing operator paths required.');
require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root);
$runtime->license()->enforce($runtime->baseUrl());
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
$owner = (int) $db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
if (!$owner || $cms->defaultLocale() !== 'en') throw new RuntimeException('Expected licensed English product website.');
$theme = (new App\Core\Packages\ThemeManager($runtime))->activePath();
$catalog = App\Core\PageBuilder::catalog(json_decode((string) file_get_contents($theme . '/theme.json'), true, 32, JSON_THROW_ON_ERROR));
$journal = $backup . '/social-oauth-popup-marketplace-before.json';
$lock = fopen($backup . '/social-oauth-popup-marketplace.lock', 'c+b');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Social OAuth marketplace update already running.');

$document = static function (string $path) use ($cms): array {
    $page = $cms->pageAtPath($path);
    $doc = $page ? $cms->builderDocument((int) $page['id']) : null;
    if (!$doc) throw new RuntimeException('Expected marketplace page is unavailable: ' . $path);
    return $doc;
};
$save = static function (array $before) use ($cms, $catalog, $owner): void {
    $current = $cms->builderDocument((int) $before['id']);
    if (!$current) throw new RuntimeException('A marketplace page disappeared during update.');
    $versions = [];
    foreach ($current['blocks'] as $block) if (!empty($block['global_section_id'])) $versions[(int) $block['global_section_id']] = (int) $block['global_version'];
    foreach ($before['blocks'] as &$block) if (!empty($block['global_section_id']) && isset($versions[(int) $block['global_section_id']])) $block['global_version'] = $versions[(int) $block['global_section_id']];
    unset($block);
    $cms->saveBuilderDocument((int) $before['id'], (int) $current['builder_version'], $owner, 'sensecms', App\Core\PageBuilder::sanitizeBlocks($before['blocks'], $catalog, array_keys($before['translations'])), array_keys($catalog));
};
$replace = static function (mixed &$value, string $old, string $new, int &$count) use (&$replace): void {
    if (is_array($value)) {
        foreach ($value as &$item) $replace($item, $old, $new, $count);
        unset($item);
    } elseif (is_string($value)) {
        $value = str_replace($old, $new, $value, $replaced);
        $count += $replaced;
    }
};
$restore = static function (array $state) use ($save): void {
    foreach (array_reverse($state['documents']) as $doc) $save($doc);
};

if ($argv[2] === '--rollback') {
    $state = json_decode((string) file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
    $restore($state);
    echo "Social publisher marketplace versions restored.\n";
    exit;
}
if (file_exists($journal)) throw new RuntimeException('Existing social OAuth marketplace journal; inspect before rerun.');
$paths = [
    '/extensions',
    '/extensions/catalog/plugin/facebook-publisher',
    '/extensions/catalog/plugin/x-publisher',
    '/extensions/catalog/plugin/linkedin-publisher',
    '/extensions/catalog/plugin/bluesky-publisher',
];
$state = ['documents' => array_map($document, $paths)];
if (file_put_contents($journal, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Cannot save marketplace recovery journal.');

try {
    $changes = [0, 0, 0, 0, 0];
    $replace($state['documents'][0]['blocks'], 'Development Preview 0.1.2', 'Development Preview 0.1.3', $changes[0]);
    $replace($state['documents'][1]['blocks'], 'Version 0.2.0 is installed', 'Version 0.2.1 is installed', $changes[1]);
    $replace($state['documents'][2]['blocks'], 'Version 0.1.0 is installed', 'Version 0.1.1 is installed', $changes[2]);
    $replace($state['documents'][3]['blocks'], 'Version 0.1.0 is installed', 'Version 0.1.1 is installed', $changes[3]);
    $replace($state['documents'][4]['blocks'], 'Development Preview 0.1.2', 'Development Preview 0.1.3', $changes[4]);
    if ($changes !== [1, 1, 1, 1, 1]) throw new RuntimeException('Unexpected social publisher marketplace baseline: ' . json_encode($changes, JSON_THROW_ON_ERROR));
    foreach ($state['documents'] as $doc) $save($doc);
    $rendered = [];
    foreach ($paths as $path) $rendered[$path] = json_encode($document($path)['blocks'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (!str_contains($rendered[$paths[0]], 'Development Preview 0.1.3')
        || !str_contains($rendered[$paths[1]], 'Version 0.2.1 is installed')
        || !str_contains($rendered[$paths[2]], 'Version 0.1.1 is installed')
        || !str_contains($rendered[$paths[3]], 'Version 0.1.1 is installed')
        || !str_contains($rendered[$paths[4]], 'Development Preview 0.1.3')) {
        throw new RuntimeException('Social publisher marketplace version verification failed.');
    }
} catch (Throwable $error) {
    $restore($state);
    throw $error;
}

echo "Social publisher marketplace versions updated for automatic OAuth popup closure.\n";
