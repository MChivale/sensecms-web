<?php
declare(strict_types=1);
$fallbackNav = [
    ['label'=>'Platform', 'url'=>'/platform'],
    ['label'=>'Extensions', 'url'=>'/extensions'],
    ['label'=>'Update', 'url'=>'/update'],
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
$renderLinks = static function (array $links) use ($e, $path, $safeUrl): void {
    foreach ($links as $link) {
        $url = trim((string) ($link['url'] ?? ''));
        if (!$safeUrl($url)) continue;
        $target = ($link['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $current = $url === $path ? ' aria-current="page"' : ($url !== '/' && str_starts_with($path, $url . '/') ? ' class="nav-parent"' : '');
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
$breadcrumbItems = $path === '/' ? [] : [['label'=>'Home','url'=>$homeUrl]];
if (str_starts_with($path, '/docs/') && $path !== '/docs') $breadcrumbItems[] = ['label'=>'Documentation','url'=>'/docs'];
if (str_starts_with($path, '/extensions/') && $path !== '/extensions') $breadcrumbItems[] = ['label'=>'Extensions','url'=>'/extensions'];
if ($breadcrumbItems) $breadcrumbItems[] = ['label'=>$currentLabel,'url'=>''];
$renderBreadcrumb = static function () use ($breadcrumbItems, $e): void {
    if (!$breadcrumbItems) return;
    echo '<nav class="subpage-breadcrumb" aria-label="Breadcrumb">';
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
<body class="product-design"><a class="skip" href="#main">Skip to content</a>
<header class="header"><div class="container header-inner"><a class="brand" href="<?= $e($homeUrl) ?>" aria-label="Sense CMS home"><img src="/theme-assets/logo.svg" width="34" height="38" alt=""><span><?php $renderBrandText('SenseCMS.'); ?></span></a>
<nav id="navigation" class="primary-nav" aria-label="Main navigation"><?php $renderLinks($primaryLinks); ?></nav>
<div class="header-actions"><div class="accessibility-menu"><button class="accessibility-toggle" type="button" aria-label="Accessibility" aria-expanded="false" aria-controls="accessibility-panel" aria-haspopup="dialog" data-accessibility-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.5" r="2"/><path d="M5 8.5c2.3 1 4.6 1.5 7 1.5s4.7-.5 7-1.5M12 10v10M8.5 21 12 15l3.5 6"/></svg></button><section id="accessibility-panel" class="accessibility-panel" role="dialog" aria-labelledby="accessibility-title" hidden data-accessibility-panel><div class="accessibility-panel-head"><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="4.5" r="2"/><path d="M5 8.5c2.3 1 4.6 1.5 7 1.5s4.7-.5 7-1.5M12 10v10M8.5 21 12 15l3.5 6"/></svg></span><div><h2 id="accessibility-title">Accessibility</h2><p>Adjust text from 100% to 200%.</p></div><button type="button" aria-label="Close accessibility settings" data-accessibility-close>×</button></div><div class="accessibility-controls"><button type="button" aria-label="Decrease text size" data-text-decrease>A−</button><output for="accessibility-scale" aria-live="polite" data-text-scale>100%</output><button type="button" aria-label="Increase text size" data-text-increase>A+</button><button type="button" data-text-reset>Reset</button></div><label for="accessibility-scale">Text size</label><input id="accessibility-scale" type="range" min="100" max="200" step="10" value="100" data-text-range></section></div><details class="language-menu"><summary aria-label="Choose language"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z"/></svg><span><?= $e(strtoupper($activeLocale)) ?></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg></summary><div><?php foreach ($languageLinks as $language): ?><a href="<?= $e($language['url']) ?>" hreflang="<?= $e($language['code']) ?>" lang="<?= $e($language['code']) ?>"<?= $language['code'] === $activeLocale ? ' aria-current="page"' : '' ?>><span><?= $e($language['native']) ?></span><small><?= $e($language['name']) ?></small></a><?php endforeach; ?></div></details>
<a class="header-demo" href="https://demo.sensecms.com/" target="_blank" rel="noopener noreferrer">Demo<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg></a>
<button class="menu-button" aria-expanded="false" aria-controls="navigation" aria-label="Open navigation" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button></div></div></header>
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
</main><footer class="footer"><div class="container footer-top"><div class="footer-brand"><a class="brand" href="/" aria-label="<?= $e($footerBrand) ?> home"><img src="<?= $e($footerLogo) ?>" width="42" height="42" alt=""><span><?php $renderBrandText($footerBrand); ?></span></a><p><?= $e($footerDescription) ?></p></div>
<div><h2>Explore</h2><?php if (isset($navigation['footer'])): $renderLinks($navigation['footer']); else: ?><a href="/platform">Platform</a><a href="/extensions">Extensions</a><a href="/download">Release status</a><?php endif; ?></div>
<div><h2>Build</h2><a href="/docs">Documentation</a><a href="/docs/installation">Installation</a><a href="/docs/packages">Package contract</a></div>
<div><h2>Connect</h2><?php if (isset($navigation['footer-connect'])): $renderLinks($navigation['footer-connect']); else: ?><a href="/contact">Contact</a><a href="mailto:info@SenseCMS.com">info@SenseCMS.com</a><?php endif; ?></div>
</div><div class="container footer-bottom"><span><?= $e($themeSettings['footer_copyright_text'] ?? '© Copyright by Sense CMS · QUANT Software House Limited. All rights reserved.') ?></span><?php if (($themeSettings['footer_right_type'] ?? 'links') === 'text'): ?><span class="footer-right-text"><?= $e($themeSettings['footer_right_text'] ?? '') ?></span><?php else: ?><nav class="footer-legal" aria-label="Legal"><?php $renderLinks($footerLinks); ?></nav><?php endif; ?></div></footer>
<button class="back-to-top" type="button" aria-label="Back to top" aria-hidden="true" tabindex="-1" data-back-to-top><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 12 6-6 6 6M12 6v12"/></svg></button>
</body></html>
