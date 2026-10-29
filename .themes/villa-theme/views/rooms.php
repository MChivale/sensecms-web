<?php
declare(strict_types=1);

$rooms = array_values(array_filter((array) ($data['rooms'] ?? []), static fn(mixed $room): bool => is_array($room) && $safeUrl($room['image'] ?? '')));
if (!$rooms) return;
if (!$home): ?>
<section class="villa-room-list" aria-label="<?= $e($data['title'] ?? '') ?>">
    <header class="villa-section-heading">
        <?php if (!empty($data['eyebrow'])): ?><span class="villa-eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
        <h2><?= $e($data['title'] ?? '') ?></h2>
        <?php if (!empty($data['text'])): ?><p><?= nl2br($e($data['text'])) ?></p><?php endif; ?>
    </header>
    <div class="villa-room-grid">
        <?php foreach ($rooms as $room): $url = $safeUrl($room['url'] ?? '') ? (string) $room['url'] : ''; ?>
        <article class="villa-room-card">
            <?php if ($url !== ''): ?><a class="villa-room-card-image" href="<?= $e($url) ?>" data-room-photo aria-label="<?= $e(($isPolish ? 'Zobacz zdjęcia: ' : 'View photos: ') . ($room['title'] ?? '')) ?>"><?php else: ?><div class="villa-room-card-image"><?php endif; ?>
                <picture><?= $webpSource($room['image']) ?><img src="<?= $e($room['image']) ?>" alt="<?= $e($room['image_alt'] ?? '') ?>" loading="lazy"></picture>
            <?php if ($url !== ''): ?></a><?php else: ?></div><?php endif; ?>
            <div class="villa-room-card-copy">
                <h3><?php if ($url !== ''): ?><a href="<?= $e($url) ?>"><?php endif; ?><?= $e($room['title'] ?? '') ?><?php if ($url !== ''): ?></a><?php endif; ?></h3>
                <?php if (!empty($room['description'])): ?><p><?= nl2br($e($room['description'])) ?></p><?php endif; ?>
                <?php if (!empty($room['beds'])): ?><p class="villa-room-card-beds"><?= $e($room['beds']) ?></p><?php endif; ?>
                <?php if ($url !== ''): ?><a class="villa-text-link" href="<?= $e($url) ?>"><?= $e($isPolish ? 'Poznaj pokój' : 'Explore room') ?><span aria-hidden="true">↗</span></a><?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php return; endif;
$roomPrefix = 'room-' . substr(hash('sha256', (string) ($block['uid'] ?? $layoutIndex)), 0, 10) . '-';
?>
<section class="villa-room-explorer" data-room-explorer aria-label="<?= $e($data['title'] ?? '') ?>">
    <header class="villa-section-heading">
        <?php if (!empty($data['eyebrow'])): ?><span class="villa-eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
        <h2><?= $e($data['title'] ?? '') ?></h2>
        <?php if (!empty($data['text'])): ?><p><?= nl2br($e($data['text'])) ?></p><?php endif; ?>
    </header>
    <div class="villa-room-explorer-body">
        <div class="villa-room-panels">
            <?php foreach ($rooms as $index => $room): ?>
                <article class="villa-room-panel" id="<?= $e($roomPrefix . $index) ?>" data-room-panel aria-labelledby="<?= $e($roomPrefix . $index) ?>-title">
                    <figure><picture><?= $webpSource($room['image']) ?><img src="<?= $e($room['image']) ?>" alt="<?= $e($room['image_alt'] ?? '') ?>" width="1200" height="900" loading="lazy"></picture></figure>
                    <div class="villa-room-panel-copy">
                        <h3 id="<?= $e($roomPrefix . $index) ?>-title"><?= $e($room['title'] ?? '') ?></h3>
                        <?php if (!empty($room['description'])): ?><p><?= nl2br($e($room['description'])) ?></p><?php endif; ?>
                        <?php if ($safeUrl($room['url'] ?? '')): ?><a class="villa-text-link" href="<?= $e($room['url']) ?>"><?= $e($isPolish ? 'Zobacz pokój' : 'View room') ?><span aria-hidden="true">↗</span></a><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <nav class="villa-room-selector" aria-label="<?= $e($isPolish ? 'Wybierz rodzaj pokoju' : 'Choose a room type') ?>">
            <?php foreach ($rooms as $index => $room): ?><a class="villa-room-option" data-room-option href="#<?= $e($roomPrefix . $index) ?>"<?= $index === count($rooms) - 1 ? ' data-selected' : '' ?>><span class="villa-room-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span><?= $e($room['beds'] ?? $room['title'] ?? '') ?></span></a><?php endforeach; ?>
        </nav>
    </div>
</section>
