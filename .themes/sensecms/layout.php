<?php
declare(strict_types=1);
$nav = ['/platform' => 'Platform', '/extensions' => 'Extensions', '/docs' => 'Documentation'];
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
$renderLinks = static function (array $links) use ($e, $path): void {
    foreach ($links as $link) {
        $target = ($link['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $url = (string) ($link['url'] ?? '');
        $current = $url === $path ? ' aria-current="page"' : ($url !== '/' && str_starts_with($path, $url . '/') ? ' class="nav-parent"' : '');
        echo \App\Core\HtmlSanitizer::sanitize('<a href="' . $e($link['url'] ?? '') . '"' . $target . $current . '>' . $e($link['label'] ?? '') . '</a>');
    }
};
?>
<!doctype html>
<html lang="<?= $e($locale ?? 'en') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php if (isset($seo)): require __DIR__ . '/views/metadata.php'; else: ?>
<title><?= $e($page['title']) ?> · Sense CMS</title><meta name="description" content="<?= $e($page['description']) ?>">
<?php if ($status === 200): ?><link rel="canonical" href="<?= $e($baseUrl . $path) ?>"><?php else: ?><meta name="robots" content="noindex"><?php endif; ?>
<meta name="theme-color" content="#f7f9fc"><meta property="og:title" content="<?= $e($page['title']) ?> · Sense CMS"><meta property="og:description" content="<?= $e($page['description']) ?>"><meta property="og:type" content="website"><meta property="og:url" content="<?= $e($baseUrl . ($status === 200 ? $path : '/')) ?>">
<?php endif; ?>
<link rel="icon" href="/theme-assets/logo.svg" type="image/svg+xml"><link rel="stylesheet" href="/theme-assets/site.css"><link rel="stylesheet" href="/theme-assets/product.css"><script src="/theme-assets/site.js" defer></script>
</head>
<body class="product-design"><a class="skip" href="#main">Skip to content</a>
<header class="header"><div class="container header-inner"><a class="brand" href="/" aria-label="Sense CMS home"><img src="/theme-assets/logo.svg" width="34" height="38" alt=""><span>Sense<span class="brand-light">CMS</span><span class="brand-dot">.</span></span></a>
<button class="menu-button" aria-expanded="false" aria-controls="navigation" type="button">Menu <span aria-hidden="true">☰</span></button>
<nav id="navigation" aria-label="Main navigation"><?php if (isset($navigation['primary'])): $renderLinks($navigation['primary']); else: ?><?php foreach ($nav as $url => $label): ?><a href="<?= $url ?>"<?= str_starts_with($path, $url) ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?><a href="/contact"<?= $path === '/contact' ? ' aria-current="page"' : '' ?>>Contact</a><a class="button small" href="/download">Get Sense CMS <span aria-hidden="true">↗</span></a><?php endif; ?></nav></div></header>
<main id="main" tabindex="-1">
<?php if ($page['kind'] === 'home'): ?>
<?php require __DIR__ . '/views/home.php'; ?>
<?php elseif ($page['kind'] === 'product'): ?>
<?php require __DIR__ . '/views/product.php'; ?>
<?php elseif ($page['kind'] === 'managed'): ?>
<?php require __DIR__ . '/views/subpage.php'; ?>
<?php elseif ($page['kind'] === 'article'): ?>
<div class="container docs-layout"><aside class="docs-nav"><a href="/docs" class="eyebrow">DOCUMENTATION</a><nav aria-label="Documentation"><?php foreach (['/docs/installation' => 'Installation', '/docs/licensing' => 'Licensing', '/docs/packages' => 'Package contract', '/docs/themes' => 'Theme development', '/docs/server' => 'Server configuration'] as $url => $label): ?><a href="<?= $url ?>"<?= $path === $url ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav><p>Documentation for the current development Core.</p></aside><article class="article"><a class="back" href="/docs">← All documentation</a><h1><?= $e($page['title']) ?></h1><p class="intro"><?= $e($page['description']) ?></p><?php foreach ($page['sections'] as $i => $section): ?><section id="section-<?= $i + 1 ?>"><h2><?= $e($section[0]) ?></h2><p><?= $e($section[1]) ?></p><?php if (isset($section[2])): ?><pre><code><?= $e($section[2]) ?></code></pre><?php endif; ?></section><?php endforeach; ?><div class="article-footer">Have a question? <a href="/contact">Contact Sense CMS →</a></div></article></div>
<?php else: ?>
<section class="page-hero container"><span class="eyebrow">404 / NOT FOUND</span><h1><?= $e($page['title']) ?></h1><p class="intro"><?= $e($page['description']) ?></p></section>
<section class="container closing"><p>The address may have changed. Start from the homepage or browse the documentation.</p><div class="actions"><a class="button" href="/">Back to the homepage →</a><a href="/docs" class="text-link">Documentation →</a></div></section>
<?php endif; ?>
</main><footer class="footer"><div class="container footer-top"><div><a class="brand" href="/"><img src="/theme-assets/logo.svg" width="30" height="34" alt=""><span>Sense<span class="brand-light">CMS</span><span class="brand-dot">.</span></span></a><p>A clear foundation.<br>A system of your own.</p></div>
<div><h2>Explore</h2><?php if (isset($navigation['footer'])): $renderLinks($navigation['footer']); else: ?><a href="/platform">Platform</a><a href="/extensions">Extensions</a><a href="/download">Release status</a><?php endif; ?></div>
<div><h2>Build</h2><a href="/docs">Documentation</a><a href="/docs/installation">Installation</a><a href="/docs/packages">Package contract</a></div>
<div><h2>Connect</h2><?php if (isset($navigation['footer-connect'])): $renderLinks($navigation['footer-connect']); else: ?><a href="/contact">Contact</a><a href="mailto:info@SenseCMS.com">info@SenseCMS.com</a><?php endif; ?></div>
</div><div class="container footer-bottom"><span>© <?= date('Y') ?> Sense CMS · QUANT Software House Limited</span><span>Independent by design.</span></div></footer>
<button class="back-to-top" type="button" aria-label="Back to top" aria-hidden="true" tabindex="-1" data-back-to-top><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 12 6-6 6 6M12 6v12"/></svg></button>
</body></html>
