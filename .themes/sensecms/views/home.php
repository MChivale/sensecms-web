<?php declare(strict_types=1); ?>
<div class="product-hero">
  <section class="container product-intro">
    <div class="hero-copy">
      <span class="eyebrow"><span class="status-dot"></span> CONTENT. PEOPLE. POSSIBILITIES.</span>
      <h1><?= $e($themeSettings['home_title']) ?><br><span class="blue"><?= $e($themeSettings['home_accent']) ?></span></h1>
      <p class="intro"><?= $e($themeSettings['home_intro']) ?> <?= $e($themeSettings['home_intro_end']) ?></p>
      <div class="actions"><a class="button" href="/platform">Discover Sense CMS <span aria-hidden="true">↗</span></a><a class="secondary-button" href="/extensions">Explore the ecosystem <span aria-hidden="true">→</span></a></div>
    </div>
    <aside class="hero-editorial"><span class="section-index">01 / YOUR DIGITAL WORKSPACE</span><p><?= $e($themeSettings['home_detail']) ?></p><div class="hero-notes"><span>Your server</span><span>Your content</span><span>Your rules</span></div></aside>
  </section>
  <div class="container workspace-stage">
    <figure class="workspace-figure">
      <div class="workspace-frame"><div class="frame-bar"><span class="frame-dots" aria-hidden="true">● ● ●</span><span>Sense CMS / Workspace</span><span class="frame-status">Content &amp; communication</span></div><img src="/theme-assets/workspace.png" width="1440" height="940" alt="Sense CMS administration workspace with content overview, editorial queue and team communication. Example data from the development installation." fetchpriority="high"></div>
      <figcaption><span>THE REAL WORKSPACE. A CLEARER PICTURE.</span><span>Development interface · example data</span></figcaption>
    </figure>
    <a class="floating-note" href="/platform"><span class="note-symbol" aria-hidden="true">✓</span><span><strong>One place. A wider view.</strong><small>Content, teams and facilities</small></span><span aria-hidden="true">↗</span></a>
  </div>
</div>
<section class="product-principles container" aria-label="Platform principles"><div><span>01</span><strong>Content without clutter</strong><small>Pages, media &amp; publishing</small></div><div><span>02</span><strong>Connected teams</strong><small>Roles &amp; facility-scoped access</small></div><div><span>03</span><strong>Freedom to build</strong><small>Independent, replaceable themes</small></div><div><span>04</span><strong>Ownership by design</strong><small>Self-hosted on your infrastructure</small></div></section>
<section class="section container product-features">
  <div class="section-heading"><div><span class="eyebrow">LESS FRICTION. MORE POSSIBILITY.</span><h2><?= $e($themeSettings['features_title']) ?></h2></div><p><?= $e($themeSettings['features_intro']) ?></p></div>
  <div class="home-sections"><?php require __DIR__ . '/blocks.php'; ?></div>
</section>
<section class="ecosystem-section">
  <div class="container ecosystem-layout"><div class="ecosystem-copy"><span class="eyebrow">AN OPEN-ENDED FOUNDATION</span><h2><?= $e($themeSettings['ecosystem_title']) ?></h2><p><?= $e($themeSettings['ecosystem_text']) ?></p><a class="button inverse" href="/extensions">Meet the ecosystem <span aria-hidden="true">↗</span></a></div>
    <div class="ecosystem-map" aria-label="Sense CMS Core and four independent package types">
      <div class="ecosystem-core"><img src="/theme-assets/logo.svg" width="40" height="40" alt=""><div><span>THE SHARED FOUNDATION</span><strong>Sense CMS Core</strong></div></div>
      <div class="ecosystem-packages"><a href="/extensions/themes"><span>01 / PRESENTATION</span><strong>Themes ↗</strong><p>Your visual identity.</p></a><a href="/extensions/modules"><span>02 / CAPABILITIES</span><strong>Modules ↗</strong><p>Your application's features.</p></a><a href="/extensions/plugins"><span>03 / CONNECTIONS</span><strong>Plugins ↗</strong><p>Your service integrations.</p></a><a href="/extensions/addons"><span>04 / WORKSPACE</span><strong>Addons ↗</strong><p>Your additional tools.</p></a></div>
      <p class="ecosystem-disclosure">Package availability and compatibility are listed separately. <a href="/download">Check release status →</a></p>
    </div>
  </div>
</section>
<section class="section container ownership-section"><div><span class="eyebrow">YOUR PLATFORM, ON YOUR TERMS</span><h2><?= $e($themeSettings['ownership_title']) ?></h2><p><?= $e($themeSettings['ownership_text']) ?></p><a class="text-link" href="/docs/themes">Explore theme development <span aria-hidden="true">→</span></a></div><ol class="ownership-list"><li><span>01</span><div><strong>Keep your content</strong><p>Pages and their addresses belong to the CMS, not the theme.</p></div></li><li><span>02</span><div><strong>Shape the experience</strong><p>Change your presentation without replacing the administration panel.</p></div></li><li><span>03</span><div><strong>Choose your infrastructure</strong><p>Deploy on your own PHP server with a private application directory.</p></div></li></ol></section>
<section class="container product-closing"><div><span class="eyebrow">LET’S BUILD WHAT’S NEXT</span><h2><?= $e($themeSettings['closing_title']) ?></h2><p><?= $e($themeSettings['closing_text']) ?></p></div><div class="closing-actions"><a class="button" href="/contact">Talk about your project <span aria-hidden="true">↗</span></a><a class="text-link" href="/download">Development &amp; release status →</a></div></section>
