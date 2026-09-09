<?php
declare(strict_types=1);

$sectionHero = array_replace([
    'overline' => 'SENSECMS WORKSPACE',
    'title' => $title ?? 'Workspace',
    'description' => '',
    'icon' => 'layout-dashboard',
    'status' => '',
    'status_detail' => '',
    'status_icon' => 'circle-check',
    'action_url' => '',
    'action_label' => '',
    'action_icon' => 'arrow-right',
    'action_button' => '',
], is_array($sectionHero ?? null) ? $sectionHero : []);
$sectionHeroActionButton = preg_match('/^data-[a-z0-9-]+$/', (string) $sectionHero['action_button']) === 1
    ? (string) $sectionHero['action_button']
    : '';
?>
<header class="sensecms-section-hero">
    <div class="sensecms-section-hero-main">
        <span class="sensecms-section-hero-icon"><i data-lucide="<?= $escape($sectionHero['icon']) ?>"></i></span>
        <div>
            <span class="sensecms-section-overline"><?= $escape($sectionHero['overline']) ?></span>
            <h2><?= $escape($sectionHero['title']) ?></h2>
            <?php if ($sectionHero['description'] !== ''): ?><p><?= $escape($sectionHero['description']) ?></p><?php endif; ?>
        </div>
    </div>
    <?php if ($sectionHero['status'] !== ''): ?>
        <div class="sensecms-section-hero-state">
            <i data-lucide="<?= $escape($sectionHero['status_icon']) ?>"></i>
            <span><strong><?= $escape($sectionHero['status']) ?></strong><?php if ($sectionHero['status_detail'] !== ''): ?><small><?= $escape($sectionHero['status_detail']) ?></small><?php endif; ?></span>
        </div>
    <?php elseif ($sectionHeroActionButton !== '' && $sectionHero['action_label'] !== ''): ?>
        <button class="btn bg-primary text-white" type="button" <?= $sectionHeroActionButton ?>><i data-lucide="<?= $escape($sectionHero['action_icon']) ?>"></i><?= $escape($sectionHero['action_label']) ?></button>
    <?php elseif ($sectionHero['action_url'] !== '' && $sectionHero['action_label'] !== ''): ?>
        <a class="btn bg-primary text-white" href="<?= $escape($sectionHero['action_url']) ?>"><i data-lucide="<?= $escape($sectionHero['action_icon']) ?>"></i><?= $escape($sectionHero['action_label']) ?></a>
    <?php endif; ?>
</header>
<?php unset($sectionHero); ?>
