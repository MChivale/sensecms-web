<?php
declare(strict_types=1);

$e = isset($e) && is_callable($e) ? $e : static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = isset($safeUrl) && is_callable($safeUrl) ? $safeUrl : static fn(string $url): bool => (str_starts_with($url, '/') && !str_starts_with($url, '//') && !preg_match('/[\s<>"\'\\\\]/u', $url)) || (bool) preg_match('/^(?:mailto|tel):[^\s<>]+$/iD', $url) || ((bool) filter_var($url, FILTER_VALIDATE_URL) && (bool) preg_match('/^https:\/\//iD', $url));
$rich = static fn(mixed $value): string => \App\Core\HtmlSanitizer::sanitize((string) $value);
$focus = static function (array $data, string $prefix = ''): string {
    $xValue = (string) ($data[$prefix . 'focus_x'] ?? 'center');
    $yValue = (string) ($data[$prefix . 'focus_y'] ?? 'center');
    $x = in_array($xValue, ['left','center','right'], true) ? $xValue : 'center';
    $y = in_array($yValue, ['top','center','bottom'], true) ? $yValue : 'center';
    return ' focus-x-' . $x . ' focus-y-' . $y;
};
$action = static function (mixed $label, mixed $url, string $class = 'button') use ($e, $safeUrl): string {
    $label = trim((string) $label); $url = trim((string) $url);
    return $label !== '' && $safeUrl($url) ? '<a class="' . $class . '" href="' . $e($url) . '">' . $e($label) . '</a>' : '';
};
$heading = static function (array $data) use ($e, $rich): string {
    return (!empty($data['eyebrow']) ? '<span class="eyebrow">' . $e($data['eyebrow']) . '</span>' : '')
        . (!empty($data['title']) ? '<h2>' . $e($data['title']) . '</h2>' : '')
        . (!empty($data['text']) ? '<div class="sense-block-copy">' . $rich($data['text']) . '</div>' : '');
};

$layoutKey = null;
$layoutOpen = false;
foreach ($blocks as $layoutIndex => $block):
    $type = (string) ($block['type'] ?? '');
    $data = (array) ($block['payload'] ?? []);
    $shared = (array) ($block['shared'] ?? []);
    $layout = \App\Core\PageBuilder::sanitizeLayout($block['layout'] ?? []);
    $appearance = \App\Core\PageBuilder::sanitizeAppearance($block['appearance'] ?? []);
    $grouped = $layout['group'] !== null;
    $nextLayoutKey = $layout['group'] ?? ('section-' . (string) ($block['uid'] ?? $layoutIndex));
    if ($layoutKey !== $nextLayoutKey):
        if ($layoutOpen): ?></div><?php endif;
        $layoutKey = $nextLayoutKey;
        $layoutOpen = true;
        $layoutItems = $grouped ? count(array_filter($blocks, static fn(array $item): bool => (($item['layout']['group'] ?? null) === $layout['group']))) : 1;
        $layoutClass = 'sense-layout sense-layout-' . $layout['mode'] . ' sense-layout-' . $layout['container'] . ' sense-layout-gap-' . $layout['gap'] . ' sense-layout-align-' . $layout['align'] . ($grouped ? ' is-grouped' : '') . ($layout['wrap'] ? ' allows-wrap' : ' is-nowrap'); ?>
<div class="<?= $e($layoutClass) ?>" style="--sense-layout-items:<?= $layoutItems ?>">
<?php endif;
    $builderUid = !empty($isBuilderPreview) && !empty($block['builder_preview']) ? (string) ($block['uid'] ?? '') : '';
    $itemClass = 'sense-layout-item sense-variant-' . $appearance['variant'] . ' sense-surface-' . $appearance['surface'] . ' sense-spacing-' . $appearance['spacing'] . ' sense-radius-' . $appearance['radius'] . ' sense-content-align-' . $appearance['align'] . ($layout['desktop']['hidden'] ? ' is-hidden-desktop' : '') . ($layout['tablet']['hidden'] ? ' is-hidden-tablet' : '') . ($layout['mobile']['hidden'] ? ' is-hidden-mobile' : '') . ($builderUid !== '' && empty($block['visible']) ? ' is-builder-hidden' : '');
    $itemStyle = '--sense-span-desktop:' . $layout['desktop']['span'] . ';--sense-order-desktop:' . $layout['desktop']['order'] . ';--sense-span-tablet:' . $layout['tablet']['span'] . ';--sense-order-tablet:' . $layout['tablet']['order'] . ';--sense-span-mobile:' . $layout['mobile']['span'] . ';--sense-order-mobile:' . $layout['mobile']['order']; ?>
<div class="<?= $e($itemClass) ?>" style="<?= $e($itemStyle) ?>"<?= $builderUid !== '' ? ' data-sensecms-builder-uid="' . $e($builderUid) . '"' : '' ?>>
<?php
    if ($type === 'hero'):
        $isVideo = ($data['media_type'] ?? 'image') === 'video'; ?>
<section class="sense-block sense-block-hero"><div class="sense-block-hero-copy"><?= $heading($data) ?><div class="sense-block-actions"><?= $action($data['primary_label'] ?? '', $data['primary_url'] ?? '') ?><?= $action($data['secondary_label'] ?? '', $data['secondary_url'] ?? '', 'text-link') ?></div></div><div class="sense-block-media<?= $focus($data) ?>"><?php if ($isVideo && !empty($data['video'])): ?><video src="<?= $e($data['video']) ?>"<?= !empty($data['poster']) ? ' poster="' . $e($data['poster']) . '"' : '' ?> controls playsinline preload="metadata"></video><?php elseif (!empty($data['image'])): ?><picture><?php if (!empty($data['mobile_image'])): ?><source media="(max-width: 640px)" srcset="<?= $e($data['mobile_image']) ?>"><?php endif; ?><img src="<?= $e($data['image']) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" loading="eager"></picture><?php endif; ?></div></section>
<?php elseif ($type === 'hero-slider'): $slides = array_values((array) ($data['slides'] ?? [])); if ($slides):
        $heightValue = (string) ($shared['height'] ?? 'large');
        $height = in_array($heightValue, ['full','large','medium'], true) ? $heightValue : 'large';
        $transition = ($shared['transition'] ?? 'fade') === 'slide' ? 'slide' : 'fade'; ?>
<section class="sense-block sense-block-slider is-<?= $height ?> is-<?= $transition ?>" data-core-slider data-autoplay="<?= !empty($shared['autoplay']) ? '1' : '0' ?>" data-loop="<?= !empty($shared['loop']) ? '1' : '0' ?>" data-pause-hover="<?= !empty($shared['pause_hover']) ? '1' : '0' ?>" data-interval="<?= max(3, min(15, (int) ($shared['interval'] ?? 6))) ?>"><?php foreach ($slides as $index => $slide): $video = ($slide['media_type'] ?? 'image') === 'video'; ?><article class="sense-block-slide<?= $index === 0 ? ' is-active' : '' ?>" data-slide aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>"><div class="sense-block-slide-media<?= $focus((array) $slide) ?>"><?php if ($video && !empty($slide['video'])): ?><video src="<?= $e($slide['video']) ?>"<?= !empty($slide['poster']) ? ' poster="' . $e($slide['poster']) . '"' : '' ?> muted playsinline preload="metadata"></video><?php elseif (!empty($slide['image'])): ?><picture><?php if (!empty($slide['mobile_image'])): ?><source media="(max-width: 640px)" srcset="<?= $e($slide['mobile_image']) ?>"><?php endif; ?><img src="<?= $e($slide['image']) ?>" alt="<?= $e($slide['alt'] ?? '') ?>"<?= $index ? ' loading="lazy"' : '' ?>></picture><?php endif; ?></div><div class="sense-block-slide-copy"><?php if (!empty($slide['eyebrow'])): ?><span class="eyebrow"><?= $e($slide['eyebrow']) ?></span><?php endif; ?><h2><?= $e($slide['title'] ?? '') ?></h2><?php if (!empty($slide['text'])): ?><div class="sense-block-copy"><?= $rich($slide['text']) ?></div><?php endif; ?><div class="sense-block-actions"><?= $action($slide['primary_label'] ?? '', $slide['primary_url'] ?? '') ?><?= $action($slide['secondary_label'] ?? '', $slide['secondary_url'] ?? '', 'text-link') ?></div></div></article><?php endforeach; ?><?php if (count($slides) > 1): ?><div class="sense-block-slider-controls"><?php if (!empty($shared['arrows'])): ?><button type="button" data-slider-prev aria-label="Previous slide">←</button><button type="button" data-slider-next aria-label="Next slide">→</button><?php endif; ?><?php if (!empty($shared['dots'])): ?><div class="sense-block-slider-dots"><?php foreach ($slides as $index => $_): ?><button type="button" data-slider-dot="<?= $index ?>"<?= $index === 0 ? ' class="is-active" aria-current="true"' : '' ?> aria-label="Show slide <?= $index + 1 ?>"></button><?php endforeach; ?></div><?php endif; ?></div><?php endif; ?></section>
<?php endif; elseif ($type === 'gallery'): ?>
<section class="sense-block sense-block-gallery"><header><?= $heading($data) ?></header><div class="sense-block-gallery-grid"><?php foreach ((array) ($data['items'] ?? []) as $item): if (empty($item['image'])) continue; ?><figure><img class="<?= trim($focus((array) $item)) ?>" src="<?= $e($item['image']) ?>" alt="<?= $e($item['alt'] ?? '') ?>" loading="lazy"><figcaption><?php if (!empty($item['eyebrow'])): ?><span><?= $e($item['eyebrow']) ?></span><?php endif; ?><?php if (!empty($item['title'])): ?><strong><?= $e($item['title']) ?></strong><?php endif; ?></figcaption></figure><?php endforeach; ?></div></section>
<?php elseif ($type === 'admissions'): ?>
<section class="sense-block sense-block-steps"><header><?= $heading($data) ?></header><ol><?php foreach ((array) ($data['steps'] ?? []) as $index => $step): ?><li><span><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h3><?= $e($step['title'] ?? '') ?></h3><?php if (!empty($step['text'])): ?><p><?= nl2br($e($step['text'])) ?></p><?php endif; ?><?= $action($step['label'] ?? '', $step['url'] ?? '', 'text-link') ?></div></li><?php endforeach; ?></ol><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '') ?></section>
<?php elseif ($type === 'contact-form' && !empty($formCsrf)): ?>
<?php require __DIR__ . '/contact-form.php'; ?>
<?php elseif ($type === 'custom-html'): ?>
<section class="sense-block sense-block-custom"><?= $rich($data['html'] ?? '') ?></section>
<?php elseif ($type === 'story'): ?>
<section class="sense-block sense-block-story"><div><?= $heading($data) ?><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '', 'text-link') ?></div><?php if (!empty($data['statement']) || !empty($data['stamp_title'])): ?><aside><?php if (!empty($data['statement'])): ?><blockquote><?= $e($data['statement']) ?></blockquote><?php endif; ?><?php if (!empty($data['stamp_title'])): ?><strong><?= $e($data['stamp_title']) ?></strong><?php endif; ?><?php if (!empty($data['stamp_text'])): ?><p><?= nl2br($e($data['stamp_text'])) ?></p><?php endif; ?></aside><?php endif; ?></section>
<?php elseif ($type === 'values'): ?>
<section class="sense-block sense-block-values"><div class="sense-block-values-copy"><?= $heading($data) ?><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '', 'text-link') ?><div class="sense-block-value-list"><?php foreach ((array) ($data['items'] ?? []) as $index => $item): ?><article><span><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h3><?= $e($item['title'] ?? '') ?></h3><p><?= nl2br($e($item['text'] ?? '')) ?></p></div></article><?php endforeach; ?></div></div><?php if (!empty($data['image'])): ?><figure class="sense-block-media<?= $focus($data) ?>"><img src="<?= $e($data['image']) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" loading="lazy"></figure><?php endif; ?></section>
<?php elseif ($type === 'programs'): ?>
<section class="sense-block sense-block-services"><header><?= $heading($data) ?></header><div class="sense-block-service-grid"><?php foreach ((array) ($data['items'] ?? []) as $index => $item): ?><article><span><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><h3><?= $e($item['title'] ?? '') ?></h3><p><?= nl2br($e($item['text'] ?? '')) ?></p><?= $action($item['label'] ?? '', $item['url'] ?? '', 'text-link') ?></article><?php endforeach; ?></div><?php if (!empty($data['note_title']) || !empty($data['note_image'])): ?><aside><?php if (!empty($data['note_image'])): ?><img class="<?= trim($focus($data, 'note_')) ?>" src="<?= $e($data['note_image']) ?>" alt="" loading="lazy"><?php endif; ?><div><?php if (!empty($data['note_eyebrow'])): ?><span class="eyebrow"><?= $e($data['note_eyebrow']) ?></span><?php endif; ?><h3><?= $e($data['note_title'] ?? '') ?></h3><p><?= nl2br($e($data['note_text'] ?? '')) ?></p><?= $action($data['note_label'] ?? '', $data['note_url'] ?? '', 'text-link') ?></div></aside><?php endif; ?></section>
<?php elseif ($type === 'statistics'): ?>
<section class="sense-block sense-block-statistics"><div><?= $heading($data) ?><div class="sense-block-stat-grid"><?php foreach ((array) ($data['items'] ?? []) as $item): ?><article><strong><?= $e($item['value'] ?? '') ?></strong><span><?= $e($item['label'] ?? '') ?></span></article><?php endforeach; ?></div></div><?php if (!empty($data['image'])): ?><figure class="sense-block-media<?= $focus($data) ?>"><img src="<?= $e($data['image']) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" loading="lazy"><figcaption><?php if (!empty($data['stage_eyebrow'])): ?><span><?= $e($data['stage_eyebrow']) ?></span><?php endif; ?><strong><?= $e($data['stage_title'] ?? '') ?></strong></figcaption></figure><?php endif; ?></section>
<?php elseif ($type === 'motion'): ?>
<section class="sense-block sense-block-motion"><div><?= $heading($data) ?><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '') ?></div><?php if (!empty($data['video'])): ?><figure class="sense-block-media<?= $focus($data, 'poster_') ?>"><video src="<?= $e($data['video']) ?>"<?= !empty($data['poster']) ? ' poster="' . $e($data['poster']) . '"' : '' ?> controls playsinline preload="metadata"></video></figure><?php endif; ?></section>
<?php elseif ($type === 'news'): $collectionItems=(array)($block['collection']['items']??$latestPosts??[]);$presentationValue=(string)($shared['presentation']??'cards');$presentation=in_array($presentationValue,['cards','list','compact'],true)?$presentationValue:'cards';$showImage=($shared['show_image']??true)!==false;$showExcerpt=($shared['show_excerpt']??true)!==false;$showDate=($shared['show_date']??true)!==false; ?>
<section class="sense-block sense-block-news is-<?= $e($presentation) ?>"><header><?= $heading($data) ?></header><div class="sense-block-news-grid"><?php foreach ($collectionItems as $item): $url=$safeUrl((string)($item['url']??''))?(string)$item['url']:'#'; ?><article><?php if ($showImage&&!empty($item['image'])): ?><a href="<?= $e($url) ?>" tabindex="-1" aria-hidden="true"><img src="<?= $e($item['image']) ?>" alt="" loading="lazy"></a><?php endif; ?><div class="sense-block-news-copy"><?php if ($showDate&&!empty($item['published_at'])): ?><time datetime="<?= $e($item['published_at']) ?>"><?= $e(date('j M Y', strtotime((string)$item['published_at']))) ?></time><?php endif; ?><h3><a href="<?= $e($url) ?>"><?= $e($item['title']??'') ?></a></h3><?php if ($showExcerpt&&!empty($item['excerpt'])): ?><p><?= $e($item['excerpt']) ?></p><?php endif; ?></div></article><?php endforeach; ?><?php if (!$collectionItems): ?><p class="sense-block-empty">No published content matches this collection yet.</p><?php endif; ?></div></section>
<?php elseif ($type === 'cta'): ?>
<section class="sense-block sense-block-cta"><div><?= $heading($data) ?><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '') ?></div><aside><?php if (!empty($data['panel_eyebrow'])): ?><span class="eyebrow"><?= $e($data['panel_eyebrow']) ?></span><?php endif; ?><h3><?= $e($data['panel_title'] ?? '') ?></h3><p><?= nl2br($e($data['panel_text'] ?? '')) ?></p><?= $action($data['panel_label'] ?? '', $data['panel_url'] ?? '', 'text-link') ?></aside></section>
<?php elseif ($type === 'text'): ?>
<section class="sense-block sense-block-text<?= !empty($featuredResource) && ($data['cta_url'] ?? '') === '/docs/installation' ? ' resource-start' : '' ?>"><?php if (isset($blockIcons[$data['cta_url'] ?? ''])): ?><span class="section-symbol" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $blockIcons[$data['cta_url']] ?>"/></svg></span><?php endif; ?><?= $heading($data) ?><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '', 'text-link') ?></section>
<?php elseif ($type === 'image-text'): ?>
<section class="sense-block sense-block-image-text"><?php if (!empty($data['image'])): ?><figure class="sense-block-media<?= $focus($data) ?>"><img src="<?= $e($data['image']) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" loading="lazy"></figure><?php endif; ?><div><?= $heading($data) ?><?= $action($data['cta_label'] ?? '', $data['cta_url'] ?? '') ?></div></section>
<?php elseif ($type === 'separator'): ?>
<div class="sense-block sense-block-separator" style="--separator-space:<?= max(0,min(240,(int)($shared['spacing']??48))) ?>px;--separator-weight:<?= max(1,min(8,(int)($shared['weight']??1))) ?>px"><hr class="is-<?= $e(in_array(($shared['style']??''),['solid','dashed','dotted'],true)?$shared['style']:'solid') ?>"></div>
<?php elseif ($type === 'spacer'): ?>
<div class="sense-block sense-block-spacer" aria-hidden="true" style="--spacer-desktop:<?= max(0,min(480,(int)($shared['desktop']??96))) ?>px;--spacer-tablet:<?= max(0,min(360,(int)($shared['tablet']??72))) ?>px;--spacer-mobile:<?= max(0,min(240,(int)($shared['mobile']??48))) ?>px"></div>
<?php elseif (!empty($block['component_renderer'])): ?>
<?= \App\Core\PluginComponentRenderer::render($projectRoot, $block['component_source'], $block['component_renderer'], ['data'=>$data,'shared'=>$shared,'locale'=>$locale,'page'=>$page]) ?>
<?php endif; ?>
</div>
<?php endforeach; if ($layoutOpen): ?></div><?php endif; ?>
