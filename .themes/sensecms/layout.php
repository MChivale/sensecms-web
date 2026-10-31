<?php
declare(strict_types=1);
$tr = static fn(string $en, string $pl): string => ($locale ?? 'en') === 'pl' ? $pl : $en;
$locale = $locale ?? 'en';
$localizedPaths = $localizedPaths ?? [];
$localUrl = static function (string $url) use ($locale, $localizedPaths): string {
    if (($locale ?? 'en') === 'en' || !str_starts_with($url, '/') || str_starts_with($url, '//')) return $url;
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!isset($localizedPaths[$path])) return $url;
    return '/' . rawurlencode((string)$locale) . '/' . rawurlencode((string)$localizedPaths[$path]) . substr($url, strlen($path));
};
if (($locale ?? 'en') === 'pl') {
    foreach (['home_title','home_accent','home_intro','home_intro_end','home_detail','features_title','features_intro','ecosystem_title','ecosystem_text','ownership_title','ownership_text','closing_title','closing_text','footer_description'] as $key) {
        if (!empty($themeSettings[$key . '_pl'])) $themeSettings[$key] = $themeSettings[$key . '_pl'];
    }
    if (($themeSettings['footer_copyright_text'] ?? '') === '© Copyright by Sense CMS · QUANT Software House Limited. All rights reserved.') $themeSettings['footer_copyright_text'] = '© Sense CMS · QUANT Software House Limited. Wszelkie prawa zastrzeżone.';
}
$fallbackNav = [
    ['label'=>'Platform', 'url'=>'/platform'],
    ['label'=>'AI', 'url'=>'/platform/ai'],
    ['label'=>'Extensions', 'url'=>'/extensions'],
    ['label'=>'Licensing', 'url'=>'/docs/licensing'],
    ['label'=>'Documentation', 'url'=>'/docs'],
    ['label'=>'Contact', 'url'=>'/contact'],
];
if (!isset($blocks) && isset($page['sections'])) {
    $blocks = array_map(static fn(array $section): array => ['type'=>'text', 'payload'=>[
        'title'=>$section[0], 'text'=>'<p>' . $e($section[1]) . '</p>' . (isset($section[2]) ? '<pre><code>' . $e($section[2]) . '</code></pre>' : ''), 'cta_label'=>$section[3]??'', 'cta_url'=>$section[4]??'',
    ]], $page['sections']);
    if ($page['kind'] !== 'home') $page['kind'] = $page['kind'] === 'platform' ? 'product' : 'managed';
}
if (($page['kind'] ?? '') === 'home') {
    $page['title'] = $themeSettings['home_seo_title'] ?? $page['title'];
    $page['description'] = $themeSettings['home_seo_description'] ?? $page['description'];
}
$safeUrl = static fn(string $url): bool => (str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\s<>"\'\\\\]/u', $url))
    || (bool) preg_match('/^(?:mailto|tel):[^\s<>]+$/iD', $url)
    || ((bool) filter_var($url, FILTER_VALIDATE_URL) && (bool) preg_match('/^https:\/\//iD', $url));
$canonicalPath = (string) ($page['public_path'] ?? $path);
$renderLinks = static function (array $links) use ($e, $path, $canonicalPath, $safeUrl, $localUrl): void {
    $active = null;
    $score = 0;
    $exact = false;
    foreach ($links as $index => $link) {
        $url = trim((string) ($link['url'] ?? ''));
        if (!$safeUrl($url) || !str_starts_with($url, '/') || str_starts_with($url, '//')) continue;
        $route = (string) parse_url($url, PHP_URL_PATH);
        $matches = $localUrl($route) === $path || $route === $canonicalPath;
        $rank = $matches ? PHP_INT_MAX : ($route !== '/' && str_starts_with($canonicalPath, $route . '/') ? strlen($route) : 0);
        if ($rank > $score) { $active = $index; $score = $rank; $exact = $matches; }
    }
    foreach ($links as $index => $link) {
        $url = $localUrl(trim((string) ($link['url'] ?? '')));
        if (!$safeUrl($url)) continue;
        $target = ($link['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $current = $index === $active ? ($exact ? ' aria-current="page"' : ' class="nav-parent"') : '';
        echo '<a href="' . $e($url) . '"' . $target . $current . '>' . $e($link['label'] ?? '') . '</a>';
    }
};
$primaryLinks = isset($navigation['primary']) ? array_values(array_filter($navigation['primary'], static fn(array $link): bool => !(
    ($link['url'] ?? '') === '/download' && strcasecmp(trim((string) ($link['label'] ?? '')), 'Get Sense CMS') === 0
))) : $fallbackNav;
$footerLinks = [];
for ($i = 1; $i <= 3; $i++) {
    $label = trim((string) ($themeSettings['footer_link_' . $i . '_label'] ?? ''));
    $url = trim((string) ($themeSettings['footer_link_' . $i . '_url'] ?? ''));
    if ($locale === 'pl') $label = match ($label) {'Privacy Policy'=>'Polityka prywatności','Terms & Conditions'=>'Regulamin','Cookies'=>'Pliki cookies',default=>$label};
    if ($label !== '' && $safeUrl($url)) $footerLinks[] = ['label'=>$label, 'url'=>$url];
}
$footerLogo = trim((string) ($themeSettings['footer_logo_url'] ?? '/theme-assets/logo-light.svg'));
if (!preg_match('#^/[A-Za-z0-9_./%+-]+$#D', $footerLogo) || str_starts_with($footerLogo, '//')) $footerLogo = '/theme-assets/logo-light.svg';
$footerBrand = trim((string) ($themeSettings['footer_brand_text'] ?? 'SenseCMS.')) ?: 'SenseCMS.';
$footerDescription = trim((string) ($themeSettings['footer_description'] ?? 'Sense CMS is a flexible, self-hosted content management system for building multilingual websites, managing content and connecting editorial teams. Its modular platform brings pages, media, workflows and social publishing together while keeping organizations in control of their infrastructure and digital presence.'));
$renderBrandText = static function (string $text) use ($e): void {
    if (preg_match('/^Sense\s*CMS\.?$/iD', $text)) { echo 'Sense<span class="brand-light">CMS</span><span class="brand-dot">.</span>'; return; }
    echo $e($text);
};
$activeLocale = preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})?$/iD', (string) ($locale ?? 'en')) ? strtolower((string) ($locale ?? 'en')) : 'en';
$renderLanguageFlag = static function (string $code): void {
    $flag = match ($code) { 'en'=>'uk', 'pl'=>'pl', default=>null };
    if ($flag !== null) echo '<img class="language-flag" src="/theme-assets/flag-' . $flag . '.svg" width="18" height="12" alt="">';
};
$availableLanguages = isset($languages) && is_array($languages) ? $languages : [['locale'=>$activeLocale, 'name'=>strtoupper($activeLocale), 'native_name'=>strtoupper($activeLocale), 'is_default'=>true]];
$availableLanguageUrls = isset($languageUrls) && is_array($languageUrls) ? $languageUrls : [$activeLocale=>$path];
$languageLinks = [];
$defaultLocale = $activeLocale;
foreach ($availableLanguages as $language) {
    if (!is_array($language) || (array_key_exists('enabled', $language) && !$language['enabled'])) continue;
    $code = strtolower(trim((string) ($language['locale'] ?? '')));
    $url = trim((string) ($availableLanguageUrls[$code] ?? ''));
    if (!preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{2,8})?$/iD', $code) || !$safeUrl($url)) continue;
    if (!empty($language['is_default'])) $defaultLocale = $code;
    $languageLinks[] = ['code'=>$code, 'url'=>$url, 'native'=>(string) ($language['native_name'] ?? $language['name'] ?? strtoupper($code)), 'name'=>(string) ($language['name'] ?? strtoupper($code))];
}
if (!$languageLinks) $languageLinks[] = ['code'=>$activeLocale, 'url'=>$path, 'native'=>strtoupper($activeLocale), 'name'=>strtoupper($activeLocale)];
$homeUrl = $activeLocale === $defaultLocale ? '/' : '/' . rawurlencode($activeLocale) . '/home';
$currentLabel = match ($path) {
    '/docs' => 'Documentation', '/docs/installation' => 'Installation', '/docs/licensing' => 'Licensing', '/docs/packages' => 'Package contract', '/docs/themes' => 'Theme development', '/docs/server' => 'Server configuration',
    '/extensions' => 'Extensions', '/extensions/catalog' => 'Package catalogue', '/extensions/modules', '/extensions/catalog/module' => 'Modules', '/extensions/plugins', '/extensions/catalog/plugin' => 'Plugins', '/extensions/addons', '/extensions/catalog/addon' => 'Addons', '/extensions/themes', '/extensions/catalog/theme' => 'Themes',
    '/download', '/update' => 'Release status', '/contact' => 'Contact', '/platform' => 'Platform',
    default => (string) ($page['title'] ?? 'Page'),
};
$breadcrumbPath = (string)($page['public_path'] ?? $path);
$breadcrumbItems = $breadcrumbPath === '/' ? [] : [['label'=>$tr('Home','Strona główna'),'url'=>$homeUrl]];
if (str_starts_with($breadcrumbPath, '/docs/') && $breadcrumbPath !== '/docs') $breadcrumbItems[] = ['label'=>$tr('Documentation','Dokumentacja'),'url'=>$localUrl('/docs')];
if (str_starts_with($breadcrumbPath, '/extensions/') && $breadcrumbPath !== '/extensions') $breadcrumbItems[] = ['label'=>$tr('Extensions','Rozszerzenia'),'url'=>$localUrl('/extensions')];
if ($locale === 'pl') $currentLabel = (string)($page['title'] ?? $currentLabel);
if ($breadcrumbItems) $breadcrumbItems[] = ['label'=>$currentLabel,'url'=>''];
$renderBreadcrumb = static function () use ($breadcrumbItems, $e, $tr): void {
    if (!$breadcrumbItems) return;
    echo '<nav class="subpage-breadcrumb" aria-label="' . $e($tr('Breadcrumb','Ścieżka nawigacji')) . '">';
    foreach ($breadcrumbItems as $index => $item) {
        if ($index) echo '<span aria-hidden="true">/</span>';
        echo $item['url'] !== '' ? '<a href="' . $e($item['url']) . '">' . $e($item['label']) . '</a>' : '<span aria-current="page">' . $e($item['label']) . '</span>';
    }
    echo '</nav>';
};
if (isset($seo) && $breadcrumbItems) {
    $breadcrumbId = rtrim((string) ($seo['canonical'] ?? ''), '/') . '/#breadcrumb';
    $items = [];
    foreach ($breadcrumbItems as $position => $item) $items[] = ['@type'=>'ListItem','position'=>$position + 1,'name'=>$item['label'],'item'=>$item['url'] !== '' ? rtrim((string) ($seo['site_url'] ?? $baseUrl), '/') . $item['url'] : (string) ($seo['canonical'] ?? '')];
    $seo['json_ld']['@graph'][] = ['@type'=>'BreadcrumbList','@id'=>$breadcrumbId,'itemListElement'=>$items];
    foreach ($seo['json_ld']['@graph'] as &$node) if (($node['@id'] ?? '') === ($seo['canonical'] ?? '') . '#webpage') $node['breadcrumb'] = ['@id'=>$breadcrumbId];
    unset($node);
}
if (isset($seo) && in_array($canonicalPath, ['/', '/platform', '/extensions/catalog/system/sensecms'], true)) {
    $siteUrl = rtrim((string) $seo['site_url'], '/');
    $softwareId = $siteUrl . '/#software';
    $seo['json_ld']['@graph'][] = ['@type'=>'SoftwareApplication','@id'=>$softwareId,'name'=>'Sense CMS','url'=>$siteUrl . '/platform','description'=>$seo['description'],'applicationCategory'=>'BusinessApplication','operatingSystem'=>'Web browser; PHP server','inLanguage'=>$locale,'publisher'=>['@id'=>$siteUrl . '/#organization'],'license'=>$siteUrl . $localUrl('/docs/licensing'),'softwareHelp'=>['@type'=>'WebPage','url'=>$siteUrl . $localUrl('/docs')]];
    foreach ($seo['json_ld']['@graph'] as &$node) if (($node['@id'] ?? '') === $seo['canonical'] . '#webpage') $node['mainEntity'] = ['@id'=>$softwareId];
    unset($node);
}
?>
<!doctype html>
<html lang="<?= $e($locale ?? 'en') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php if (isset($seo)): require __DIR__ . '/views/metadata.php'; else: ?>
<title><?= $e($page['title']) ?> · Sense CMS</title><meta name="description" content="<?= $e($page['description']) ?>">
<?php if ($status === 200): ?><link rel="canonical" href="<?= $e($baseUrl . $path) ?>"><?php else: ?><meta name="robots" content="noindex"><?php endif; ?>
<meta name="theme-color" content="#f7f9fc"><meta property="og:title" content="<?= $e($page['title']) ?> · Sense CMS"><meta property="og:description" content="<?= $e($page['description']) ?>"><meta property="og:type" content="website"><meta property="og:url" content="<?= $e($baseUrl . ($status === 200 ? $path : '/')) ?>"><?php if ($status === 200): ?><meta property="og:image" content="<?= $e($baseUrl . '/theme-assets/og.image.jpg') ?>"><meta property="og:image:secure_url" content="<?= $e($baseUrl . '/theme-assets/og.image.jpg') ?>"><meta property="og:image:alt" content="Sense CMS connected content management workspace"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:type" content="image/jpeg"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="<?= $e($baseUrl . '/theme-assets/og.image.jpg') ?>"><?php endif; ?>
<?php endif; ?>
<link rel="icon" href="/theme-assets/logo.svg" type="image/svg+xml"><link rel="stylesheet" href="/theme-assets/site.css"><link rel="stylesheet" href="/theme-assets/product.css"><script src="/theme-assets/site.js" defer></script>
</head>
<body class="product-design"><a class="skip" href="#main"><?= $e($tr('Skip to content','Przejdź do treści')) ?></a>
<header class="header"><div class="container header-inner"><a class="brand" href="<?= $e($homeUrl) ?>" aria-label="<?= $e($tr('Sense CMS home','Strona główna Sense CMS')) ?>"><img src="/theme-assets/logo.svg" width="34" height="38" alt=""><span><?php $renderBrandText('SenseCMS.'); ?></span></a>
<nav id="navigation" class="primary-nav" aria-label="<?= $e($tr('Main navigation','Nawigacja główna')) ?>"><?php $renderLinks($primaryLinks); ?><a class="mobile-demo" href="https://demo.sensecms.com/" target="_blank" rel="noopener noreferrer"><?= $e($tr('Try the demo','Wypróbuj demo')) ?> <span aria-hidden="true">↗</span></a></nav>
<div class="header-actions"><div class="accessibility-menu"><button class="accessibility-toggle" type="button" aria-label="<?= $e($tr('Accessibility','Dostępność')) ?>" aria-expanded="false" aria-controls="accessibility-panel" aria-haspopup="dialog" data-accessibility-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.5" r="2"/><path d="M5 8.5c2.3 1 4.6 1.5 7 1.5s4.7-.5 7-1.5M12 10v10M8.5 21 12 15l3.5 6"/></svg></button><section id="accessibility-panel" class="accessibility-panel" role="dialog" aria-labelledby="accessibility-title" hidden data-accessibility-panel><div class="accessibility-panel-head"><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.5" r="2"/><path d="M5 8.5c2.3 1 4.6 1.5 7 1.5s4.7-.5 7-1.5M12 10v10M8.5 21 12 15l3.5 6"/></svg></span><div><h2 id="accessibility-title"><?= $e($tr('Accessibility','Dostępność')) ?></h2><p><?= $e($tr('Adjust text from 100% to 200%.','Zmień wielkość tekstu od 100% do 200%.')) ?></p></div><button type="button" aria-label="<?= $e($tr('Close accessibility settings','Zamknij ustawienia dostępności')) ?>" data-accessibility-close>×</button></div><div class="accessibility-controls"><button type="button" aria-label="<?= $e($tr('Decrease text size','Zmniejsz tekst')) ?>" data-text-decrease>A−</button><output for="accessibility-scale" aria-live="polite" data-text-scale>100%</output><button type="button" aria-label="<?= $e($tr('Increase text size','Powiększ tekst')) ?>" data-text-increase>A+</button><button type="button" data-text-reset><?= $e($tr('Reset','Przywróć')) ?></button></div><label for="accessibility-scale"><?= $e($tr('Text size','Wielkość tekstu')) ?></label><input id="accessibility-scale" type="range" min="100" max="200" step="10" value="100" data-text-range></section></div><details class="language-menu"><summary aria-label="<?= $e($tr('Choose language','Wybierz język')) ?>"><?php $renderLanguageFlag($activeLocale); ?><span><?= $e(strtoupper($activeLocale)) ?></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg></summary><div><?php foreach ($languageLinks as $language): ?><a href="<?= $e($language['url']) ?>" hreflang="<?= $e($language['code']) ?>" lang="<?= $e($language['code']) ?>"<?= $language['code'] === $activeLocale ? ' aria-current="page"' : '' ?>><span class="language-label"><?php $renderLanguageFlag($language['code']); ?><?= $e($language['native']) ?></span><small><?= $e($language['name']) ?></small></a><?php endforeach; ?></div></details>
<a class="header-demo" href="https://demo.sensecms.com/" target="_blank" rel="noopener noreferrer">Demo<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg></a>
<button class="menu-button" aria-expanded="false" aria-controls="navigation" aria-label="<?= $e($tr('Open navigation','Otwórz nawigację')) ?>" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button></div></div></header>
<main id="main" tabindex="-1">
<?php if ($page['kind'] === 'home'): ?>
<?php require __DIR__ . '/views/home.php'; ?>
<?php elseif ($page['kind'] === 'product'): ?>
<?php require __DIR__ . '/views/product.php'; ?>
<?php elseif ($page['kind'] === 'managed'): ?>
<?php require __DIR__ . '/views/subpage.php'; ?>
<?php elseif ($page['kind'] === 'post'): ?>
<?php require __DIR__ . '/views/post.php'; ?>
<?php elseif ($page['kind'] === 'article'): ?>
<div class="container docs-layout"><aside class="docs-nav"><a href="/docs" class="eyebrow">DOCUMENTATION</a><nav aria-label="Documentation"><?php foreach (['/docs/installation' => 'Installation', '/docs/licensing' => 'Licensing', '/docs/packages' => 'Package contract', '/docs/themes' => 'Theme development', '/docs/server' => 'Server configuration'] as $url => $label): ?><a href="<?= $url ?>"<?= $path === $url ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav><p>Documentation for the current development Core.</p></aside><article class="article"><?php $renderBreadcrumb(); ?><h1><?= $e($page['title']) ?></h1><p class="intro"><?= $e($page['description']) ?></p><?php foreach ($page['sections'] as $i => $section): ?><section id="section-<?= $i + 1 ?>"><h2><?= $e($section[0]) ?></h2><p><?= $e($section[1]) ?></p><?php if (isset($section[2])): ?><pre><code><?= $e($section[2]) ?></code></pre><?php endif; ?></section><?php endforeach; ?><div class="article-footer">Have a question? <a href="/contact">Contact Sense CMS →</a></div></article></div>
<?php else: ?>
<section class="page-hero container"><span class="eyebrow">404 / NOT FOUND</span><h1><?= $e($page['title']) ?></h1><p class="intro"><?= $e($page['description']) ?></p></section>
<section class="container closing"><p>The address may have changed. Start from the homepage or browse the documentation.</p><div class="actions"><a class="button" href="/">Back to the homepage →</a><a href="/docs" class="text-link">Documentation →</a></div></section>
<?php endif; ?>
</main><footer class="footer"><div class="container footer-top"><div class="footer-brand"><a class="brand" href="<?= $e($homeUrl) ?>" aria-label="<?= $e($footerBrand . ' — ' . $tr('home','strona główna')) ?>"><img src="<?= $e($footerLogo) ?>" width="42" height="42" alt=""><span><?php $renderBrandText($footerBrand); ?></span></a><p><?= $e($footerDescription) ?></p></div>
<div><h2><?= $e($tr('Explore','Poznaj')) ?></h2><?php if (isset($navigation['footer'])): $renderLinks($navigation['footer']); else: ?><a href="/platform">Platform</a><a href="/extensions">Extensions</a><a href="/download">Release status</a><?php endif; ?></div>
<div><h2><?= $e($tr('Build','Twórz')) ?></h2><a href="<?= $e($localUrl('/docs')) ?>"><?= $e($tr('Documentation','Dokumentacja')) ?></a><a href="<?= $e($localUrl('/docs/installation')) ?>"><?= $e($tr('Installation','Instalacja')) ?></a><a href="<?= $e($localUrl('/docs/packages')) ?>"><?= $e($tr('Package contract','Specyfikacja pakietów')) ?></a></div>
<div><h2><?= $e($tr('Connect','Kontakt')) ?></h2><?php if (isset($navigation['footer-connect'])): $renderLinks($navigation['footer-connect']); else: ?><a href="/contact">Contact</a><a href="mailto:info@SenseCMS.com">info@SenseCMS.com</a><?php endif; ?></div>
</div><div class="container footer-bottom"><span><?= $e($themeSettings['footer_copyright_text'] ?? '© Copyright by Sense CMS · QUANT Software House Limited. All rights reserved.') ?></span><?php if (($themeSettings['footer_right_type'] ?? 'links') === 'text'): ?><span class="footer-right-text"><?= $e($themeSettings['footer_right_text'] ?? '') ?></span><?php else: ?><nav class="footer-legal" aria-label="<?= $e($tr('Legal','Informacje prawne')) ?>"><?php $renderLinks($footerLinks); ?></nav><?php endif; ?></div></footer>
<button class="back-to-top" type="button" aria-label="<?= $e($tr('Back to top','Wróć na górę')) ?>" aria-hidden="true" tabindex="-1" data-back-to-top><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 12 6-6 6 6M12 6v12"/></svg></button>
</body></html>
