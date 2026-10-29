<?php
declare(strict_types=1);
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = is_array($data ?? null) ? $data : [];
$url = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '' || str_contains($value, '\\') || preg_match('/[\s<>"\x00-\x1f]/u', $value)) return '';
    if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_contains($value, '..')) return $value;
    if (preg_match('/^(?:tel|mailto):[^\s<>]+$/iD', $value)) return $value;
    return filter_var($value, FILTER_VALIDATE_URL) && preg_match('/^https:\/\//iD', $value) ? $value : '';
};
$image = $url($data['image'] ?? '');
if ($image === '') return;
$primary = $url($data['primary_url'] ?? '');
$secondary = $url($data['secondary_url'] ?? '');
?>
<section class="villa-hero" aria-labelledby="villa-hero-title">
    <div class="villa-hero-copy">
        <?php if (!empty($data['eyebrow'])): ?><span class="villa-eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
        <h1 id="villa-hero-title"><?= $e($data['title'] ?? '') ?></h1>
        <?php if (!empty($data['text'])): ?><p><?= nl2br($e($data['text'])) ?></p><?php endif; ?>
        <div class="villa-hero-actions">
            <?php if ($primary !== '' && trim((string) ($data['primary_label'] ?? '')) !== ''): ?><a class="villa-button" href="<?= $e($primary) ?>"><?= $e($data['primary_label']) ?><span aria-hidden="true">↗</span></a><?php endif; ?>
            <?php if ($secondary !== '' && trim((string) ($data['secondary_label'] ?? '')) !== ''): ?><a class="villa-hero-secondary" href="<?= $e($secondary) ?>"><?= $e($data['secondary_label']) ?><span aria-hidden="true">→</span></a><?php endif; ?>
        </div>
    </div>
    <figure class="villa-hero-image"><img src="<?= $e($image) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" fetchpriority="high"></figure>
</section>
