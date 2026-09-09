<?php

$marketplace = $marketplace ?? ['items' => [], 'summary' => [], 'pagination' => [], 'filters' => []];
$marketSummary = array_replace(['total' => 0, 'installed' => 0, 'updates' => 0, 'verified' => 0], (array) ($marketplace['summary'] ?? []));
$filters = array_replace(['q' => '', 'type' => 'all', 'price' => 'all', 'status' => 'all'], (array) ($marketplace['filters'] ?? []));
$types = ['all' => 'All', 'theme' => 'Theme', 'addon' => 'Add-on', 'plugin' => 'Plugin'];
$prices = ['all' => 'All', 'free' => 'Free', 'paid' => 'Paid'];
?>
<section class="sensecms-marketplace" data-marketplace-root data-csrf="<?= $escape($csrf) ?>">
    <?php
    $sectionHero = [
        'overline' => 'EXPERIENCE · VERIFIED EXTENSIONS',
        'title' => 'SenseCMS Marketplace',
        'description' => 'Discover, install and manage verified themes, add-ons and plugins from one clean component catalog.',
        'icon' => 'store',
        'status' => 'Protected lifecycle',
        'status_detail' => 'License · signature · checksum · recovery',
        'status_icon' => 'badge-check',
    ];
    require __DIR__ . '/console-section-hero.php';
    ?>

    <section class="sensecms-marketplace-summary" data-marketplace-summary>
        <?php foreach ([['package', 'Products', $marketSummary['total']], ['package-check', 'Installed', $marketSummary['installed']], ['arrow-up-circle', 'Updates', $marketSummary['updates']], ['shield-check', 'Verified', $marketSummary['verified']]] as [$icon, $label, $value]): ?>
            <article><i data-lucide="<?= $icon ?>"></i><span><strong><?= (int) $value ?></strong><small><?= $label ?></small></span></article>
        <?php endforeach; ?>
    </section>

    <section class="sensecms-marketplace-toolbar" data-marketplace-discovery>
        <div class="sensecms-marketplace-search-row">
            <label class="sensecms-marketplace-search">
                <span>Search Marketplace</span>
                <span><i data-lucide="search"></i><input type="search" data-marketplace-filter="q" value="<?= $escape($filters['q']) ?>" placeholder="Search themes, add-ons and plugins" autocomplete="off"><kbd>Ctrl K</kbd></span>
            </label>
            <label><span>Category</span><select data-marketplace-filter="type"><?php foreach ($types as $value => $label): ?><option value="<?= $value ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <label><span>Pricing</span><select data-marketplace-filter="price"><?php foreach ($prices as $value => $label): ?><option value="<?= $value ?>" <?= $filters['price'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <label><span>Status</span><select data-marketplace-filter="status"><option value="all">All</option><option value="installed" <?= $filters['status'] === 'installed' ? 'selected' : '' ?>>Installed</option><option value="updates" <?= $filters['status'] === 'updates' ? 'selected' : '' ?>>Updates</option><option value="verified" <?= $filters['status'] === 'verified' ? 'selected' : '' ?>>Verified</option></select></label>
        </div>
        <div class="sensecms-marketplace-chip-groups">
            <div><span>Filter by category</span><div role="group" aria-label="Filter by category"><?php foreach ($types as $value => $label): ?><button type="button" data-marketplace-chip="type:<?= $value ?>" aria-pressed="<?= $filters['type'] === $value ? 'true' : 'false' ?>" class="<?= $filters['type'] === $value ? 'is-active' : '' ?>"><?= $label ?></button><?php endforeach; ?></div></div>
            <div><span>Filter by pricing</span><div role="group" aria-label="Filter by pricing"><?php foreach ($prices as $value => $label): ?><button type="button" data-marketplace-chip="price:<?= $value ?>" aria-pressed="<?= $filters['price'] === $value ? 'true' : 'false' ?>" class="<?= $filters['price'] === $value ? 'is-active' : '' ?>"><?= $label ?></button><?php endforeach; ?></div></div>
        </div>
    </section>

    <div class="sensecms-marketplace-results-head"><div><span>VERIFIED CATALOG</span><h3>Available components</h3></div><p data-marketplace-result-count><?= (int) ($marketplace['pagination']['total'] ?? 0) ?> matching components</p></div>
    <section class="sensecms-marketplace-grid" data-marketplace-grid aria-live="polite"></section>
    <nav class="sensecms-marketplace-pagination" data-marketplace-pagination aria-label="Marketplace pages"></nav>

    <section class="sensecms-marketplace-security"><div><i data-lucide="shield-check"></i><span><strong>Every operation uses the protected package lifecycle</strong><small>Product entitlement, publisher signature, SHA-256 inventory, compatibility, dependencies and a private recovery package are verified before the installation changes.</small></span></div></section>

    <aside class="sensecms-marketplace-drawer" data-marketplace-drawer hidden aria-hidden="true"><button type="button" data-marketplace-close aria-label="Close component details"><i data-lucide="x"></i></button><div data-marketplace-detail></div></aside>
    <dialog class="sensecms-marketplace-license" data-marketplace-license aria-labelledby="marketplace-license-title">
        <header><span><i data-lucide="key-round"></i></span><button type="button" data-marketplace-license-close aria-label="Close"><i data-lucide="x"></i></button></header>
        <div><small>SECURE INSTALLATION</small><h2 id="marketplace-license-title">Enter your license key</h2><p>The key is verified directly by the SenseCMS distribution service and is never stored in this system.</p><strong data-marketplace-license-name></strong></div>
        <form data-marketplace-license-form><label for="marketplace-license-key">License key</label><span><i data-lucide="lock-keyhole"></i><input id="marketplace-license-key" name="license" type="text" maxlength="128" required autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Enter up to 128 characters"></span><p data-marketplace-license-status role="status" aria-live="polite" hidden></p><footer><button class="btn bg-default-150" type="button" data-marketplace-license-close>Cancel</button><button class="btn is-install" type="submit"><i data-lucide="package-check"></i>Verify and install</button></footer></form>
    </dialog>
    <script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" type="application/json" data-marketplace-bootstrap><?= json_encode($marketplace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
