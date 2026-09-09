<?php
declare(strict_types=1);
// Present existing CMS blocks; never use marketing copy as download authorisation.
$marketTypes = ['system'=>'Core','theme'=>'Theme','plugin'=>'Plugin','addon'=>'Add-on','module'=>'Module','application'=>'Application'];
$marketIcons = [
    'system'=>'m12 3 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5',
    'theme'=>'M3 4h18v16H3zM3 9h18M9 9v11',
    'plugin'=>'M8 3v5m8-5v5M6 8h12v3a6 6 0 0 1-6 6v4m-6-13v3a6 6 0 0 0 6 6',
    'addon'=>'M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm2-3v6m10-6v6M3 11h18m-14 4h3m4 0h3',
    'application'=>'M3 4h18v13H3zM8 21h8m-4-4v4',
    'module'=>'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
];
$marketItems = []; $marketOther = [];
foreach ($blocks as $block) {
    $data = (array) ($block['payload'] ?? []);
    if ($block['type'] !== 'text' || !preg_match('~^/extensions/catalog/(system|theme|plugin|addon|module|application)/[a-z0-9-]+$~D', (string) ($data['cta_url'] ?? ''), $match)) { $marketOther[] = $block; continue; }
    $doc = new DOMDocument();
    $doc->loadHTML('<!doctype html><meta charset="UTF-8"><body>' . \App\Core\HtmlSanitizer::sanitize((string) ($data['text'] ?? '')) . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $paragraphs = $doc->getElementsByTagName('p');
    $price = trim($doc->getElementsByTagName('strong')->item(0)?->textContent ?? '');
    // Unexpected editorial structures retain the standard renderer without data loss.
    if ($paragraphs->length !== 3 || !preg_match('~^(Free|USD [0-9]+ / year)$~D', $price)) { $marketOther[] = $block; continue; }
    $marketItems[] = ['title'=>(string)($data['title']??''),'url'=>$data['cta_url'],'type'=>$match[1],'price'=>$price,'pricing'=>$price==='Free'?'free':'paid','description'=>trim($paragraphs->item(1)->textContent),'status'=>trim($paragraphs->item(2)->textContent)];
}
?>
<div class="marketplace-shell"><div class="container marketplace" data-marketplace>
    <div class="market-summary"><p><strong><?= count($marketItems) ?></strong> products in this collection</p><span>Clear licence terms. Transparent release status.</span></div>
    <form class="market-filters" data-market-filters hidden role="search" aria-label="Search packages">
        <div class="market-fields"><label>Search marketplace<input type="search" name="search" placeholder="Search systems, themes and extensions" autocomplete="off"></label><label>Category<select name="category"><option value="all">All categories</option><?php foreach ($marketTypes as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></label><label>Pricing<select name="pricing"><option value="all">All prices</option><option value="free">Free</option><option value="paid">Paid</option></select></label></div>
        <div class="market-category-row"><span>Filter by category</span><div class="market-chips" role="group" aria-label="Quick category filters"><?php foreach (['all'=>'All']+$marketTypes as $key=>$label): ?><button type="button" data-market-category="<?= $key ?>" aria-pressed="<?= $key==='all'?'true':'false' ?>"><?= $label ?></button><?php endforeach; ?></div></div>
        <div class="market-filter-footer"><div class="market-chips" role="group" aria-label="Quick pricing filters"><?php foreach (['all'=>'All prices','free'=>'Free','paid'=>'Paid'] as $key=>$label): ?><button type="button" data-market-price="<?= $key ?>" aria-pressed="<?= $key==='all'?'true':'false' ?>"><?= $label ?></button><?php endforeach; ?></div><p role="status" aria-live="polite" aria-atomic="true" data-market-count><?= count($marketItems) ?> results</p><button class="market-reset" type="reset">Reset filters</button></div>
    </form>
    <div class="market-grid">
    <?php foreach ($marketItems as $item): ?>
        <article class="market-card" data-market-item data-category="<?= $item['type'] ?>" data-pricing="<?= $item['pricing'] ?>">
            <div class="market-card-top"><span class="market-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $marketIcons[$item['type']] ?>"/></svg></span><span class="market-type"><?= $marketTypes[$item['type']] ?></span></div>
            <h2><a data-market-open href="<?= $e($item['url']) ?>"><?= $e($item['title']) ?></a></h2>
            <p class="market-description"><?= $e($item['description']) ?></p>
            <div class="market-meta"><span class="market-price <?= $item['pricing']==='free'?'is-free':'' ?>"><?= $e($item['price']) ?></span><span class="market-license"><?= $item['pricing']==='free'?'CMS licence required':($item['type']==='system'?'System licence':'Separate licence') ?></span></div>
            <p class="market-availability"><?= $e($item['status']) ?></p>
            <div class="market-actions"><a class="market-action" data-market-open href="<?= $e($item['url']) ?>" aria-label="View details: <?= $e($item['title']) ?>">View details <span aria-hidden="true">＋</span></a><button class="market-action market-status-action" type="button" data-market-status hidden aria-label="Download status: <?= $e($item['title']) ?>">Download status <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4m-4 4v3"/></svg></button></div>
        </article>
    <?php endforeach; ?>
    </div>
    <div class="market-empty" data-market-empty hidden><h2>No matching packages</h2><p>Try a different search or reset the filters to see this collection.</p></div>
    <?php if ($marketOther): ?><div class="subpage-content"><?php $savedBlocks=$blocks; $blocks=$marketOther; require __DIR__.'/blocks.php'; $blocks=$savedBlocks; ?></div><?php endif; ?>
    <aside class="market-note"><span class="market-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6"/></svg></span><div><h2>A clear path from discovery to installation.</h2><p>Free packages require a valid Sense CMS licence. Paid extensions require their own licence. Public downloads are not open yet; each package page shows its current availability.</p></div></aside>
    <dialog class="market-dialog" data-market-dialog aria-labelledby="market-dialog-title" aria-describedby="market-dialog-description">
        <div class="market-dialog-body"><div class="market-dialog-top"><span data-dialog-icon></span><button type="button" class="market-dialog-close" data-market-close aria-label="Close package details" autofocus>×</button></div><span class="market-type" data-dialog-type></span><h2 id="market-dialog-title"></h2><p id="market-dialog-description"></p>
        <dl class="market-dialog-facts"><div><dt>Category</dt><dd data-dialog-category></dd></div><div><dt>Price</dt><dd data-dialog-price></dd></div><div><dt>Licence</dt><dd data-dialog-license></dd></div></dl>
        <section class="market-dialog-availability" aria-labelledby="market-dialog-status" tabindex="-1"><h3 id="market-dialog-status">Download availability</h3><p data-dialog-availability></p><p data-dialog-entitlement></p><p>Public downloads are not open yet. No licence key or payment is required to browse this catalogue.</p></section></div>
        <form class="market-download-form" data-download-form hidden action="/packages/download" method="post"><input type="hidden" name="csrf"><input type="hidden" name="product"><label>Installation domain<input name="domain" type="url" placeholder="https://www.example.com" required maxlength="253" autocomplete="url"></label><label>Licence key<input name="license_key" type="password" required minlength="32" maxlength="32" pattern="[A-Za-z0-9]{32}" autocomplete="off" spellcheck="false"></label><p>Your key is sent securely for verification and is not stored by this marketplace.</p><label class="market-preview-consent"><input type="checkbox" name="preview" required> I understand this is a development package, not a Stable release.</label><button type="submit" class="button">Verify and download</button><p data-download-result role="status" aria-live="polite"></p></form>
        <div class="market-dialog-footer"><button type="button" class="market-download-unavailable" disabled>Download unavailable</button><button class="button" type="button" data-market-close>Back to results</button></div>
    </dialog>
</div></div>
