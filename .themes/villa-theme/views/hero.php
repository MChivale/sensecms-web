<?php
declare(strict_types=1);

$image = $safeUrl($data['image'] ?? '') ? (string) $data['image'] : '';
$detailImage = $safeUrl($settings['detail_image'] ?? '') ? (string) $settings['detail_image'] : '';
$heroLines = preg_split('/\R/u', trim((string) ($data['title'] ?? '')), 2) ?: [];
if ($image === '') return;
?>
<section class="villa-editorial-hero" aria-labelledby="villa-hero-title">
    <div class="villa-editorial-hero-copy">
        <?php if (!empty($data['eyebrow'])): ?><span class="villa-eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
        <h1 id="villa-hero-title"><span><?= $e($heroLines[0] ?? '') ?></span><?php if (!empty($heroLines[1])): ?><em><?= $e($heroLines[1]) ?></em><?php endif; ?></h1>
        <?php if (!empty($data['text'])): ?><p><?= nl2br($e($data['text'])) ?></p><?php endif; ?>
        <?php if (!empty($data['primary_label']) && $safeUrl($data['primary_url'] ?? '')): ?><a class="villa-button" href="<?= $e($data['primary_url']) ?>"><?= $e($data['primary_label']) ?><span aria-hidden="true">↗</span></a><?php endif; ?>
    </div>
    <figure class="villa-editorial-hero-main"><picture><?= $webpSource($image) ?><img src="<?= $e($image) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" width="1200" height="1600" fetchpriority="high"></picture></figure>
    <?php if ($detailImage !== ''): ?><figure class="villa-editorial-hero-detail"><picture><?= $webpSource($detailImage) ?><img src="<?= $e($detailImage) ?>" alt="<?= $e($settings['detail_image_alt'] ?? '') ?>" width="640" height="720" loading="eager"></picture></figure><?php endif; ?>
    <?php if (!empty($settings['location_label'])): ?><p class="villa-editorial-location"><?= $e($settings['location_label']) ?></p><?php endif; ?>
</section>
