<?php

declare(strict_types=1);

use App\Core\Packages\Archive;
use App\Core\Packages\ThemeManager;
use App\Core\PublicTheme;
use App\Core\Runtime;

require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$source = dirname(__DIR__) . '/.themes/sensecms';
$theme = new PublicTheme($source, 'https://www.sensecms.com');
$pages = require $source . '/pages.php';
$count = 0;
$assert = static function (bool $ok, string $name) use (&$count): void { if (!$ok) throw new RuntimeException('FAIL ' . $name); $count++; echo 'PASS ' . $name . PHP_EOL; };
$reject = static function (callable $call, string $name) use ($assert): void { try { $call(); } catch (RuntimeException $e) { $assert(true, $name); return; } $assert(false, $name); };
$catalogPackage = ['type'=>'theme','slug'=>'catalog-test','name'=>'Catalog test','version'=>'0.1.0','installed_version'=>'0.2.0','pending_version'=>'0.2.0','managed_releases'=>true,'active'=>true,'source'=>'package','signature_status'=>'verified','manifest'=>['engine'=>'>=0.1.0 <1.0.0','release_channel'=>'stable']];
$remoteRelease = ['id'=>7,'type'=>'theme','slug'=>'catalog-test','version'=>'0.2.0','release_channel'=>'stable','manifest'=>[]];
$catalog = static fn(array $package, array $remote = [], array $themes = [], array $legacy = []) => App\Core\MarketplaceCatalog::build('0.1.0', $themes, [], [], [$package], $legacy, [], [], $remote);
$item = $catalog($catalogPackage, [$remoteRelease])['items'][0];
$assert($item['installed'] && $item['active'] && $item['version'] === '0.1.0', 'catalog retains the actual active version');
$assert($item['installed_version'] === '0.2.0' && $item['pending_version'] === '0.2.0' && !$item['update_available'], 'already staged release is not advertised as a download');
$assert(!$item['uninstallable'] && $item['configurable'] && $item['compatible'], 'private themes expose supported actions and canonical compatibility');
$assert(count($catalog($catalogPackage, [$remoteRelease])['items']) === 1, 'inactive registry and remote catalog do not duplicate a package');
$remoteRelease['version'] = '0.3.0';
$item = $catalog($catalogPackage, [$remoteRelease])['items'][0];
$assert($item['update_available'] && $item['available_version'] === '0.3.0' && $item['catalog_entry_id'] === 7, 'newer catalog release binds its exact download identity');
$recordedUpdate = $catalogPackage; $recordedUpdate['available_version'] = '0.3.0';
$assert($catalog($recordedUpdate, [$remoteRelease])['items'][0]['catalog_entry_id'] === 7, 'recorded update still binds a matching signed catalog download');
$otherChannel = $remoteRelease; $otherChannel['version'] = '0.4.0'; $otherChannel['release_channel'] = 'beta'; $otherChannel['id'] = 8;
$assert(!$catalog($catalogPackage, [$otherChannel])['items'][0]['update_available'], 'different release channel is not offered as an update');
$older = $remoteRelease; $older['version'] = '0.2.1'; $older['id'] = 9;
$item = $catalog($catalogPackage, [$remoteRelease, $older, $otherChannel])['items'][0];
$assert($item['available_version'] === '0.3.0' && $item['catalog_entry_id'] === 7, 'catalog order cannot replace the selected update with an older or beta download');
$official = $remoteRelease; $official['version'] = '0.5.0'; $official['official_id'] = str_repeat('a', 32);
$item = $catalog($catalogPackage, [$remoteRelease, $official])['items'][0];
$assert($item['official_id'] === $official['official_id'] && $item['catalog_entry_id'] === null, 'official update clears an unrelated catalog download ID');
$stagedPackage = $catalogPackage; $stagedPackage['active'] = false;
$item = $catalog($stagedPackage, [], ['catalog-test'=>['name'=>'Old manifest']], ['catalog-test'=>['active'=>true]])['items'][0];
$assert($item['installed'] && !$item['active'], 'private state overrides a stale legacy installed-theme flag');
$stagedPackage['signature_status'] = 'unverified';
$item = $catalog($stagedPackage)['items'][0];
$assert($item['trust'] === 'unverified' && !$item['compatible'], 'unverified retained archive is not presented as trusted distribution');
$stagedPackage = $catalogPackage; $stagedPackage['manifest']['engine'] = '>=0.2.0 <1.0.0';
$assert(!$catalog($stagedPackage)['items'][0]['compatible'], 'catalog enforces canonical minimum Core range');
$stagedPackage['manifest']['engine'] = '>=0.0.0 <0.1.0';
$assert(!$catalog($stagedPackage)['items'][0]['compatible'], 'catalog enforces canonical exclusive maximum Core range');
foreach ($pages as $path => $page) {
    [$status, $headers, $body] = $theme->response($path);
    $assert($status === 200 && str_contains($body, htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8')), 'page and title ' . $path);
    $assert(str_contains($body, 'rel="canonical" href="https://www.sensecms.com' . $path . '"'), 'canonical ' . $path);
    $assert($theme->response($path, 'HEAD')[2] === '', 'HEAD ' . $path);
    $assert(!preg_match('/href="[^" ]*#[^" ]+"/', str_replace('href="#main"', '', $body)), 'navigation uses real page URLs ' . $path);
    preg_match_all('/(?:href|src)="(\/[^"#]*)(?:#[^"]*)?"/', $body, $links);
    foreach (array_unique($links[1]) as $link) $assert($theme->response($link, 'HEAD')[0] === 200, 'internal link ' . $path . ' -> ' . $link);
}
foreach (['/missing', '/theme-assets/../pages.php', '/theme-assets/pages.php', '/theme-assets/../../.cfg/SSH.txt'] as $path) $assert($theme->response($path)[0] === 404, 'unknown/private path ' . $path);
$assert($theme->response('/', 'POST')[0] === 405, 'read-only public routes');
$assert(!str_contains($theme->response('/download')[2], '.zip'), 'no fake release download');
$fields = json_decode((string) file_get_contents($source . '/theme.json'), true)['configuration'];
$settings = array_column($fields, 'default', 'key');
foreach ($settings as $key => $_value) {
    $body = $theme->response('/', 'GET', ['theme_settings'=>[$key=>'QA-' . $key . '<unsafe>']])[2];
    $assert(str_contains($body, 'QA-' . $key . '&lt;unsafe&gt;') && !str_contains($body, '<unsafe>'), 'editable homepage field escapes text: ' . $key);
}
$assert(!str_contains($theme->response('/', 'GET', ['theme_settings'=>['unknown'=>'UNSUPPORTED']])[2], 'UNSUPPORTED'), 'unknown homepage fields are ignored');
$assert(!str_contains($theme->response('/docs', 'GET', ['theme_settings'=>['home_title'=>'HOME-ONLY']])[2], 'HOME-ONLY'), 'homepage settings do not overwrite documentation');
$descriptor = ['configuration'=>$fields];
$assert(mb_strlen(App\Core\ThemeContract::sanitize($descriptor, ['home_title'=>str_repeat('ą', 100)])['home_title']) === 60, 'homepage title length is Unicode safe');
$reject(fn() => App\Core\ThemeContract::sanitize($descriptor, ['home_title'=>['invalid']]), 'structured theme input rejected without conversion warning');
$assert(str_contains($theme->response('/sitemap.xml')[2], '<loc>https://www.sensecms.com/docs/packages</loc>'), 'sitemap contains docs');
$assert($theme->response('/robots.txt', 'HEAD')[2] === '', 'robots HEAD');
$assert(str_contains($theme->response('/theme-assets/site.css')[1]['Content-Type'], 'text/css'), 'asset MIME');
$assert(str_contains($theme->response('/')[1]['Content-Security-Policy'], "object-src 'none'"), 'public CSP');
foreach (['/', '/about', '/docs/installation'] as $path) $assert(App\Core\PublicPagePath::validate($path) === $path, 'portable public path ' . $path);
$assert(App\Core\PublicPagePath::validate('') === null, 'empty public path keeps localized routing');
foreach (['/login','/api/test','/theme-assets/logo','/pl/page','/../secret','/about/','//host','/page#section','https://example.test','/page?q=1',[]] as $path) $reject(fn() => App\Core\PublicPagePath::validate($path), 'reserved or unsafe public path rejected');
$configured = $theme->response('/platform', 'GET', ['navigation' => [
    'primary' => [['label'=>'<Navigation>', 'url'=>'/platform', 'target'=>'_blank']],
    'footer' => [], 'footer-connect' => [['label'=>'Unsafe URL', 'url'=>'javascript:unsafe()']],
]])[2];
$assert(str_contains($configured, '&lt;Navigation&gt;') && str_contains($configured, 'aria-current="page"'), 'configured menu is escaped and marks current page');
$assert(str_contains($configured, 'rel="noopener noreferrer"') && !str_contains($configured, 'javascript:'), 'configured links protect new windows and remove executable URLs');
$assert(str_contains($configured, '<h2>Explore</h2></div>'), 'intentionally empty navigation stays empty');
$managed = static function (array $page) use ($source): string {
    $locale = 'pl'; $baseUrl = 'https://www.sensecms.com';
    ob_start();
    try { require $source . '/views/page.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
};
$html = $managed(['title' => '<unsafe title>', 'excerpt' => 'Description', 'blocks' => [
    ['type' => 'text', 'payload' => ['title' => 'Heading', 'text' => '<p>Safe body</p><script>unsafe()</script>', 'cta_label' => 'Contact us', 'cta_url' => '/contact']],
    ['type' => 'custom-html', 'payload' => ['html' => '<h2>Custom content</h2><img src="/theme-assets/logo.svg" onerror="unsafe()"><a href="javascript:unsafe()">Link</a>']],
]]);
$assert(str_contains($html, '&lt;unsafe title&gt;') && str_contains($html, 'lang="pl"'), 'managed title escaped and language declared');
$assert(str_contains($html, '<p>Safe body</p>') && str_contains($html, '<h2>Custom content</h2>'), 'text and custom HTML sections render');
$assert(!str_contains($html, 'unsafe()') && !str_contains($html, 'onerror='), 'managed content strips executable HTML and URLs');
$assert(str_contains($html, 'href="/contact">Contact us</a>'), 'text section action renders');
$marketBlocks = [];
foreach (require dirname(__DIR__) . '/.src/package-catalog.php' as $product) {
    $marketBlocks[] = ['type'=>'text','payload'=>['title'=>$product['name'],'text'=>'<p><strong>' . ($product['usd_year']===0?'Free':'USD '.$product['usd_year'].' / year') . '</strong></p><p>'.$product['description'].'</p><p>Not available yet</p>','cta_url'=>'/extensions/catalog/'.$product['type'].'/'.$product['slug'],'cta_label'=>'View package']];
}
$market = $managed(['title'=>'Packages','public_path'=>'/extensions/catalog','blocks'=>$marketBlocks]);
$assert(substr_count($market, 'data-market-item ')===13, 'marketplace presents all CMS products as cards');
$entryMarket=$managed(['title'=>'Marketplace','public_path'=>'/extensions','blocks'=>$marketBlocks]);
$assert(substr_count($entryMarket,'data-market-item ')===13 && str_contains($entryMarket,'marketplace-hero'), 'primary extensions route renders the complete marketplace');
$assert(str_contains($entryMarket,'data-market-category="plugin"') && str_contains($entryMarket,'data-market-price="free"'), 'primary marketplace contains category and price chips');
$assert(substr_count($entryMarket,'data-market-dialog ')===1 && str_contains($entryMarket,'aria-labelledby="market-dialog-title"'), 'marketplace provides one accessible inline details dialog');
$assert(substr_count($entryMarket,'data-market-open href=')===26 && substr_count($entryMarket,'data-market-status hidden')===13, 'all product actions support dialogs with real no-JS detail links');
$assert(str_contains($entryMarket,'disabled>Download unavailable') && str_contains($entryMarket,'data-download-form hidden'), 'download form remains hidden until trusted server availability is loaded');
$assert(substr_count($market, 'data-pricing="free"')===5, 'marketplace retains five free product tiers');
$assert(str_contains($market, 'data-market-filters hidden') && str_contains($market, 'data-market-count'), 'marketplace controls progressively enhance visible server-rendered cards');
$assert(str_contains($market, 'USD 120 / year') && str_contains($market, 'Separate licence'), 'marketplace displays prices and entitlement labels');
$marketBlocks[] = ['type'=>'text','payload'=>['title'=>'Editorial <unsafe>','text'=>'<p>Preserved editorial note</p><script>unsafe()</script>']];
$market = $managed(['title'=>'Packages','public_path'=>'/extensions/catalog/plugin','blocks'=>$marketBlocks]);
$assert(str_contains($market, 'Preserved editorial note') && str_contains($market, 'Editorial &lt;unsafe&gt;') && !str_contains($market,'unsafe()'), 'marketplace preserves non-product editorial blocks safely');
$assert(!str_contains($managed(['title'=>'Details','public_path'=>'/extensions/catalog/addon/calendar','blocks'=>[]]), 'data-marketplace'), 'package detail page does not render a collection');
$platformBlocks = [
    ['type'=>'text', 'payload'=>['title'=>'Overview <unsafe>', 'text'=>'<p>Editable overview</p><script>unsafe()</script>']],
    ['type'=>'custom-html', 'payload'=>['html'=>'<h2>Custom capability</h2><img src="/theme-assets/logo.svg" onerror="unsafe()">']],
    ['type'=>'text', 'payload'=>['title'=>'Additional capability', 'text'=>'<p>Preserved content</p>', 'cta_label'=>'Unsafe link', 'cta_url'=>'javascript:unsafe()']],
];
$platform = $managed(['template'=>'product', 'title'=>'Product <unsafe>', 'excerpt'=>'Editable <intro>', 'blocks'=>$platformBlocks]);
$assert(str_contains($platform, 'class="platform-hero"') && str_contains($platform, 'Product &lt;unsafe&gt;') && str_contains($platform, 'Editable &lt;intro&gt;'), 'product template renders editable escaped hero independently of URL');
foreach (['Overview &lt;unsafe&gt;', 'Editable overview', 'Custom capability', 'Additional capability', 'Preserved content'] as $copy) $assert(substr_count($platform, $copy) === 1, 'product template retains each block once: ' . $copy);
$assert(!str_contains($platform, 'unsafe()') && !str_contains($platform, 'onerror='), 'product template uses shared safe block renderer');
$assert(!str_contains($html, 'class="platform-hero"'), 'default managed template stays unchanged');
$assert(str_contains($managed(['template'=>'product','title'=>'Empty','blocks'=>[]]), 'class="platform-hero"'), 'product template handles an empty block collection');
$assert(str_contains($theme->response('/platform')[2], 'class="platform-hero"'), 'starter platform uses same presentation as managed template');
$assert(str_contains($platform, 'aria-label="Back to top" aria-hidden="true" tabindex="-1"') && str_contains($platform, '<main id="main" tabindex="-1">'), 'return control starts hidden and has an accessible focus destination');
$assert(str_contains($theme->response('/docs')[2], 'data-back-to-top') && str_contains($theme->response('/')[2], 'data-back-to-top'), 'return control is shared by homepage and subpages');
$assert(in_array('product', array_column(json_decode((string) file_get_contents($source . '/theme.json'), true)['page_templates'], 'key'), true), 'platform template is available to the CMS editor');
foreach (['/contact','/docs','/docs/installation','/docs/licensing','/docs/packages','/docs/themes','/docs/server','/download','/extensions','/extensions/modules','/extensions/plugins','/extensions/addons','/extensions/themes'] as $route) {
    $body=$theme->response($route)[2];
    $assert(str_contains($body,'class="subpage-hero ') && substr_count($body,'<h1>')===1,'professional subpage hero: '.$route);
}
$formView = static function (array $extra = [], bool $csrf = true) use ($source): string {
    $locale='en';$formCsrf=str_repeat('a',64);$baseUrl='https://www.sensecms.com';
    $page=['title'=>'Contact','public_path'=>'/contact','blocks'=>[['uid'=>'03000000-0000-4000-a000-000000000001','type'=>'contact-form','shared'=>['captcha'=>true],'payload'=>['title'=>'Your message','fields'=>[['key'=>'name','type'=>'text','label'=>'Your name','required'=>true],['key'=>'email','type'=>'email','label'=>'Email','required'=>true],['key'=>'message','type'=>'textarea','label'=>'Message','required'=>true]]]]]];
    $page['blocks'] = array_merge($page['blocks'], $extra);
    if (!$csrf) unset($formCsrf);
    ob_start();try {require $source.'/views/page.php';return ob_get_contents();}finally{ob_end_clean();}
};
$formHtml=$formView();
$assert(str_contains($formHtml,'data-contact-form') && str_contains($formHtml,'/captcha/forms/03000000-0000-4000-a000-000000000001.png'),'native CAPTCHA contact block renders');
$assert(!str_contains(strtolower($formHtml),'recaptcha') && !str_contains(strtolower($formHtml),'turnstile'),'contact has no external CAPTCHA provider');
$assert(str_contains($formHtml,'name="csrf" value="'.str_repeat('a',64).'"') && str_contains($formHtml,'aria-live="polite"'),'contact CSRF and accessible feedback present');
$contactCopy = [
    ['type'=>'text','payload'=>['title'=>'Managed contact details','text'=>'<p>Editable contact instructions</p>']],
    ['type'=>'custom-html','payload'=>['html'=>'<p>Additional managed information</p><script>unsafe()</script>']],
];
$composed = $formView($contactCopy);
$assert(str_contains($composed, 'contact-composed') && str_contains($composed, 'class="contact-details"'), 'managed contact uses a composed form and details layout');
foreach (['Managed contact details', 'Editable contact instructions', 'Additional managed information'] as $copy) {
    $assert(substr_count($composed, $copy) === 1, 'contact retains managed copy exactly once: ' . $copy);
    $assert(strpos($composed, $copy) > strpos($composed, 'class="contact-details"'), 'managed contact copy is placed in the details column');
}
$assert(!str_contains($composed, 'unsafe()') && substr_count($composed, 'data-contact-form') === 1, 'composed contact keeps shared sanitization and one form');
$fallback = $formView($contactCopy, false);
$assert(!str_contains($fallback, 'contact-composed') && substr_count($fallback, 'Managed contact details') === 1, 'preview without form context preserves content');
$docsHtml = $theme->response('/docs')[2];
$assert(substr_count($docsHtml, 'resource-start') === 1 && substr_count($docsHtml, 'class="section-symbol"') === 5, 'documentation has one featured start and five category icons');
$assert(!str_contains($theme->response('/docs/installation')[2], 'resource-start'), 'guide pages do not inherit the landing featured layout');
$assert(substr_count($theme->response('/extensions')[2], 'class="section-symbol"') === 4, 'extension categories share the icon system');
$heroCss = (string) file_get_contents($source . '/assets/product.css');
$assert(str_contains($heroCss, '.product-hero,.platform-hero,.subpage-hero{overflow:hidden;background:radial-gradient(ellipse at 90% 10%,#2462b590,transparent 65%),#09244d;color:#fff}'), 'all public heroes share the approved contact palette');
$assert(!str_contains($heroCss, '.subpage-contact{') && !str_contains($heroCss, '.subpage-guide .subpage-intro'), 'contact and documentation no longer override shared hero styling');
$assert(str_contains($theme->response('/platform')[2], 'class="subpage-intro"') && str_contains($theme->response('/platform')[2], 'class="subpage-emblem"'), 'platform uses the same heading layout and emblem as other subpages');
$mail=App\Core\FormMail::render(['data'=>['fields'=>[['key'=>'message','label'=>'Message']]]],['message'=>'<script>alert(1)</script>'],'TEST-REFERENCE',true);
$assert(str_contains($mail['html'],'&lt;script&gt;')&&!str_contains($mail['html'],'<script>'),'email template escapes visitor content');
$assert(str_contains($mail['text'],'<script>alert(1)</script>') && str_contains($mail['html'],'TEST-REFERENCE'),'email includes readable text alternative and reference');
$customMail=App\Core\FormMail::render(['data'=>['fields'=>[['key'=>'message','label'=>'Message']]]],['message'=>'The Sense CMS team'],'REFERENCE',true,['name'=>'Independent organisation','email'=>'hello@example.test']);
$assert(str_contains($customMail['html'],'Independent organisation') && str_contains($customMail['html'],'hello@example.test') && !str_contains($customMail['html'],'QUANT') && !str_contains($customMail['html'],'info@SenseCMS.com'),'form mail identity belongs to its installation');
$assert(str_contains($customMail['html'],'The Sense CMS team'),'branding never rewrites submitted message text');
$seo = App\Core\SeoMeta::resolve([], ['title'=>'Title </title><script>unsafe()</script>'], ['title'=>'Page'], ['base_url'=>'https://www.sensecms.com','current_path'=>'/en/example','locale'=>'en']);
$assert($seo['json_ld']['@graph'][0]['@type'] === 'Organization' && $seo['image'] === '', 'general CMS metadata has no educational identity or missing fallback image');
$metadata = static function (array $seo) use ($source): string {
    $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ob_start();
    try { require $source . '/views/metadata.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
};
$head = $metadata($seo);
$assert(!str_contains($head, '<script>unsafe()</script>') && str_contains($head, '&lt;/title&gt;'), 'SEO title cannot inject markup or terminate structured data');

$temp = sys_get_temp_dir() . '/sense-theme-test-' . bin2hex(random_bytes(12));
mkdir($temp, 0700); mkdir($temp . '/source', 0700); mkdir($temp . '/config', 0700);
try {
    copy(dirname(__DIR__) . '/.cms/source/config/product.php', $temp . '/config/product.php');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $temp . '/source/' . substr($file->getPathname(), strlen($source) + 1);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        copy($file->getPathname(), $path);
    }
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    mkdir($temp . '/source/assets/fonts', 0700);
    file_put_contents($temp . '/source/assets/fonts/custom.woff2', 'font-fixture');
    file_put_contents($temp . '/source/assets/private.php', '<?php echo "private";');
    $portable = new PublicTheme($temp . '/source', 'https://www.example.test');
    [$code, $headers, $body] = $portable->response('/theme-assets/fonts/custom.woff2');
    $assert($code === 200 && $headers['Content-Type'] === 'font/woff2' && $body === 'font-fixture', 'custom nested theme assets do not depend on branded filenames');
    $assert($portable->response('/theme-assets/fonts/custom.woff2', 'HEAD')[2] === '', 'custom asset HEAD has no body');
    foreach (['private.php','fonts/../private.php','fonts/%2e%2e/private.php','.hidden.css','fonts\\custom.woff2'] as $asset) $assert($portable->response('/theme-assets/' . $asset)[0] === 404, 'unsafe custom asset rejected ' . $asset);
    unlink($temp . '/source/assets/private.php');
    $keys = ['sensecms-release' => sodium_crypto_sign_publickey_from_secretkey($secret)];
    Archive::build($temp . '/source', $temp . '/v1.zip', $secret);
    $runtime = new Runtime($temp); $manager = new ThemeManager($runtime);
    mkdir($temp . '/themes/sensecms', 0700, true);
    copy($temp . '/source/theme.json', $temp . '/themes/sensecms/theme.json');
    $reject(fn() => $manager->install($temp . '/v1.zip', $keys), 'automatic activation rejects a legacy identity collision');
    unlink($temp . '/themes/sensecms/theme.json'); rmdir($temp . '/themes/sensecms');
    $reject(fn() => $manager->install($temp . '/v1.zip', []), 'unsigned publisher cannot activate');
    $assert($manager->activePath() === null, 'rejected theme leaves no active state');
    $first = $manager->install($temp . '/v1.zip', $keys);
    $sourceVersion = json_decode((string) file_get_contents($source . '/sense-package.json'), true)['version'];
    $assert($first['version'] === $sourceVersion, 'signed theme activates');
    $registry = new App\Core\ManifestRegistry($temp . '/themes', 'theme.json', 'theme', $manager->activePath());
    $registered = $registry->find('sensecms');
    $assert($registered['_view_path'] === $manager->activePath() . '/views/page.php', 'Workspace uses the signed active payload');
    $catalog = App\Core\PageBuilder::catalog($registered);
    $assert(count($catalog) === 3 && isset($catalog['text'], $catalog['custom-html'], $catalog['contact-form']), 'builder exposes only implemented public sections');
    $assert((new PublicTheme($manager->activePath(), 'https://www.sensecms.com'))->response('/')[0] === 200, 'installed payload renders homepage');
    $reject(fn() => $manager->install($temp . '/v1.zip', $keys), 'same version install rejected');
    $reject(fn() => $manager->rollback($keys), 'rollback without history rejected');
    $manifest = json_decode((string) file_get_contents($temp . '/source/sense-package.json'), true, 16, JSON_THROW_ON_ERROR);
    $parts = explode('.', $sourceVersion); $parts[2] = (string) ((int) $parts[2] + 1); $upgradeVersion = implode('.', $parts);
    $manifest['version'] = $upgradeVersion; file_put_contents($temp . '/source/sense-package.json', json_encode($manifest));
    Archive::build($temp . '/source', $temp . '/mismatched.zip', $secret);
    $reject(fn() => $manager->install($temp . '/mismatched.zip', $keys), 'mismatched Workspace identity rejected');
    $assert($runtime->read('theme')['active'] === $first, 'invalid contract preserves active release');
    $descriptor = json_decode((string) file_get_contents($temp . '/source/theme.json'), true, 64, JSON_THROW_ON_ERROR);
    $descriptor['version'] = $upgradeVersion; file_put_contents($temp . '/source/theme.json', json_encode($descriptor));
    Archive::build($temp . '/source', $temp . '/v2.zip', $secret);
    $second = $manager->install($temp . '/v2.zip', $keys);
    $assert($second['version'] === $upgradeVersion && $runtime->read('theme')['previous'] === $first, 'upgrade preserves previous release');
    $reject(fn() => $manager->rollback([]), 'rollback reverifies publisher trust');
    $assert($manager->rollback($keys) === $first, 'rollback restores previous pointer');
    $assert($manager->rollback($keys) === $second, 'rollback can restore newer release');
    $manifest['slug'] = 'sense-alternate'; $descriptor['slug'] = 'sense-alternate';
    file_put_contents($temp . '/source/sense-package.json', json_encode($manifest));
    file_put_contents($temp . '/source/theme.json', json_encode($descriptor));
    Archive::build($temp . '/source', $temp . '/alternate.zip', $secret);
    $alternate = $manager->install($temp . '/alternate.zip', $keys, false);
    $assert($manager->active() === $second, 'staged theme installation does not switch the website');
    $assert(count($manager->releases()) === 3, 'release inventory retains earlier and newly installed presentations');
    $reject(fn() => $manager->activate('../source', $keys), 'activation rejects a directory outside the release inventory');
    $reject(fn() => $manager->activate($alternate['directory'], []), 'activation rechecks current publisher trust');
    $assert($manager->active() === $second, 'rejected activation preserves the active website');
    mkdir($temp . '/themes/sense-alternate', 0700, true);
    copy($temp . '/source/theme.json', $temp . '/themes/sense-alternate/theme.json');
    $reject(fn() => $manager->activate($alternate['directory'], $keys), 'explicit activation rejects a legacy identity collision');
    unlink($temp . '/themes/sense-alternate/theme.json'); rmdir($temp . '/themes/sense-alternate');
    $assert($manager->activate($alternate['directory'], $keys) === $alternate, 'exact signed alternate release activates');
    $assert(str_contains($manager->activePath(), $alternate['directory']), 'public renderer resolves the selected release');
    $assert($manager->activate($alternate['directory'], $keys) === $alternate, 'same-release activation is idempotent');
    $assert($manager->rollback($keys) === $second, 'rollback restores presentation across different theme slugs');
    $manager->activate($first['directory'], $keys); $manager->activate($second['directory'], $keys);
    $originalState = $runtime->read('theme');
    $invalidState = $originalState; $invalidState['releases'][$alternate['directory']]['slug'] = 'forged';
    $runtime->write('theme', $invalidState);
    $reject(fn() => $manager->activate($alternate['directory'], $keys), 'release inventory identity cannot be forged');
    $runtime->write('theme', $originalState);
    $oldFile = $temp . '/storage/themes/' . $first['directory'] . '/payload/pages.php';
    file_put_contents($oldFile, '<?php return [];');
    $reject(fn() => $manager->rollback($keys), 'tampered rollback payload rejected');
    $assert($runtime->read('theme')['active'] === $second, 'failed rollback preserves current website');
    $assert(glob($temp . '/storage/themes/stage-*') === [], 'unpublished staging directories cleaned');
    $runtime->write('theme', ['active' => ['directory' => '../source']]);
    $reject(fn() => $manager->activePath(), 'state path traversal rejected');
    sodium_memzero($secret);
    echo "\n$count theme and website checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temp);
}
