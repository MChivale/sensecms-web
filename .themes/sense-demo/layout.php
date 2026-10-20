<?php

declare(strict_types=1);

$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isPolish = ($locale ?? '') === 'pl';
$copy = $isPolish ? [
    'label' => 'PRZYKŁADOWA WITRYNA', 'nav_home' => 'Start', 'nav_services' => 'Możliwości',
    'nav_about' => 'O demo', 'login' => 'Otwórz panel', 'hero' => 'Zobacz Sense CMS w działaniu.',
    'intro' => 'Przykładowa witryna utworzona w osobnej instalacji Sense CMS. Przeglądaj strony, a następnie poznaj panel administracyjny.',
    'eyebrow' => 'Witryna demonstracyjna', 'cta' => 'Poznaj możliwości', 'second' => 'O tym demo',
    'section' => 'Treść i wygląd mają swoje miejsce.',
    'sections' => [
        ['01', 'Strony i języki', 'Przykładowe strony są zapisane w CMS i dostępne po polsku oraz angielsku.'],
        ['02', 'Niezależny motyw', 'Wygląd witryny działa jako osobny pakiet, bez zmiany panelu administracyjnego.'],
        ['03', 'Dostęp demonstracyjny', 'Konto Demo pozwala obejrzeć panel. Zapis danych jest zablokowany po stronie serwera.'],
    ],
    'article' => 'Przeczytaj przykładowy wpis', 'footer' => 'Przykładowe treści i wygląd służą wyłącznie prezentacji Sense CMS.',
] : [
    'label' => 'EXAMPLE WEBSITE', 'nav_home' => 'Home', 'nav_services' => 'Explore',
    'nav_about' => 'About the demo', 'login' => 'Open workspace', 'hero' => 'See Sense CMS in action.',
    'intro' => 'An example website in a separate Sense CMS installation. Explore the pages, then take a look at the administration workspace.',
    'eyebrow' => 'Demonstration website', 'cta' => 'Explore the features', 'second' => 'About this demo',
    'section' => 'Content and presentation each have a place.',
    'sections' => [
        ['01', 'Pages and languages', 'Example pages live in the CMS and are available in English and Polish.'],
        ['02', 'Independent theme', 'This website uses its own theme package without changing the administration workspace.'],
        ['03', 'Demo access', 'The Demo account can explore the workspace. The server blocks every data change.'],
    ],
    'article' => 'Read the sample post', 'footer' => 'Example content and design are provided solely to demonstrate Sense CMS.',
];
$homeUrl = $isPolish ? '/pl/home' : '/';
$servicesUrl = $isPolish ? '/pl/explore' : '/explore';
$aboutUrl = $isPolish ? '/pl/about' : '/about';
$currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$managed = isset($page['id']);
$title = $managed ? (string) ($page['title'] ?? '') : (string) ($page['title'] ?? 'Sense CMS Demo');
$description = $managed ? (string) ($page['excerpt'] ?? '') : (string) ($page['description'] ?? $copy['intro']);
$isHome = !$managed || !empty($isFacilityHome) || ($page['template'] ?? '') === 'home';
$isPost = ($contentType ?? '') === 'post';
?>
<!doctype html>
<html lang="<?= $isPolish ? 'pl' : 'en' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= $e($description) ?>">
  <title><?= $e($title) ?> · Sense CMS Demo</title>
  <link rel="stylesheet" href="/theme-assets/demo.css">
</head>
<body>
  <a class="skip" href="#main"><?= $isPolish ? 'Przejdź do treści' : 'Skip to content' ?></a>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="<?= $e($homeUrl) ?>" aria-label="Sense CMS Demo — <?= $e($copy['nav_home']) ?>"><span class="brand-mark" aria-hidden="true">S</span><span>Sense<span class="brand-light">CMS</span><small>DEMO</small></span></a>
      <nav class="main-nav" aria-label="<?= $isPolish ? 'Nawigacja główna' : 'Main navigation' ?>">
        <a href="<?= $e($homeUrl) ?>"<?= $currentPath === $homeUrl ? ' aria-current="page"' : '' ?>><?= $e($copy['nav_home']) ?></a>
        <a href="<?= $e($servicesUrl) ?>"<?= $currentPath === $servicesUrl ? ' aria-current="page"' : '' ?>><?= $e($copy['nav_services']) ?></a>
        <a href="<?= $e($aboutUrl) ?>"<?= $currentPath === $aboutUrl ? ' aria-current="page"' : '' ?>><?= $e($copy['nav_about']) ?></a>
      </nav>
      <div class="header-actions"><a class="language" href="<?= $e($isPolish ? ($managed ? (string) ($languageUrls['en'] ?? '/') : '/') : ($managed ? (string) ($languageUrls['pl'] ?? '/pl/home') : '/pl/home')) ?>" lang="<?= $isPolish ? 'en' : 'pl' ?>" hreflang="<?= $isPolish ? 'en' : 'pl' ?>"><?= $isPolish ? 'EN' : 'PL' ?></a><a class="workspace-link" href="/login"><?= $e($copy['login']) ?> <span aria-hidden="true">↗</span></a></div>
    </div>
  </header>
  <main id="main">
    <?php if ($isHome): ?>
    <section class="hero"><div class="wrap hero-grid"><div class="hero-copy"><span class="kicker"><span class="pulse" aria-hidden="true"></span><?= $e($copy['eyebrow']) ?></span><h1><?= $e($managed ? $title : $copy['hero']) ?></h1><p><?= $e($managed && $description !== '' ? $description : $copy['intro']) ?></p><div class="hero-actions"><a class="button" href="<?= $e($servicesUrl) ?>"><?= $e($copy['cta']) ?> <span aria-hidden="true">↗</span></a><a class="text-link" href="<?= $e($aboutUrl) ?>"><?= $e($copy['second']) ?> <span aria-hidden="true">→</span></a></div></div><div class="hero-art" aria-hidden="true"><div class="art-window"><div class="art-top"><i></i><i></i><i></i><span>Sense CMS</span></div><div class="art-body"><div class="art-sidebar"><b></b><span></span><span></span><span></span><span></span></div><div class="art-content"><div class="art-badge"></div><div class="art-title"></div><div class="art-title short"></div><div class="art-cards"><div></div><div></div><div></div></div></div></div></div><div class="art-float">✦ <span>Content meets design</span></div></div></div></section>
    <section class="features wrap"><div class="section-head"><span class="eyebrow">SENSE CMS · DEMO</span><h2><?= $e($copy['section']) ?></h2></div><div class="feature-grid"><?php foreach ($copy['sections'] as [$num, $heading, $body]): ?><article class="feature"><span class="number"><?= $e($num) ?></span><h3><?= $e($heading) ?></h3><p><?= $e($body) ?></p></article><?php endforeach; ?></div><a class="text-link sample-link" href="<?= $isPolish ? '/pl/posts/witamy-w-demo' : '/en/posts/welcome-to-the-demo' ?>"><?= $e($copy['article']) ?> →</a></section>
    <?php else: ?>
    <section class="page-hero"><div class="wrap"><span class="eyebrow"><?= $e($copy['label']) ?></span><h1><?= $e($title) ?></h1><?php if ($description !== ''): ?><p><?= $e($description) ?></p><?php endif; ?></div></section>
    <div class="wrap page-content">
      <?php if ($isPost && !empty($page['content'])): ?><div class="rich-copy"><?= \App\Core\HtmlSanitizer::sanitize((string) $page['content']) ?></div><?php endif; ?>
      <?php if (!$isPost): $blocks = (array) ($page['blocks'] ?? []); require __DIR__ . '/views/blocks.php'; endif; ?>
      <?php if (!$isPost && empty($page['blocks'])): ?><p class="content-intro"><?= $e($description) ?></p><?php endif; ?>
      <a class="text-link back-link" href="<?= $e($homeUrl) ?>">← <?= $e($copy['nav_home']) ?></a>
    </div>
    <?php endif; ?>
  </main>
  <footer class="site-footer"><div class="wrap footer-inner"><div><strong>Sense<span class="brand-light">CMS</span> <span class="footer-demo">DEMO</span></strong><p><?= $e($copy['footer']) ?></p></div><a href="/login"><?= $e($copy['login']) ?> ↗</a></div></footer>
</body>
</html>
