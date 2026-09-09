<?php
declare(strict_types=1);
?>
<section class="platform-hero">
    <div class="container">
        <nav class="subpage-breadcrumb" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">/</span><span>Platform</span></nav>
        <div class="subpage-intro">
            <div>
                <span class="eyebrow">THE SENSE CMS PLATFORM</span>
                <h1><?= $e($page['title']) ?></h1>
                <?php if ($page['description'] !== ''): ?><p><?= $e($page['description']) ?></p><?php endif; ?>
                <div class="actions"><a class="button inverse" href="/contact">Let’s talk <span aria-hidden="true">↗</span></a><a class="platform-docs" href="/docs">Explore the documentation <span aria-hidden="true">→</span></a></div>
            </div>
            <div class="subpage-emblem" aria-hidden="true"><img src="/theme-assets/logo.svg" alt="" width="64" height="72"></div>
        </div>
        <div class="platform-foundations"><span>01 <strong>A focused Core</strong></span><span>02 <strong>A connected workspace</strong></span><span>03 <strong>An independent theme</strong></span></div>
    </div>
</section>
<div class="container platform-body">
    <div class="platform-showcase">
        <div class="platform-overview"><span class="eyebrow">ONE WORKSPACE. A WIDER VIEW.</span><?php $productBlocks = $blocks; $blocks = array_slice($productBlocks, 0, 1); require __DIR__ . '/blocks.php'; ?></div>
        <figure class="platform-preview"><div class="frame-bar"><span class="frame-dots" aria-hidden="true">● ● ●</span><span>Sense CMS / Workspace</span></div><img src="/theme-assets/workspace.png" width="1440" height="940" alt="Sense CMS administration workspace with content, facilities and publishing controls" loading="lazy"><figcaption>The real workspace · illustrative data from our private test environment</figcaption></figure>
    </div>
    <div class="platform-capabilities"><?php $blocks = array_slice($productBlocks, 1); require __DIR__ . '/blocks.php'; $blocks = $productBlocks; ?></div>
</div>
