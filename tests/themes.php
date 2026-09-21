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
$emailLogo='/theme-assets/sensecms/images/sensecms-logo-email.png';
[$logoStatus,$logoHeaders,$logoBody]=$theme->response($emailLogo);
$assert($logoStatus===200&&$logoHeaders['Content-Type']==='image/png','Default email logo is bundled in the product theme');
$assert($logoBody===file_get_contents($source.'/assets/sensecms/images/sensecms-logo-email.png')&&getimagesizefromstring($logoBody)['mime']==='image/png','Email logo route returns the exact valid PNG');
[$headStatus,$headHeaders,$headBody]=$theme->response($emailLogo,'HEAD');
$assert($headStatus===200&&$headBody===''&&(int)$headHeaders['Content-Length']===strlen($logoBody),'Email logo HEAD has correct length and no body');
$socialImage='/theme-assets/og.image.jpg';[$socialStatus,$socialHeaders,$socialBody]=$theme->response($socialImage);$socialSize=getimagesizefromstring($socialBody);
$assert($socialStatus===200&&$socialHeaders['Content-Type']==='image/jpeg'&&$socialSize['mime']==='image/jpeg'&&$socialSize[0]===1200&&$socialSize[1]===630,'Default Open Graph image is an exact 1200 by 630 JPEG');
$assert($theme->response('/theme-assets/logo-light.svg')[0]===200&&$theme->response('/theme-assets/logo-light.svg')[1]['Content-Type']==='image/svg+xml','Light footer logo is a public theme asset');
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
    foreach (array_unique($links[1]) as $link) {
        if($link==='/system/update'){$assert($theme->response($link,'HEAD')[0]===404,'Core Update link is not owned by the public theme');continue;}
        $assert($theme->response((string)parse_url($link,PHP_URL_PATH), 'HEAD')[0] === 200, 'internal link ' . $path . ' -> ' . $link);
    }
}
foreach (['/missing', '/theme-assets/../pages.php', '/theme-assets/pages.php', '/theme-assets/../../.cfg/SSH.txt'] as $path) $assert($theme->response($path)[0] === 404, 'unknown/private path ' . $path);
$assert($theme->response('/', 'POST')[0] === 405, 'read-only public routes');
$assert(!str_contains($theme->response('/download')[2], '.zip'), 'no fake release download');
$fields = json_decode((string) file_get_contents($source . '/theme.json'), true)['configuration'];
$settings = array_column($fields, 'default', 'key');
foreach ($settings as $key => $_value) {
    $field = array_values(array_filter($fields, static fn(array $candidate): bool => $candidate['key'] === $key))[0];
    if ($field['type'] === 'select') continue;
    $value = str_ends_with($key, '_url') ? '/qa-' . str_replace('_', '-', $key) : 'QA-' . $key . '<unsafe>';
    $input = [$key=>$value];
    if ($key === 'footer_right_text') $input['footer_right_type'] = 'text';
    $body = $theme->response('/', 'GET', ['theme_settings'=>$input])[2];
    $expected = str_ends_with($key, '_url') ? $value : 'QA-' . $key . '&lt;unsafe&gt;';
    $assert(str_contains($body, $expected) && !str_contains($body, '<unsafe>'), 'editable homepage field escapes text: ' . $key);
}
$defaultFooter = $theme->response('/')[2];
$assert(str_contains($defaultFooter, '© Copyright by Sense CMS · QUANT Software House Limited. All rights reserved.') && !str_contains($defaultFooter, 'Independent by design.'), 'product footer uses the requested copyright text');
$assert(str_contains($defaultFooter,'src="/theme-assets/logo-light.svg"')&&str_contains($defaultFooter,'Sense CMS is a flexible, self-hosted content management system')&&str_contains($footerCss=(string)file_get_contents($source.'/assets/site.css'),'.footer-brand>p{max-width:390px;text-align:justify'),'footer logo, brand and justified SEO description use configurable theme fields');
$wordmark='Sense<span class="brand-light">CMS</span><span class="brand-dot">.</span>';
$assert(substr_count($defaultFooter,$wordmark)===2&&str_contains($footerCss,'.footer .brand,.footer .brand-dot{color:#fff}'),'header and footer share one SenseCMS wordmark structure while the footer stays white');
$assert(str_contains($defaultFooter, 'href="/privacy-policy">Privacy Policy</a>') && str_contains($defaultFooter, 'href="/terms-and-conditions">Terms &amp; Conditions</a>') && str_contains($defaultFooter, 'href="/cookies">Cookies</a>'), 'product footer links every legal page');
$privacy = $theme->response('/privacy-policy')[2];
$terms = $theme->response('/terms-and-conditions')[2];
$cookies = $theme->response('/cookies')[2];
$assert(str_contains($privacy, 'company number 16902352') && str_contains($privacy, '71-75 Shelton Street'), 'privacy policy identifies the UK controller');
foreach (['Facebook Pages', 'X', 'LinkedIn', 'Bluesky', 'Mastodon', 'Telegram Channels', 'TikTok', 'YouTube'] as $provider) $assert(str_contains($privacy, $provider), 'privacy policy covers active integration ' . $provider);
foreach (['Instagram', 'Threads', 'Pinterest'] as $provider) $assert(str_contains($privacy, $provider), 'privacy policy covers pending integration ' . $provider);
$assert(str_contains($privacy, 'youtube.upload') && str_contains($privacy, 'youtube.readonly') && str_contains($privacy, 'currently authenticated channel') && str_contains($privacy, 'Google API Services User Data Policy') && str_contains($privacy, 'does not request email, contacts, comments, subscribers or analytics'), 'privacy policy documents minimum YouTube data use and Limited Use compliance');
$assert(str_contains($terms, 'Social publishing is review-first') && str_contains($terms, 'Development Preview integrations') && str_contains($terms, 'remain subject to platform access or production enablement'), 'terms distinguish reviewed delivery, preview and pending integrations');
$assert(str_contains($cookies, 'sensecms_session') && str_contains($cookies, 'sensecms:analytics:') && str_contains($cookies, 'sensega_ga'), 'cookie notice documents necessary storage and consent-gated analytics');
$footerCss = (string) file_get_contents($source . '/assets/site.css');
$assert(str_contains($footerCss, '.footer a:not(.brand){color:#b9c9df;font-size:14px;padding:7px 0;text-decoration:none}') && str_contains($footerCss, '.footer a:not(.brand):is(:hover,:focus-visible){color:#fff;text-decoration:underline') && !str_contains($footerCss, '.footer-legal a{'), 'all footer text links share one style and underline only on interaction');
$assert(str_contains($defaultFooter, '<nav id="navigation" class="primary-nav"') && str_contains($defaultFooter, 'class="language-menu"') && str_contains($defaultFooter, '>EN</span>'), 'product header exposes consistent navigation and language controls');
$assert(str_contains($defaultFooter, 'class="header-demo" href="https://demo.sensecms.com/" target="_blank" rel="noopener noreferrer">Demo') && !str_contains($defaultFooter, '>Get Sense CMS <'), 'product header replaces the old action with a safe external Demo link');
$assert(str_contains($footerCss, '.primary-nav>a::after{content:"";position:absolute;right:50%;bottom:-12px;left:50%;height:2px') && str_contains($footerCss, '.primary-nav>a:is(:hover,:focus-visible,[aria-current],.nav-parent)::after{right:0;left:0}'), 'primary navigation uses one expanding underline for hover, focus and current page');
$assert(str_contains($defaultFooter, 'aria-controls="accessibility-panel" aria-haspopup="dialog" data-accessibility-toggle') && str_contains($defaultFooter, 'id="accessibility-scale" type="range" min="100" max="200" step="10"'), 'header exposes the accessible text-size dialog and bounded range');
$siteJs = (string) file_get_contents($source . '/assets/site.js');
$assert(str_contains($siteJs, "const textScaleKey = 'sensecms:text-scale';") && str_contains($siteJs, "Math.min(200, Math.max(100") && str_contains($siteJs, "localStorage.removeItem(textScaleKey)"), 'text-size preference is bounded and stored locally only when changed');
$assert(str_contains($siteJs,"siteHeader.classList.toggle('is-compact'")&&str_contains($footerCss,'.header.is-compact .header-inner{height:66px}')&&str_contains($footerCss,'.footer:before{'), 'scrolling compacts the sticky header and the footer has a subtle shared separator');
$productCss = (string) file_get_contents($source . '/assets/product.css');
$assert(str_contains($footerCss, '.primary-nav{display:flex;align-items:center;justify-content:flex-end') && str_contains($footerCss, '.brand{display:flex;gap:10px;align-items:center;font-size:26px') && str_contains($productCss, '.product-design .brand{font-size:26px') && str_contains($footerCss, '.footer-bottom{') && str_contains($footerCss, 'font-size:12px'), 'header navigation aligns with controls while header and footer typography stay fixed during content scaling');
$assert(str_contains($siteJs, "toggleAttribute('data-text-scale-large'") && !str_contains($siteJs, 'data-text-scale-menu'), 'large text marks content wrapping without switching the desktop header');
$textFooter = $theme->response('/', 'GET', ['theme_settings'=>['footer_right_type'=>'text','footer_right_text'=>'Custom legal note <safe>']])[2];
$assert(str_contains($textFooter, 'Custom legal note &lt;safe&gt;') && !str_contains($textFooter, '<nav class="footer-legal"'), 'footer right side switches safely from links to text');
$unsafeFooter = $theme->response('/', 'GET', ['theme_settings'=>['footer_link_1_url'=>'javascript:alert(1)']])[2];
$assert(!str_contains($unsafeFooter, 'javascript:') && !str_contains($unsafeFooter, '>Privacy Policy</a>'), 'unsafe configurable footer destination is omitted');
$assert(!str_contains($theme->response('/', 'GET', ['theme_settings'=>['unknown'=>'UNSUPPORTED']])[2], 'UNSUPPORTED'), 'unknown homepage fields are ignored');
$assert(!str_contains($theme->response('/docs', 'GET', ['theme_settings'=>['home_title'=>'HOME-ONLY']])[2], 'HOME-ONLY'), 'homepage settings do not overwrite documentation');
$descriptor = ['configuration'=>$fields];
$assert(mb_strlen(App\Core\ThemeContract::sanitize($descriptor, ['home_title'=>str_repeat('ą', 100)])['home_title']) === 60, 'homepage title length is Unicode safe');
$reject(fn() => App\Core\ThemeContract::sanitize($descriptor, ['home_title'=>['invalid']]), 'structured theme input rejected without conversion warning');
$sitemap=$theme->response('/sitemap.xml')[2];
$assert(str_contains($sitemap, '<loc>https://www.sensecms.com/docs/packages</loc>')&&str_contains($sitemap,'<lastmod>'),'sitemap contains docs with an explicit content-change date');
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
$managed = static function (array $page, ?array $seo=null) use ($source): string {
    $locale = 'pl'; $baseUrl = 'https://www.sensecms.com';
    $requestUri=$_SERVER['REQUEST_URI']??null;$_SERVER['REQUEST_URI']=(string)($page['public_path']??'/');
    ob_start();
    try { require $source . '/views/page.php'; return (string) ob_get_contents(); }
    finally { ob_end_clean();if($requestUri===null)unset($_SERVER['REQUEST_URI']);else $_SERVER['REQUEST_URI']=$requestUri; }
};
$html = $managed(['title' => '<unsafe title>', 'excerpt' => 'Description', 'blocks' => [
    ['type' => 'text', 'payload' => ['title' => 'Heading', 'text' => '<p>Safe body</p><script>unsafe()</script>', 'cta_label' => 'Contact us', 'cta_url' => '/contact']],
    ['type' => 'custom-html', 'payload' => ['html' => '<h2>Custom content</h2><img src="/theme-assets/logo.svg" onerror="unsafe()"><a href="javascript:unsafe()">Link</a>']],
]]);
$assert(str_contains($html, '&lt;unsafe title&gt;') && str_contains($html, 'lang="pl"'), 'managed title escaped and language declared');
$assert(str_contains($html, '<p>Safe body</p>') && str_contains($html, '<h2>Custom content</h2>'), 'text and custom HTML sections render');
$assert(!str_contains($html, 'unsafe()') && !str_contains($html, 'onerror='), 'managed content strips executable HTML and URLs');
$assert(str_contains($html, 'href="/contact">Contact us</a>'), 'text section action renders');
$coreBlocks = [
    ['type'=>'hero','payload'=>['media_type'=>'image','image'=>'/theme-assets/logo.svg','title'=>'Core hero']],
    ['type'=>'hero-slider','shared'=>['arrows'=>true,'dots'=>true],'payload'=>['slides'=>[['media_type'=>'image','image'=>'/theme-assets/logo.svg','title'=>'Core slide']]]],
    ['type'=>'gallery','payload'=>['title'=>'Core gallery','items'=>[['image'=>'/theme-assets/logo.svg','title'=>'Gallery item']]]],
    ['type'=>'admissions','payload'=>['title'=>'Core steps','steps'=>[['title'=>'First step']]]],
    ['uid'=>'12345678-1234-4234-8234-123456789abc','type'=>'story','layout'=>['group'=>'22345678-1234-4234-8234-123456789abc','mode'=>'columns','container'=>'wide','gap'=>'small','desktop'=>['span'=>4]],'payload'=>['title'=>'Core story','statement'=>'Story statement']],
    ['uid'=>'32345678-1234-4234-8234-123456789abc','type'=>'values','layout'=>['group'=>'22345678-1234-4234-8234-123456789abc','mode'=>'columns','container'=>'wide','gap'=>'small','desktop'=>['span'=>8]],'payload'=>['title'=>'Core values','items'=>[['title'=>'Value','text'=>'Description']]]],
    ['type'=>'programs','payload'=>['title'=>'Core services','items'=>[['title'=>'Service','text'=>'Description']]]],
    ['type'=>'statistics','payload'=>['title'=>'Core highlights','items'=>[['value'=>'15','label'=>'Sections']]]],
    ['type'=>'motion','payload'=>['title'=>'Core video','video'=>'/media/core.mp4']],
    ['type'=>'news','payload'=>['title'=>'Core stories']],
    ['type'=>'cta','payload'=>['title'=>'Core action','panel_title'=>'Next step']],
    ['type'=>'image-text','payload'=>['title'=>'Core image text','image'=>'/theme-assets/logo.svg']],
    ['type'=>'separator','shared'=>['style'=>'dashed','weight'=>2,'spacing'=>32],'payload'=>[]],
    ['type'=>'spacer','shared'=>['desktop'=>80,'tablet'=>60,'mobile'=>40],'payload'=>[]],
];
$coreHtml=$managed(['title'=>'Core standard','blocks'=>$coreBlocks]);
foreach(['hero','slider','gallery','steps','story','values','services','statistics','motion','news','cta','image-text','separator','spacer']as$marker)$assert(str_contains($coreHtml,'sense-block-'.$marker),'official theme renders Core section '.$marker);
$assert(str_contains($coreHtml,'sense-layout-columns sense-layout-wide sense-layout-gap-small')&&str_contains($coreHtml,'--sense-span-desktop:4')&&str_contains($coreHtml,'--sense-span-desktop:8'),'official theme renders controlled responsive layout groups');
$assert(str_contains((string)file_get_contents($source.'/assets/site.js'),'[data-core-slider]')&&str_contains((string)file_get_contents($source.'/assets/site.css'),'.sense-block-slider'),'Core slider has accessible behaviour and responsive presentation');
$marketBlocks = [];
foreach (require dirname(__DIR__) . '/.src/package-catalog.php' as $product) {
    $marketBlocks[] = ['type'=>'text','payload'=>['title'=>$product['name'],'text'=>'<p><strong>' . ($product['usd_year']===0?'Free':'USD '.$product['usd_year'].' / year') . '</strong></p><p>'.$product['description'].'</p><p>Not available yet</p>','cta_url'=>'/extensions/catalog/'.$product['type'].'/'.$product['slug'],'cta_label'=>'View package']];
}
$market = $managed(['title'=>'Packages','public_path'=>'/extensions/catalog','blocks'=>$marketBlocks]);
$assert(substr_count($market, 'data-market-item ')===22, 'marketplace presents all CMS products as cards');
$entryMarket=$managed(['title'=>'Marketplace','public_path'=>'/extensions','blocks'=>$marketBlocks]);
$assert(substr_count($entryMarket,'data-market-item ')===22 && str_contains($entryMarket,'marketplace-hero'), 'primary extensions route renders the complete marketplace');
$assert(str_contains($entryMarket,'data-market-category="plugin"') && str_contains($entryMarket,'data-market-price="free"'), 'primary marketplace contains category and price chips');
$assert(substr_count($entryMarket,'data-market-dialog ')===1 && str_contains($entryMarket,'aria-labelledby="market-dialog-title"'), 'marketplace provides one accessible inline details dialog');
$assert(substr_count($entryMarket,'data-market-open href=')===44 && substr_count($entryMarket,'data-market-status hidden')===22, 'all product actions support dialogs with real no-JS detail links');
$assert(str_contains($entryMarket,'disabled>Download unavailable') && str_contains($entryMarket,'data-download-form hidden'), 'download form remains hidden until trusted server availability is loaded');
$assert(substr_count($market, 'data-pricing="free"')===8, 'marketplace retains eight free product tiers');
$catalogProducts=require dirname(__DIR__) . '/.src/package-catalog.php';$bluesky=array_values(array_filter($catalogProducts,static fn(array$product):bool=>($product['slug']??'')==='bluesky-publisher'))[0]??[];$assert(($bluesky['usd_year']??null)===15&&($bluesky['license']['product_name']??'')==='Sense CMS Bluesky Publisher Plugin'&&($bluesky['license']['product_model']??'')==='Bluesky Publisher Plugin','Bluesky marketplace entry keeps separate paid licence identity');
$mastodon=array_values(array_filter($catalogProducts,static fn(array$product):bool=>($product['slug']??'')==='mastodon-publisher'))[0]??[];$assert(($mastodon['usd_year']??null)===15&&($mastodon['license']['product_name']??'')==='Sense CMS Mastodon Publisher Plugin'&&($mastodon['license']['product_model']??'')==='Mastodon Publisher Plugin','Mastodon marketplace entry keeps separate paid licence identity');
$telegramChannels=array_values(array_filter($catalogProducts,static fn(array$product):bool=>($product['slug']??'')==='telegram-channels-publisher'))[0]??[];$assert(($telegramChannels['usd_year']??null)===15&&($telegramChannels['license']['product_name']??'')==='Sense CMS Telegram Channels Plugin'&&($telegramChannels['license']['product_model']??'')==='Telegram Channels Plugin','Telegram Channels marketplace entry keeps separate paid licence identity');
$pinterest=array_values(array_filter($catalogProducts,static fn(array$product):bool=>($product['slug']??'')==='pinterest-publisher'))[0]??[];$assert(($pinterest['usd_year']??null)===15&&($pinterest['license']['product_name']??'')==='Sense CMS Pinterest Publisher Plugin'&&($pinterest['license']['product_model']??'')==='Pinterest Publisher Plugin','Pinterest marketplace entry keeps separate paid licence identity');
$tiktok=array_values(array_filter($catalogProducts,static fn(array$product):bool=>($product['slug']??'')==='tiktok-publisher'))[0]??[];$assert(($tiktok['usd_year']??null)===15&&($tiktok['license']['product_name']??'')==='Sense CMS TikTok Publisher Plugin'&&($tiktok['license']['product_model']??'')==='TikTok Publisher Plugin','TikTok marketplace entry keeps separate paid licence identity');
$youtube=array_values(array_filter($catalogProducts,static fn(array$product):bool=>($product['slug']??'')==='youtube-publisher'))[0]??[];$assert(($youtube['usd_year']??null)===15&&($youtube['license']['product_name']??'')==='Sense CMS YouTube Publisher Plugin'&&($youtube['license']['product_model']??'')==='YouTube Publisher Plugin','YouTube marketplace entry keeps separate paid licence identity');
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
$seoFixture=App\Core\SeoMeta::resolve([],[],['title'=>'Installation','excerpt'=>'Install Sense CMS'],['base_url'=>'https://www.sensecms.com','current_path'=>'/docs/installation','locale'=>'en','fallback_image'=>'/theme-assets/og.image.jpg','fallback_image_type'=>'image/jpeg','fallback_image_width'=>1200,'fallback_image_height'=>630]);
$seoPage=$managed(['title'=>'Installation','excerpt'=>'Install Sense CMS','public_path'=>'/docs/installation','blocks'=>[]],$seoFixture);
$assert(str_contains($seoPage,'"@type":"BreadcrumbList"')&&str_contains($seoPage,'"name":"Documentation"')&&str_contains($seoPage,'property="og:image:width" content="1200"'),'visible breadcrumb trail matches structured data and complete default Open Graph metadata');
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
$pageEditor=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Views/console-content-page-form.php');$pageController=(string)file_get_contents(dirname(__DIR__).'/.cms/source/app/Http/DashboardController.php');
$assert(str_contains($pageEditor,'data-seo-image-upload')&&str_contains($pageEditor,'Open Graph / social image')&&str_contains($pageController,"'og_image_width','og_image_height','og_image_type'"),'page editor uploads localized Open Graph images through the existing media pipeline');
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
    $assert(count($catalog) === 17 && array_keys($catalog) === App\Core\PageBuilder::systemTypes(), 'builder exposes the complete portable Core section standard');
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
