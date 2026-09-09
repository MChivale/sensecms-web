<?php
declare(strict_types=1);
$publicPath = (string) ($page['public_path'] ?? $path);
$marketplace = $publicPath === '/extensions' || (bool) preg_match('~^/extensions/catalog(?:/(?:theme|plugin|addon|module))?$~D', $publicPath);
$section = match (true) {
    $publicPath === '/contact' => 'contact',
    $publicPath === '/download' => 'release',
    $publicPath === '/docs' => 'resources',
    str_starts_with($publicPath, '/docs/') => 'guide',
    str_starts_with($publicPath, '/extensions') => 'extensions',
    default => 'page',
};
$labels = ['contact'=>'Let’s build something meaningful', 'release'=>'Built carefully. Released responsibly.', 'resources'=>'For people who build', 'guide'=>'Sense CMS documentation', 'extensions'=>'A system shaped around you', 'page'=>'Discover Sense CMS'];
$docs = ['/docs/installation'=>'Installation','/docs/licensing'=>'Licensing','/docs/packages'=>'Package contract','/docs/themes'=>'Theme development','/docs/server'=>'Server configuration'];
$categories = ['/extensions/modules'=>'Modules','/extensions/plugins'=>'Plugins','/extensions/addons'=>'Addons','/extensions/themes'=>'Themes'];
$categoryHome = str_starts_with($publicPath, '/extensions/catalog') ? '/extensions/catalog' : '/extensions';
if ($categoryHome === '/extensions/catalog') $categories = ['/extensions/catalog/module'=>'Modules','/extensions/catalog/plugin'=>'Plugins','/extensions/catalog/addon'=>'Addons','/extensions/catalog/theme'=>'Themes'];
$blockIcons = [
    '/extensions/modules'=>'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
    '/extensions/plugins'=>'M8 3v5m8-5v5M6 8h12v3a6 6 0 0 1-6 6v4m-6-13v3a6 6 0 0 0 6 6',
    '/extensions/addons'=>'M12 3v18M3 12h18',
    '/extensions/themes'=>'M3 4h18v16H3zM3 9h18M9 9v11',
    '/docs/installation'=>'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5',
    '/docs/licensing'=>'M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6',
    '/docs/packages'=>'m12 3 9 5v9l-9 5-9-5V8zM3 8l9 5 9-5m-9 5v9M8 5l9 5',
    '/docs/themes'=>'m8 5-7 7 7 7m8-14 7 7-7 7m-3-16-2 18',
    '/docs/server'=>'M3 3h18v7H3zM3 14h18v7H3zM7 6h1m-1 11h1M12 6h5m-5 11h5',
];
$contactBlocks = $blocks;
$contactSplit = $section === 'contact' && !empty($formCsrf) && count(array_filter($blocks, static fn(array $block): bool => $block['type'] === 'contact-form')) > 0;
if ($contactSplit) $blocks = array_values(array_filter($contactBlocks, static fn(array $block): bool => $block['type'] === 'contact-form'));
$featuredResource = $section === 'resources';
?>
<section class="subpage-hero subpage-<?= $section ?><?= $marketplace ? ' marketplace-hero' : '' ?>">
    <div class="container">
        <nav class="subpage-breadcrumb" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><?php if ($section === 'guide'): ?><a href="/docs">Documentation</a><span aria-hidden="true">/</span><?php elseif ($section === 'extensions' && $publicPath !== '/extensions'): ?><a href="/extensions">Extensions</a><span aria-hidden="true">/</span><?php endif; ?><span><?= $e($docs[$publicPath] ?? $categories[$publicPath] ?? match ($section) { 'resources'=>'Documentation', 'release'=>'Release status', 'contact'=>'Contact', 'extensions'=>$publicPath === '/extensions' ? 'Extensions' : ($publicPath === '/extensions/catalog' ? 'Package catalogue' : $page['title']), default=>$page['title'] }) ?></span></nav>
        <div class="subpage-intro"><div><span class="eyebrow"><?= $e($labels[$section]) ?></span><h1><?= $e($page['title']) ?></h1><?php if ($page['description'] !== ''): ?><p><?= $e($page['description']) ?></p><?php endif; ?></div>
        <div class="subpage-emblem" aria-hidden="true"><span><?= match ($section) { 'resources','guide'=>'{ }', 'contact'=>'↗', 'release'=>'01', 'extensions'=>'+', default=>'S' } ?></span></div></div>
        <?php if ($section === 'extensions'): ?><nav class="category-nav" aria-label="Extension categories"><a href="<?= $categoryHome ?>"<?= $publicPath === $categoryHome ? ' aria-current="page"' : '' ?>><?= $categoryHome === '/extensions/catalog' ? 'All packages' : 'Overview' ?></a><?php foreach ($categories as $url=>$label): ?><a href="<?= $url ?>"<?= ($publicPath === $url || str_starts_with($publicPath, $url . '/')) ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav><?php endif; ?>
    </div>
</section>
<?php if ($marketplace): require __DIR__ . '/marketplace.php'; else: ?>
<div class="container subpage-body subpage-body-<?= $section ?><?= $contactSplit ? ' contact-composed' : '' ?>">
<?php if ($section === 'guide'): ?>
    <aside class="guide-sidebar"><span class="eyebrow">IN THIS COLLECTION</span><nav aria-label="Documentation"><?php foreach ($docs as $url=>$label): ?><a href="<?= $url ?>"<?= $publicPath === $url ? ' aria-current="page"' : '' ?>><?= $label ?><span aria-hidden="true">↗</span></a><?php endforeach; ?></nav><div class="guide-help"><strong>Build with confidence.</strong><p>Questions about your implementation?</p><a href="/contact" class="text-link">Talk to Sense CMS →</a></div></aside>
<?php endif; ?>
<div class="subpage-content <?= in_array($section, ['extensions','resources','release'], true) ? 'subpage-cards' : '' ?>">
<?php require __DIR__ . '/blocks.php'; ?>
<?php if ($section === 'guide'): $routes=array_keys($docs); $position=array_search($publicPath,$routes,true); ?><nav class="guide-pagination" aria-label="More documentation"><?php if ($position !== false && $position > 0): ?><a href="<?= $routes[$position-1] ?>"><small>PREVIOUS GUIDE</small><strong>← <?= $docs[$routes[$position-1]] ?></strong></a><?php endif; ?><?php if ($position !== false && isset($routes[$position+1])): ?><a href="<?= $routes[$position+1] ?>"><small>NEXT GUIDE</small><strong><?= $docs[$routes[$position+1]] ?> →</strong></a><?php endif; ?></nav><?php endif; ?>
</div>
<?php if ($section === 'contact'): ?><aside class="contact-aside"><span class="eyebrow">A DIRECT CONVERSATION</span><h2>Your next step.<br>Our full attention.</h2><p>Tell us what you are building, what you need and where we can help.</p>
<?php if ($contactSplit): ?><div class="contact-details"><?php $blocks=array_values(array_filter($contactBlocks, static fn(array $block): bool => $block['type'] !== 'contact-form')); require __DIR__ . '/blocks.php'; ?></div><?php else: ?><a href="mailto:info@SenseCMS.com" class="contact-address">info@SenseCMS.com <span aria-hidden="true">↗</span></a><?php endif; ?>
<div class="contact-signature"><img src="/theme-assets/logo.svg" width="32" height="36" alt=""><div><strong>Sense CMS</strong><span>A clear foundation. A direct conversation.</span></div></div></aside><?php endif; ?>
</div>
<?php endif; ?>
<?php $blocks=$contactBlocks; unset($blockIcons, $featuredResource); ?>
<?php if (!in_array($section,['contact','guide'],true)): ?><section class="container subpage-next"><div><span class="eyebrow">LET’S MAKE THE NEXT STEP CLEAR</span><h2>Have a project in mind?</h2></div><a class="button" href="/contact">Start a conversation <span aria-hidden="true">↗</span></a></section><?php endif; ?>
