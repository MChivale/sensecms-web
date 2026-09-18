<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply', '--rollback'], true)) {
    exit("Usage: php update-telegram-channels-marketplace-0.1.1.php <installation> --apply|--rollback <private-backup>\n");
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
$journal = $backup . '/telegram-channels-marketplace-011-before.json';
$lock = fopen($backup . '/telegram-channels-marketplace-011.lock', 'c+b');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Telegram Channels marketplace update already running.');
$document = static function (string $path) use ($cms): array {
    $page = $cms->pageAtPath($path);
    $doc = $page ? $cms->builderDocument((int) $page['id']) : null;
    if (!$doc) throw new RuntimeException('Expected Telegram Channels marketplace page is unavailable: ' . $path);
    return $doc;
};
$save = static function (array $before) use ($cms, $catalog, $owner): void {
    $current = $cms->builderDocument((int) $before['id']);
    if (!$current) throw new RuntimeException('A Telegram Channels marketplace page disappeared during update.');
    $versions = [];
    foreach ($current['blocks'] as $block) if (!empty($block['global_section_id'])) $versions[(int) $block['global_section_id']] = (int) $block['global_version'];
    foreach ($before['blocks'] as &$block) if (!empty($block['global_section_id']) && isset($versions[(int) $block['global_section_id']])) $block['global_version'] = $versions[(int) $block['global_section_id']];
    unset($block);
    $cms->saveBuilderDocument((int) $before['id'], (int) $current['builder_version'], $owner, 'sensecms', App\Core\PageBuilder::sanitizeBlocks($before['blocks'], $catalog, array_keys($before['translations'])), array_keys($catalog));
};
$replace = static function (mixed &$value, string $old, string $new, int &$count) use (&$replace): void {
    if (is_array($value)) { foreach ($value as &$item) $replace($item, $old, $new, $count); unset($item); }
    elseif (is_string($value)) { $value = str_replace($old, $new, $value, $replaced); $count += $replaced; }
};
$restore = static function (array $state) use ($save): void { foreach (array_reverse($state['documents']) as $doc) $save($doc); };
if ($argv[2] === '--rollback') {
    $restore(json_decode((string) file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR));
    echo "Telegram Channels marketplace metadata restored to 0.1.0.\n";
    exit;
}
if (file_exists($journal)) throw new RuntimeException('Existing Telegram Channels marketplace update journal; inspect before rerun.');
$state = ['documents' => [$document('/extensions'), $document('/extensions/catalog/plugin/telegram-channels-publisher')]];
if (file_put_contents($journal, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Cannot save Telegram Channels marketplace recovery journal.');
try {
    $listing = $state['documents'][0]; $detail = $state['documents'][1]; $listingChanges = $detailChanges = 0;
    $replace($listing['blocks'], 'Signed Development Preview 0.1.0 available', 'Signed Development Preview 0.1.1 available', $listingChanges);
    $replace($detail['blocks'], 'Development Preview 0.1.0', 'Development Preview 0.1.1', $detailChanges);
    if ($listingChanges !== 1 || $detailChanges !== 1) throw new RuntimeException('Unexpected Telegram Channels marketplace 0.1.0 baseline.');
    $save($listing); $save($detail);
    $rendered = json_encode([$document('/extensions')['blocks'], $document('/extensions/catalog/plugin/telegram-channels-publisher')['blocks']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (str_contains($rendered, 'Development Preview 0.1.0') || substr_count($rendered, 'Development Preview 0.1.1') < 2) throw new RuntimeException('Telegram Channels marketplace version verification failed.');
} catch (Throwable $error) { $restore($state); throw $error; }
echo "Telegram Channels marketplace metadata updated to 0.1.1 with a scoped recovery journal.\n";
