<?php
declare(strict_types=1);
?>
<section class="platform-hero">
    <div class="container">
        <?php $renderBreadcrumb(); ?>
        <div class="subpage-intro">
            <div>
                <span class="eyebrow"><?= $e($tr('THE SENSE CMS PLATFORM', 'PLATFORMA SENSE CMS')) ?></span>
                <h1><?= $e($page['title']) ?></h1>
                <?php if ($page['description'] !== ''): ?><p><?= $e($page['description']) ?></p><?php endif; ?>
                <div class="actions"><a class="button inverse" href="<?= $e($localUrl('/contact')) ?>"><?= $e($tr('Let’s talk', 'Porozmawiajmy')) ?> <span aria-hidden="true">↗</span></a><a class="platform-docs" href="<?= $e($localUrl('/docs')) ?>"><?= $e($tr('Explore the documentation', 'Poznaj dokumentację')) ?> <span aria-hidden="true">→</span></a></div>
            </div>
            <?php require __DIR__ . '/hero-visual.php'; ?>
        </div>
        <div class="platform-foundations"><span>01 <strong><?= $e($tr('A focused Core', 'Przemyślany rdzeń')) ?></strong></span><span>02 <strong><?= $e($tr('A connected workspace', 'Wspólna przestrzeń pracy')) ?></strong></span><span>03 <strong><?= $e($tr('An independent theme', 'Niezależny motyw')) ?></strong></span></div>
    </div>
</section>
<div class="container platform-body">
    <div class="platform-showcase">
        <div class="platform-overview"><span class="eyebrow"><?= $e($tr('ONE WORKSPACE. A WIDER VIEW.', 'JEDNA PRZESTRZEŃ. SZERSZE MOŻLIWOŚCI.')) ?></span><?php $productBlocks = $blocks; $blocks = array_slice($productBlocks, 0, 1); require __DIR__ . '/blocks.php'; ?></div>
        <figure class="platform-preview"><div class="frame-bar"><span class="frame-dots" aria-hidden="true">● ● ●</span><span>Sense CMS / Workspace</span></div><img src="/theme-assets/workspace.png" width="1440" height="940" alt="<?= $e($tr('Sense CMS administration workspace with content, facilities and publishing controls', 'Panel administracyjny Sense CMS z treściami, placówkami i narzędziami publikacji')) ?>" loading="lazy"><figcaption><?= $e($tr('The real workspace · illustrative data from our private test environment', 'Panel administracyjny · przykładowe dane z prywatnego środowiska testowego')) ?></figcaption></figure>
    </div>
    <?php if (in_array($canonicalPath, ['/platform', '/platform/ai'], true)): require __DIR__ . '/chatbot.php'; endif; ?>
    <?php if ($canonicalPath === '/platform'): require __DIR__ . '/solutions.php'; endif; ?>
    <?php if ($canonicalPath === '/platform'): require __DIR__ . '/apps.php'; endif; ?>
    <div class="platform-capabilities"><?php $blocks = array_slice($productBlocks, 1); require __DIR__ . '/blocks.php'; $blocks = $productBlocks; ?></div>
</div>
