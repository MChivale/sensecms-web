<?php
declare(strict_types=1);
$index = array_replace(['count'=>0,'sections'=>0,'built_at'=>'','items'=>[]], is_array($searchIndex ?? null) ? $searchIndex : []);
$builtAt = $index['built_at'] !== '' ? date('M j, Y · H:i', strtotime((string) $index['built_at'])) : 'Not built yet';
?>
<section class="sensecms-unified-workspace">
    <?php $sectionHero=['overline'=>'SYSTEM · ADMINISTRATION DISCOVERY','title'=>'Search index','description'=>'Keep Quick Search synchronized with platform functions, installed themes, plugins and add-ons.','icon'=>'scan-search','status'=>(int)$index['count'].' indexed destinations','status_detail'=>'Last rebuilt '.$builtAt,'status_icon'=>'circle-check'];require __DIR__.'/console-section-hero.php'; ?>
    <div class="grid gap-5 xl:grid-cols-3">
        <section class="card xl:col-span-2">
            <div class="card-header"><div><h6 class="card-title">Dynamic administration index</h6><p class="mt-1 text-sm font-normal text-default-500">The index is permission-aware and refreshes automatically when the installed extension inventory changes.</p></div></div>
            <div class="card-body">
                <div class="sensecms-search-index-stats">
                    <article><i data-lucide="files"></i><span><strong><?= (int)$index['count'] ?></strong><small>Destinations</small></span></article>
                    <article><i data-lucide="layout-grid"></i><span><strong><?= (int)$index['sections'] ?></strong><small>Sections</small></span></article>
                    <article><i data-lucide="clock-3"></i><span><strong><?= $escape($builtAt) ?></strong><small>Last rebuild</small></span></article>
                </div>
                <div class="sensecms-search-index-groups">
                    <?php foreach(array_count_values(array_column((array)$index['items'],'section')) as $section=>$count): ?>
                        <span><i data-lucide="folder-search"></i><?= $escape($section) ?><b><?= (int)$count ?></b></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <aside class="card h-fit">
            <div class="card-header"><div><h6 class="card-title">Index maintenance</h6><p class="mt-1 text-sm font-normal text-default-500">A manual rebuild is safe and does not modify content.</p></div></div>
            <div class="card-body">
                <form method="post" action="/system/search-index/rebuild"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="btn w-full bg-primary text-white" type="submit"><i data-lucide="refresh-cw"></i>Rebuild search index</button></form>
                <p class="mt-4 text-xs leading-5 text-default-500">Quick Search also compares an extension fingerprint on demand, so installation, activation, deactivation and rollback cannot leave stale destinations behind.</p>
            </div>
        </aside>
    </div>
</section>
