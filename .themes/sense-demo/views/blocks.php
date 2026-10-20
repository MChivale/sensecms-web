<?php

declare(strict_types=1);

$allowedUrl = static function (string $url): bool {
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return !preg_match('/[\s<>"\x27\\\\]/u', $url) && !str_contains($url, '..');
    if (preg_match('/^(?:mailto|tel):[^\s<>]+$/iD', $url)) return true;
    return filter_var($url, FILTER_VALIDATE_URL) !== false && (string) parse_url($url, PHP_URL_SCHEME) === 'https';
};
$action = static function (array $data, string $labelKey, string $urlKey) use ($e, $allowedUrl): string {
    $label = trim((string) ($data[$labelKey] ?? ''));
    $url = trim((string) ($data[$urlKey] ?? ''));
    return $label !== '' && $allowedUrl($url) ? '<a class="button" href="' . $e($url) . '">' . $e($label) . ' <span aria-hidden="true">↗</span></a>' : '';
};
foreach ($blocks as $block):
    $type = (string) ($block['type'] ?? '');
    if (!in_array($type, ['hero', 'text', 'cta', 'image-text', 'gallery'], true)) continue;
    $data = (array) ($block['payload'] ?? []);
    $image = trim((string) ($data['image'] ?? ''));
    $video = trim((string) ($data['video'] ?? ''));
    $poster = trim((string) ($data['poster'] ?? ''));
    ?>
    <section class="content-section content-section-<?= $e($type) ?>">
      <?php if (!empty($data['eyebrow'])): ?><span class="eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
      <?php if (!empty($data['title'])): ?><h2><?= $e($data['title']) ?></h2><?php endif; ?>
      <?php if (!empty($data['text'])): ?><div class="rich-copy"><?= \App\Core\HtmlSanitizer::sanitize((string) $data['text']) ?></div><?php endif; ?>
      <?php if (in_array($type, ['hero', 'image-text'], true) && $image !== '' && $allowedUrl($image)): ?><figure class="block-image"><img src="<?= $e($image) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" loading="lazy"></figure><?php endif; ?>
      <?php if ($type === 'hero' && $video !== '' && $allowedUrl($video)): ?><video class="block-video" src="<?= $e($video) ?>"<?= $poster !== '' && $allowedUrl($poster) ? ' poster="' . $e($poster) . '"' : '' ?> controls preload="metadata"></video><?php endif; ?>
      <?php if ($type === 'gallery' && !empty($data['items'])): ?><div class="block-gallery"><?php foreach ((array) $data['items'] as $item): $src = trim((string) ($item['image'] ?? '')); if ($src === '' || !$allowedUrl($src)) continue; ?><figure><img src="<?= $e($src) ?>" alt="<?= $e($item['alt'] ?? '') ?>" loading="lazy"><?php if (!empty($item['title'])): ?><figcaption><?= $e($item['title']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?></div><?php endif; ?>
      <div class="block-actions"><?= $action($data, $type === 'hero' ? 'primary_label' : 'cta_label', $type === 'hero' ? 'primary_url' : 'cta_url') ?><?= $type === 'hero' ? $action($data, 'secondary_label', 'secondary_url') : '' ?></div>
      <?php if ($type === 'cta' && !empty($data['panel_title'])): ?><aside class="block-panel"><span class="eyebrow"><?= $e($data['panel_eyebrow'] ?? '') ?></span><h3><?= $e($data['panel_title']) ?></h3><p><?= $e($data['panel_text'] ?? '') ?></p><?= $action($data, 'panel_label', 'panel_url') ?></aside><?php endif; ?>
    </section>
<?php endforeach; ?>
