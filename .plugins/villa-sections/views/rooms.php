<?php
declare(strict_types=1);
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = is_array($data ?? null) ? $data : [];
$url = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '' || str_contains($value, '\\') || preg_match('/[\s<>"\x00-\x1f]/u', $value)) return '';
    if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_contains($value, '..')) return $value;
    return filter_var($value, FILTER_VALIDATE_URL) && preg_match('/^https:\/\//iD', $value) ? $value : '';
};
$rooms = array_values(array_filter((array) ($data['rooms'] ?? []), static fn(mixed $room): bool => is_array($room) && $url($room['image'] ?? '') !== ''));
if (!$rooms) return;
$isPolish = str_starts_with((string) ($locale ?? ''), 'pl');
$capacityLabel = static function (int $capacity) use ($isPolish): string {
    if (!$isPolish) return $capacity === 1 ? 'guest' : 'guests';
    if ($capacity === 1) return 'osoba';
    if ($capacity >= 2 && $capacity <= 4) return 'osoby';
    return 'osób';
};
$roomLink = $isPolish ? 'Zobacz pokój' : 'View room';
?>
<section class="villa-rooms" aria-label="<?= $e($data['title'] ?? '') ?>">
    <header class="villa-section-heading">
        <?php if (!empty($data['eyebrow'])): ?><span class="villa-eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
        <h2><?= $e($data['title'] ?? '') ?></h2>
        <?php if (!empty($data['text'])): ?><p><?= nl2br($e($data['text'])) ?></p><?php endif; ?>
    </header>
    <div class="villa-room-grid">
        <?php foreach ($rooms as $room): $image = $url($room['image'] ?? ''); $target = $url($room['url'] ?? ''); ?>
            <article class="villa-room-card">
                <div class="villa-room-card-image"><img src="<?= $e($image) ?>" alt="<?= $e($room['image_alt'] ?? '') ?>" loading="lazy"></div>
                <div class="villa-room-card-copy"><h3><?php if ($target !== ''): ?><a href="<?= $e($target) ?>"><?= $e($room['title'] ?? '') ?></a><?php else: ?><?= $e($room['title'] ?? '') ?><?php endif; ?></h3>
                    <?php if (!empty($room['description'])): ?><p><?= nl2br($e($room['description'])) ?></p><?php endif; ?>
                    <ul class="villa-room-facts"><?php if (!empty($room['beds'])): ?><li><?= $e($room['beds']) ?></li><?php endif; ?><?php if ((int) ($room['capacity'] ?? 0) > 0): $capacity = (int) $room['capacity']; ?><li><?= $capacity ?> <?= $e($capacityLabel($capacity)) ?></li><?php endif; ?></ul>
                    <?php if ($target !== ''): ?><a class="villa-text-link" href="<?= $e($target) ?>"><?= $e($roomLink) ?><span aria-hidden="true">↗</span></a><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
