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
$image = $url($data['image'] ?? '');
if ($image === '') return;
$gallery = [];
foreach ((array) ($data['gallery'] ?? []) as $item) if (is_array($item) && ($src = $url($item['image'] ?? '')) !== '') $gallery[] = ['image'=>$src,'alt'=>(string)($item['alt']??'')];
$cta = $url($data['cta_url'] ?? '');
$isPolish = str_starts_with((string) ($locale ?? ''), 'pl');
$capacity = (int) ($data['capacity'] ?? 0);
$capacityLabel = !$isPolish ? ($capacity === 1 ? 'guest' : 'guests') : ($capacity === 1 ? 'osoba' : ($capacity <= 4 ? 'osoby' : 'osób'));
$copy = $isPolish ? ['bathroom'=>'Prywatna łazienka','wifi'=>'Wi-Fi','gallery'=>'Zdjęcia pokoju'] : ['bathroom'=>'Private bathroom','wifi'=>'Wi-Fi','gallery'=>'Room photos'];
?>
<section class="villa-room-detail" aria-labelledby="villa-room-title">
    <figure class="villa-room-main-image"><img src="<?= $e($image) ?>" alt="<?= $e($data['image_alt'] ?? '') ?>" loading="eager"></figure>
    <div class="villa-room-detail-copy">
        <?php if (!empty($data['eyebrow'])): ?><span class="villa-eyebrow"><?= $e($data['eyebrow']) ?></span><?php endif; ?>
        <h2 id="villa-room-title"><?= $e($data['title'] ?? '') ?></h2>
        <ul class="villa-room-facts"><li><?= $e($data['beds'] ?? '') ?></li><?php if ($capacity > 0): ?><li><?= $capacity ?> <?= $e($capacityLabel) ?></li><?php endif; ?><?php if (!empty($data['floor'])): ?><li><?= $e($data['floor']) ?></li><?php endif; ?><li><?= $e($copy['bathroom']) ?></li><li><?= $e($copy['wifi']) ?></li></ul>
        <?php if (!empty($data['text'])): ?><div class="villa-room-description"><?= nl2br($e($data['text'])) ?></div><?php endif; ?>
        <?php if ($cta !== '' && !empty($data['cta_label'])): ?><a class="villa-button" href="<?= $e($cta) ?>"><?= $e($data['cta_label']) ?><span aria-hidden="true">↗</span></a><?php endif; ?>
    </div>
</section>
<?php if ($gallery): ?><div class="villa-room-gallery" aria-label="<?= $e($copy['gallery']) ?>"><?php foreach ($gallery as $item): ?><figure><img src="<?= $e($item['image']) ?>" alt="<?= $e($item['alt']) ?>" loading="lazy"></figure><?php endforeach; ?></div><?php endif; ?>
