<?php

declare(strict_types=1);

$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = static function (mixed $value): bool {
    $url = trim((string) $value);
    if ($url === '' || str_contains($url, '\\') || preg_match('/[\s<>"\x00-\x1f]/u', $url)) return false;
    if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '..')) return true;
    if (preg_match('/^(?:tel|mailto):[^\s<>]+$/iD', $url)) return true;
    return (bool) filter_var($url, FILTER_VALIDATE_URL) && (bool) preg_match('/^https:\/\//iD', $url);
};
$projectRoot = (string) ($projectRoot ?? '');
$webpSource = static function (mixed $value) use ($projectRoot, $e): string {
    $url = (string) $value;
    if ($projectRoot === '' || !preg_match('~^/media/library/[0-9]{4}/[0-9]{2}/[a-f0-9-]+\.jpe?g$~iD', $url)) return '';
    $originalPath = $projectRoot . '/public' . $url;
    if (!is_file($originalPath)) return '';
    $originalSize = filesize($originalPath);
    $variants = [];
    foreach (['thumb', 'medium', 'large'] as $name) {
        $variant = preg_replace('/\.jpe?g$/i', '-' . $name . '.webp', $url);
        $path = $projectRoot . '/public' . $variant;
        if (!is_file($path) || filesize($path) >= $originalSize || !($size = @getimagesize($path)) || ($size['mime'] ?? '') !== 'image/webp') continue;
        $variants[(int) $size[0]] = $variant;
    }
    if (!$variants) return '';
    ksort($variants);
    $srcset = [];
    foreach ($variants as $width => $variant) $srcset[] = $variant . ' ' . $width . 'w';
    return '<source type="image/webp" srcset="' . $e(implode(', ', $srcset)) . '" sizes="(max-width: 760px) calc(100vw - 32px), 60vw">';
};
$isPolish = str_starts_with((string) ($locale ?? ''), 'pl');
$copy = $isPolish ? [
    'skip' => 'Przejdź do treści', 'nav' => 'Nawigacja główna', 'home' => 'Strona główna',
    'call' => 'Zadzwoń', 'menu' => 'Otwórz menu', 'language' => 'Zmień język',
    'gallery' => 'Galeria', 'contact' => 'Kontakt', 'about' => 'O Villa Italiana',
    'footer' => 'Pokoje gościnne w Gdyni', 'more' => 'Poznaj ofertę',
] : [
    'skip' => 'Skip to content', 'nav' => 'Main navigation', 'home' => 'Home',
    'call' => 'Call us', 'menu' => 'Open menu', 'language' => 'Change language',
    'gallery' => 'Gallery', 'contact' => 'Contact', 'about' => 'About Villa Italiana',
    'footer' => 'Guest rooms in Gdynia', 'more' => 'Explore our rooms',
];
$settings = (array) ($appearance ?? []);
$profile = (array) ($profile ?? []);
$page = (array) ($page ?? []);
$seo = (array) ($seo ?? []);
if (!empty($settings['lodging_schema']) && !empty($seo['json_ld']['@graph'])) {
    foreach ($seo['json_ld']['@graph'] as &$entity) {
        if (($entity['@type'] ?? '') !== 'Organization') continue;
        $entity['@type'] = 'LodgingBusiness';
        $address = trim((string) ($profile['address'] ?? ''));
        if ($address !== '') {
            $lines = preg_split('/\R/u', $address) ?: [];
            $postal = [];
            preg_match('/\b\d{2}-\d{3}\b/u', $address, $postal);
            $entity['address'] = array_filter(['@type'=>'PostalAddress', 'streetAddress'=>trim((string) ($lines[0] ?? '')), 'addressLocality'=>trim((string) ($profile['facility_city'] ?? '')), 'postalCode'=>$postal[0] ?? '', 'addressCountry'=>$entity['address']['addressCountry'] ?? '']);
        }
        if (!empty($seo['image'])) $entity['image'] = $seo['image'];
    }
    unset($entity);
}
$blocks = (array) ($page['blocks'] ?? []);
$home = !empty($isFacilityHome) || ($page['template'] ?? '') === 'home';
$location = ($page['template'] ?? '') === 'location';
$mapEmbed = trim((string) ($settings['map_embed_url'] ?? ''));
$mapParts = parse_url($mapEmbed);
if (!$location || !is_array($mapParts) || ($mapParts['scheme'] ?? '') !== 'https' || ($mapParts['host'] ?? '') !== 'www.google.com' || ($mapParts['path'] ?? '') !== '/maps/embed' || isset($mapParts['user']) || isset($mapParts['pass']) || isset($mapParts['port']) || !$safeUrl($mapEmbed)) $mapEmbed = '';
if ($mapEmbed !== '' && !headers_sent()) foreach (headers_list() as $header) {
    if (stripos($header, 'Content-Security-Policy:') !== 0 || stripos($header, 'frame-src ') !== false) continue;
    header($header . '; frame-src https://www.google.com', true);
}
$isPost = ($contentType ?? 'page') === 'post';
$title = trim((string) ($page['title'] ?? 'Villa Italiana')) ?: 'Villa Italiana';
$description = trim((string) ($page['excerpt'] ?? ''));
$phone = trim((string) ($profile['phone'] ?? ''));
$tel = preg_replace('/[^+0-9]/', '', $phone) ?: '';
$bookingUrl = $safeUrl($settings['booking_url'] ?? '') ? trim((string) $settings['booking_url']) : '';
$bookingLabel = $isPolish ? (trim((string) ($settings['booking_label'] ?? '')) ?: 'Rezerwuj pobyt') : 'Book your stay';
// A configured booking destination takes priority; the managed reservation page is the fallback.
if ($bookingUrl === '') foreach ((array) ($footerMenu ?? []) as $item) {
    if (is_array($item) && preg_match('~(?:rezerwacja|reservation)$~', (string) ($item['url'] ?? '')) && $safeUrl($item['url'] ?? '')) { $bookingUrl = (string) $item['url']; break; }
}
$homeUrl = (string) ($languageUrls[$locale ?? ''] ?? '/');
if (!$home) $homeUrl = $isPolish ? '/' : '/' . rawurlencode((string) ($locale ?? 'en')) . '/home';
$currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$hasHero = false;
foreach ($blocks as $block) if (($block['type'] ?? '') === 'villa-hero') { $hasHero = true; break; }
$menu = array_values(array_filter((array) ($menu ?? []), static fn(mixed $item): bool => is_array($item) && trim((string) ($item['label'] ?? '')) !== '' && $safeUrl($item['url'] ?? '')));
$languageUrls = (array) ($languageUrls ?? []);
$languageNames = [];
foreach ((array) ($languages ?? []) as $language) {
    if (!is_array($language)) continue;
    $code = (string) ($language['locale'] ?? '');
    if ($code !== '' && isset($languageUrls[$code])) $languageNames[$code] = (string) ($language['native_name'] ?? $language['name'] ?? strtoupper($code));
}
$cssTokens = \App\Core\ThemeContract::cssTokens((array) ($theme ?? []), $settings);
$tokenStyle = implode(';', array_map(static fn(string $key, string $value): string => $key . ':' . $value, array_keys($cssTokens), $cssTokens));
?>
<!doctype html>
<html lang="<?= $e($locale ?? 'pl') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require __DIR__ . '/metadata.php'; ?>
    <meta name="theme-color" content="#f6f1e7">
    <link rel="icon" href="/theme-assets/logo.png" type="image/png">
    <link rel="preload" href="/theme-assets/bodoni-moda-8.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/theme-assets/bodoni-moda-4.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/theme-assets/bodoni-moda.css?v=0.15.4">
    <link rel="stylesheet" href="/theme-assets/villa.css?v=0.15.4">
    <script src="/theme-assets/villa.js?v=0.15.4" defer></script>
</head>
<body class="villa-site<?= $home ? ' is-home' : '' ?>"<?= $tokenStyle !== '' ? ' style="' . $e($tokenStyle) . '"' : '' ?>>
    <a class="villa-skip" href="#main"><?= $e($copy['skip']) ?></a>
    <header class="villa-header">
        <div class="villa-header-inner">
            <a class="villa-brand" href="<?= $e($homeUrl) ?>" aria-label="Villa Italiana — <?= $e($copy['home']) ?>">
                <img class="villa-brand-mark" src="/theme-assets/logo-dark.svg" alt="Villa Italiana" width="210" height="76">
            </a>
            <button class="villa-menu-toggle" type="button" aria-expanded="false" aria-controls="villa-navigation" aria-label="<?= $e($copy['menu']) ?>" data-menu-toggle><span></span><span></span></button>
            <nav class="villa-nav" id="villa-navigation" aria-label="<?= $e($copy['nav']) ?>" data-navigation>
                <?php foreach (array_chunk($menu, max(1, (int) ceil(count($menu) / 2))) as $groupIndex => $group): ?>
                <div class="villa-nav-group <?= $groupIndex === 0 ? 'villa-nav-left' : 'villa-nav-right' ?>">
                <?php foreach ($group as $item): $url = trim((string) $item['url']); $target = ($item['target'] ?? '') === '_blank' ? '_blank' : ''; ?>
                    <a href="<?= $e($url) ?>"<?= $target !== '' ? ' target="_blank" rel="noopener noreferrer"' : '' ?><?= $currentPath === (string) parse_url($url, PHP_URL_PATH) ? ' aria-current="page"' : '' ?>><?= $e($item['label']) ?></a>
                <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </nav>
            <div class="villa-header-actions">
                <?php if (count($languageNames) > 1): ?><nav class="villa-languages" aria-label="<?= $e($copy['language']) ?>"><?php foreach ($languageNames as $code => $name): ?><a href="<?= $e($languageUrls[$code]) ?>" lang="<?= $e($code) ?>" hreflang="<?= $e($code) ?>"<?= $code === ($locale ?? '') ? ' aria-current="page"' : '' ?>><?= $e(strtoupper(substr($code, 0, 2))) ?></a><?php endforeach; ?></nav><?php endif; ?>
                <?php if ($tel !== '' && $bookingUrl === ''): ?><a class="villa-call" href="tel:<?= $e($tel) ?>"><span aria-hidden="true">↗</span><?= $e($copy['call']) ?></a><?php endif; ?>
                <?php if ($bookingUrl !== ''): ?><a class="villa-book" href="<?= $e($bookingUrl) ?>"><?= $e($bookingLabel) ?><span aria-hidden="true">↗</span></a><?php endif; ?>
            </div>
        </div>
    </header>
    <main id="main" tabindex="-1">
        <?php if (!$home && !$isPost): ?><section class="villa-page-intro"><div class="villa-shell"><span class="villa-eyebrow"><?= $e($profile['facility_city'] ?? 'Gdynia') ?> · VILLA ITALIANA</span><h1><?= $e($title) ?></h1><?php if ($description !== ''): ?><p><?= $e($description) ?></p><?php endif; ?></div></section><?php endif; ?>
        <?php if ($home && !$hasHero): ?><section class="villa-fallback-hero"><div class="villa-shell"><span class="villa-eyebrow">POKOJE GOŚCINNE · GDYNIA</span><h1><?= $e($title) ?></h1><?php if ($description !== ''): ?><p><?= $e($description) ?></p><?php endif; ?><a class="villa-button" href="<?= $e($isPolish ? '/pokoje' : '/en/rooms') ?>"><?= $e($copy['more']) ?><span aria-hidden="true">↗</span></a></div></section><?php endif; ?>
        <div class="villa-content">
            <?php if ($isPost && !empty($page['content'])): ?><article class="villa-article villa-shell"><h1><?= $e($title) ?></h1><div class="villa-rich-copy"><?= \App\Core\HtmlSanitizer::sanitize((string) $page['content']) ?></div></article><?php endif; ?>
            <?php require __DIR__ . '/blocks.php'; ?>
        </div>
        <?php if ($mapEmbed !== ''): ?><section class="villa-location-map villa-shell" aria-labelledby="villa-map-title"><div class="villa-location-map-heading"><div><span class="villa-eyebrow"><?= $e($isPolish ? 'ZNAJDŹ NAS' : 'FIND US') ?></span><h2 id="villa-map-title"><?= $e($isPolish ? 'Do zobaczenia w Gdyni' : 'See you in Gdynia') ?></h2></div><?php if ($safeUrl($profile['map_url'] ?? '')): ?><a href="<?= $e($profile['map_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($isPolish ? 'Otwórz w Google Maps' : 'Open in Google Maps') ?> ↗</a><?php endif; ?></div><iframe src="<?= $e($mapEmbed) ?>" title="<?= $e($isPolish ? 'Mapa lokalizacji Villa Italiana w Gdyni' : 'Map showing Villa Italiana in Gdynia') ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></section><?php endif; ?>
    </main>
    <footer class="villa-footer">
        <div class="villa-footer-main villa-shell">
            <div class="villa-footer-brand"><a class="villa-brand" href="<?= $e($homeUrl) ?>"><img class="villa-brand-mark" src="/theme-assets/logo-dark.svg" alt="Villa Italiana" width="210" height="76"></a><p><?= $e($settings['footer_note'] ?? $copy['footer']) ?></p></div>
            <div class="villa-footer-address"><span class="villa-eyebrow"><?= $e($isPolish ? 'ODWIEDŹ NAS' : 'FIND US') ?></span><address><?= $e($profile['facility_name'] ?? 'Villa Italiana') ?><br><?= nl2br($e($profile['address'] ?? '')) ?></address><?php if ($phone !== ''): ?><a href="tel:<?= $e($tel) ?>"><?= $e($phone) ?></a><?php endif; ?><?php if ($safeUrl($profile['map_url'] ?? '')): ?><a class="villa-footer-map" href="<?= $e($profile['map_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($isPolish ? 'Zobacz w Google Maps' : 'View on Google Maps') ?> <span aria-hidden="true">↗</span></a><?php endif; ?></div>
            <?php if (!empty($footerMenu)): ?><nav class="villa-footer-links" aria-label="<?= $e($isPolish ? 'Informacje' : 'Information') ?>"><span class="villa-eyebrow"><?= $e($isPolish ? 'INFORMACJE' : 'INFORMATION') ?></span><?php foreach ((array) $footerMenu as $item): if (!is_array($item) || !$safeUrl($item['url'] ?? '')) continue; ?><a href="<?= $e($item['url']) ?>"><?= $e($item['label'] ?? '') ?></a><?php endforeach; ?></nav><?php endif; ?>
        </div>
        <div class="villa-footer-bottom"><div class="villa-shell"><span>© <?= date('Y') ?> Villa Italiana · Gdynia</span><?php if (!empty($footerConnectMenu)): ?><nav aria-label="<?= $e($isPolish ? 'Media społecznościowe' : 'Social media') ?>"><?php foreach ((array) $footerConnectMenu as $item): if (!is_array($item) || !$safeUrl($item['url'] ?? '')) continue; ?><a href="<?= $e($item['url']) ?>"<?= ($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= $e($item['label'] ?? '') ?></a><?php endforeach; ?></nav><?php endif; ?></div></div>
    </footer>
</body>
</html>
