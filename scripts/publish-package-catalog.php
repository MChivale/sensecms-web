<?php
declare(strict_types=1);

// Operator-only CMS content publication; no package executable or Core is installed.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply','--rollback','--release-status','--calendar-status','--analytics-status','--google-calendar-status','--microsoft-calendar-status','--apple-calendar-status','--telegram-status'], true)) exit("Usage: php publish-package-catalog.php <installation> <publication-mode> <private-backup>\n");
umask(0077);
$root = realpath($argv[1]); $backup = realpath($argv[3]);
if (!$root || !$backup || !str_starts_with($backup, '/root/')) throw new RuntimeException('Private existing operator paths required.');
require $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root);
$runtime->license()->enforce($runtime->baseUrl());
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
$stateFile = $backup . '/catalog-changes.json';
$lock = fopen($backup . '/catalog.lock', 'c+b');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Publication already running.');
if (in_array($argv[2], ['--release-status','--calendar-status','--analytics-status','--google-calendar-status','--microsoft-calendar-status','--apple-calendar-status','--telegram-status'], true)) {
    // Update only the existing detail block; availability comes from trusted live inventory.
    $calendar = $argv[2] === '--calendar-status';
    $analytics = $argv[2] === '--analytics-status';
    $googleCalendar = $argv[2] === '--google-calendar-status';
    $microsoftCalendar = $argv[2] === '--microsoft-calendar-status';
    $telegram = $argv[2] === '--telegram-status';
    $appleCalendar = $argv[2] === '--apple-calendar-status';
    $identity = $microsoftCalendar ? 'plugin:microsoft-365-calendar' : ($googleCalendar ? 'plugin:google-calendar' : ($analytics ? 'plugin:google-analytics' : ($calendar ? 'addon:calendar' : 'theme:sensecms')));
    if ($appleCalendar) $identity = 'plugin:apple-calendar';
    if ($telegram) $identity = 'plugin:telegram-notifications';
    $offer = (new App\Core\Packages\Distribution($runtime))->entry($identity);
    $page = $cms->pageAtPath('/extensions/catalog/' . str_replace(':','/',$identity));
    if (!$page || $offer['pricing'] !== ($calendar || $googleCalendar || $microsoftCalendar || $appleCalendar || $telegram ? 'paid' : 'free')) throw new RuntimeException('Unexpected official package.');
    $doc = $cms->builderDocument((int)$page['id']);
    $blocks = $doc['blocks']; $matched = 0;
    foreach ($blocks as &$item) {
        if (($item['localized']['en']['title'] ?? '') !== 'Release availability') continue;
        $matched++;
        if (!empty($item['global_section_id'])) throw new RuntimeException('Unexpected shared detail block.');
        $item['localized']['en']['text'] = '<p>Signed ' . $offer['channel'] . ' package available.</p><p>Available version: ' . $offer['version'] . '.</p><p>Download requires ' . ($calendar ? 'a separate valid Sense CMS Calendar licence' : 'a valid Sense CMS system licence') . ' and its installation domain. ' . ($offer['channel'] === 'development' ? 'This is not a Stable release.' : '') . '</p>';
        if ($analytics) $item['localized']['en']['text'] .= '<p>Configure your GA4 measurement ID in System → Extensions. Tracking starts only after visitor consent on published CMS pages. Signed-in administrators, previews, system routes and standalone theme fallback pages are excluded. Publishing this package does not enable tracking on SenseCMS.com.</p>';
        if ($googleCalendar) $item['localized']['en']['text'] = '<p>Signed development package available. Available version: 0.1.0.</p><p>Requires a separate valid Sense CMS Google Calendar licence and its installation domain, plus the installed and active Sense CMS Calendar addon. This is not a Stable release.</p><p>Outbound delivery only. Configure an authorised writable Google calendar in Calendar → Integrations. Private and participant-only events are excluded. OAuth credentials are encrypted within your installation.</p><p>Package installation, protocol tests and licence-gated download are verified. Live event delivery to a Google account has not yet been verified. No Google calendar is connected on SenseCMS.com.</p>';
        if ($telegram) $item['localized']['en']['text'] = '<p>Signed development package available. Version 0.1.1. This is not a Stable release.</p><p>Requires a valid Sense CMS Telegram Notifications product licence and its installation domain. A CMS licence alone does not authorize this paid download. The same product key remains valid for compatible updates while its licence is valid.</p><p>Install the signed plugin, enable Telegram in System → Notification channels, then connect your personal account in My settings. Press Start in @SenseCMSBot using the one-time link. Each user opts in privately. No bot token is copied into the installation and Calendar is optional.</p><p>Requires PHP 8.5 and a Sense CMS Core with central Telegram transport support. The official site has passed actual account connection and message delivery. Installation, upgrade and rollback are tested independently. Temporary connection records are removed after their grace period; account bindings and delivery deduplication records are preserved.</p>';
        $item['localized']['en']['cta_label'] = 'Download in marketplace';
        if ($microsoftCalendar) $item['localized']['en']['text'] = '<p>Signed development package available. Available version: 0.1.0.</p><p>Requires a separate valid Sense CMS Microsoft 365 Calendar licence and its installation domain, plus the installed and active Sense CMS Calendar addon. This is not a Stable release.</p><p>Outbound delivery only. Configure the tenant ID, application ID and secret, mailbox and optional calendar ID in Calendar → Integrations. Use an administrator-authorised application with Calendars.ReadWrite and restrict its mailbox access. Credentials are encrypted within your installation. Private and participant-only events are excluded.</p><p>Package installation, protocol tests and licence-gated download are verified. Live event delivery to a Microsoft 365 account has not yet been verified. No Microsoft account is connected on SenseCMS.com.</p>';
        $item['localized']['en']['cta_url'] = '/extensions';
        if ($appleCalendar) $item['localized']['en']['text'] = '<p>Signed development package available. Available version: 0.1.0.</p><p>Requires a separate valid Sense CMS Apple Calendar licence and its installation domain, plus the installed and active Sense CMS Calendar addon. This is not a Stable release.</p><p>Outbound iCloud CalDAV delivery only. Configure your private calendar collection URL, Apple Account email and app-specific password in Calendar → Integrations. Use a dedicated writable calendar. Automatic calendar discovery and two-way sync are not included. Credentials are encrypted within your installation; private and participant-only events are excluded.</p><p>Conditional writes protect against concurrent changes during delivery. Events managed by this integration should be edited in Sense CMS, which is their source of truth.</p><p>Package installation, protocol tests and licence-gated download are verified. Live event delivery to an Apple iCloud account has not yet been verified. No Apple account is connected on SenseCMS.com.</p>';
    }
    unset($item);
    if ($matched !== 1) throw new RuntimeException('Expected exactly one release block.');
    $owner = (int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
    if (!$owner) throw new RuntimeException('No active owner.');
    $themeRoot = (new App\Core\Packages\ThemeManager($runtime))->activePath();
    $catalog = App\Core\PageBuilder::catalog(json_decode((string)file_get_contents($themeRoot . '/theme.json'),true));
    $journal = fopen($backup . '/release-detail-before.json','xb');
    if (!$journal) throw new RuntimeException('Release detail journal already exists.');
    try { $raw=json_encode($doc,JSON_THROW_ON_ERROR); if (fwrite($journal,$raw)!==strlen($raw) || !fflush($journal)) throw new RuntimeException('Cannot save release detail journal.'); }
    finally { fclose($journal); }
    $cms->saveBuilderDocument((int)$page['id'],(int)$doc['builder_version'],$owner,'sensecms',App\Core\PageBuilder::sanitizeBlocks($blocks,$catalog,array_keys($doc['translations'])),array_keys($catalog));
    echo "Official package detail now matches enabled distribution.\n"; exit;
}
if ($argv[2] === '--rollback') {
    $state = json_decode((string) file_get_contents($stateFile), true, 64, JSON_THROW_ON_ERROR);
    foreach ($state['created'] as $item) $db->prepare("UPDATE pages SET status='draft',public_path=NULL WHERE id=? AND public_path=?")->execute([$item['id'],$item['path']]);
    foreach ($state['links'] as $item) $db->prepare('UPDATE content_blocks SET visible=0,archived_at=NOW() WHERE page_id=? AND uid=?')->execute([$item['id'],$item['uid']]);
    echo "Catalog pages withdrawn; existing content and package files preserved.\n"; exit;
}
if (file_exists($stateFile)) throw new RuntimeException('Publication already attempted; inspect its journal.');
$products = require dirname(__DIR__) . '/.src/package-catalog.php';
$types = ['theme'=>'Themes','plugin'=>'Plugins','addon'=>'Addons','module'=>'Modules','system'=>'Core','application'=>'Applications'];
$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$path = static fn(array $p): string => '/extensions/catalog/' . $p['type'] . '/' . $p['slug'];
$price = static fn(array $p): string => $p['usd_year'] === 0 ? 'Free' : 'USD ' . $p['usd_year'] . ' / year';
$availability = static fn(array $p): string => match($p['status']) {
    'packaged'=>'Signed development package prepared · public download not open',
    'development'=>'Development installer · Stable release not available',
    default=>'Sense CMS adaptation pending · not available to download',
};
$card = static fn(array $p): array => [$p['name'], '<p><strong>' . $price($p) . '</strong> · ' . $types[$p['type']] . '</p><p>' . $e($p['description']) . '</p><p>' . $e($availability($p)) . '</p>', 'View package →', $path($p)];
$pages = ['/extensions/catalog'=>['title'=>'Find the right tools for your system.','description'=>'Explore all packages, annual licence tiers and their actual Sense CMS release status. Prices are in USD; purchasing and public downloads are not open yet.','sections'=>array_map($card,$products)]];
foreach ($products as $p) {
    if (!in_array($p['type'],array_keys($types),true) || !is_int($p['usd_year']) || $p['usd_year']<0) throw new RuntimeException('Invalid product classification.');
    $license = $p['usd_year'] === 0 ? 'Downloading this free package requires a valid Sense CMS system licence. It is not an anonymous download.' : ($p['type']==='system' ? 'Core installation requires the Sense CMS system licence.' : 'This paid package requires its own product licence. A Sense CMS system licence alone does not authorise this download.');
    $pages[$path($p)] = ['title'=>$p['name'],'description'=>$p['description'],'sections'=>[
        [$price($p),'<p>' . $e($license) . '</p>'],
        ['Release availability','<p>' . $e($availability($p)) . '</p>' . (isset($p['version']) ? '<p>Prepared version: ' . $e($p['version']) . '.</p>' : '') . (isset($p['requires']) ? '<p>Requires ' . $e($p['requires']) . '.</p>' : '')],
        ['One product. One licence.','<p>New package versions do not require a replacement key while the same product licence is valid. Product identity, domain binding and validity dates are checked separately from PHP, Core and package compatibility.</p>','Licensing guide →','/docs/licensing'],
        ['Explore the ecosystem','<p>Compare the available product tiers and development status.</p>','All packages →','/extensions/catalog'],
    ]];
}
foreach (['theme','plugin','addon','module'] as $type) $pages['/extensions/catalog/' . $type] = ['title'=>$types[$type] . ' for Sense CMS','description'=>'Compare licence tiers and actual availability.','sections'=>array_map($card,array_values(array_filter($products,static fn($p)=>$p['type']===$type))) ?: [['No module release yet','<p>No separately installable Sense CMS module has been released. This category will list packages after implementation and acceptance.</p>']]];
$themeRoot = (new App\Core\Packages\ThemeManager($runtime))->activePath();
$descriptor = json_decode((string) file_get_contents($themeRoot . '/theme.json'),true);
$catalog = App\Core\PageBuilder::catalog($descriptor);
$facility = (new App\Core\FacilityRepository($db))->primary('en','en');
$owner = (int) $db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
if (!$facility || !$owner || $cms->defaultLocale()!=='en') throw new RuntimeException('Expected English product site with an active owner and primary facility.');
foreach ($pages as $url=>$copy) {
    if ($cms->pageAtPath($url)) throw new RuntimeException('Existing catalogue route needs reviewed reconciliation: ' . $url);
    $check = $db->prepare('SELECT 1 FROM page_translations WHERE facility_id=? AND locale=? AND slug=?');
    $check->execute([$facility['id'],'en',str_replace('/','-',ltrim($url,'/'))]);
    if ($check->fetchColumn()) throw new RuntimeException('Existing catalogue slug needs review.');
}
$uuid = static function (): string { $s=bin2hex(random_bytes(16)); return substr($s,0,8).'-'.substr($s,8,4).'-4'.substr($s,13,3).'-a'.substr($s,17,3).'-'.substr($s,20); };
$block = static fn(array $s): array => ['uid'=>$uuid(),'type'=>'text','visible'=>true,'shared'=>[],'localized'=>['en'=>['title'=>$s[0],'text'=>$s[1],'cta_label'=>$s[2]??'','cta_url'=>$s[3]??'']]];
$state = ['created'=>[],'links'=>[],'before'=>[]];
$persist = static function () use (&$state,$stateFile): void { if (file_put_contents($stateFile,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Cannot save publication journal.'); };
$persist();
foreach ($pages as $url=>$copy) {
    $blocks = App\Core\PageBuilder::sanitizeBlocks(array_map($block,$copy['sections']),$catalog,['en']);
    $input = ['id'=>0,'facility_id'=>(int)$facility['id'],'template'=>'default','status'=>'draft','visibility'=>'public','published_at'=>null];
    $translations = ['en'=>['title'=>$copy['title'],'slug'=>str_replace('/','-',ltrim($url,'/')),'excerpt'=>$copy['description'],'seo_title'=>$copy['title'],'seo_description'=>$copy['description']]];
    $id = $cms->savePage($input,$translations,$owner);
    $state['created'][] = ['id'=>$id,'path'=>$url]; $persist();
    $cms->saveBuilderDocument($id,0,$owner,'sensecms',$blocks,array_keys($catalog));
    $cms->savePage(array_replace($input,['id'=>$id,'status'=>'published','public_path'=>$url]),$translations,$owner);
    echo "Published $url\n";
}
foreach (['/extensions'=>'/extensions/catalog','/extensions/themes'=>'/extensions/catalog/theme','/extensions/plugins'=>'/extensions/catalog/plugin','/extensions/addons'=>'/extensions/catalog/addon','/extensions/modules'=>'/extensions/catalog/module','/download'=>'/extensions/catalog'] as $url=>$target) {
    $existing = $cms->pageAtPath($url);
    if (!$existing) throw new RuntimeException('Expected existing product route.');
    $doc = $cms->builderDocument((int)$existing['id']);
    $new = $block(['Package catalogue','<p>View products, free and paid licence tiers, and current Sense CMS availability.</p>','Browse packages →',$target]);
    $state['before'][$url] = $doc; $state['links'][] = ['id'=>$existing['id'],'uid'=>$new['uid']]; $persist();
    $blocks = App\Core\PageBuilder::sanitizeBlocks(array_merge([$new],$doc['blocks']),$catalog,array_keys($doc['translations']));
    $cms->saveBuilderDocument((int)$existing['id'],(int)$doc['builder_version'],$owner,'sensecms',$blocks,array_keys($catalog));
}
echo 'Published catalogue: ' . count($products) . ' products, ' . count($pages) . " managed pages.\n";
