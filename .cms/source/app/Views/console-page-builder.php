<?php
$builderLanguages = array_values($languages ?? []);
$defaultLanguage = array_values(array_filter($builderLanguages, static fn(array $language): bool => (bool) ($language['is_default'] ?? false)))[0] ?? ($builderLanguages[0] ?? ['locale'=>'en','name'=>'English','native_name'=>'English','flag'=>'']);
$defaultLocale = (string) $defaultLanguage['locale'];
$pageTranslation = $builderDocument['translations'][$defaultLocale] ?? reset($builderDocument['translations']) ?: [];
$pageLocation = array_values(array_filter($pages, static fn(array $item): bool => (int) $item['id'] === (int) $builderDocument['id']))[0];
$previewUrl = '/' . rawurlencode($defaultLocale) . '/facilities/' . rawurlencode($pageLocation['city_slug']) . '/' . rawurlencode($pageLocation['facility_slug']) . '/' . rawurlencode((string) ($pageTranslation['slug'] ?? $builderDocument['slug'] ?? ''));
$previewUrl = (string) ($builderDocument['public_path'] ?? $previewUrl);
$builderPayload = ['page'=>['id'=>(int)$builderDocument['id'],'title'=>(string)($builderDocument['title']??'Untitled page'),'slug'=>(string)($builderDocument['slug']??''),'status'=>(string)$builderDocument['status'],'version'=>(int)$builderDocument['builder_version'],'preview_url'=>$previewUrl],'pages'=>$pages,'languages'=>$builderLanguages,'catalog'=>$builderCatalog,'blocks'=>$builderDocument['blocks'],'globals'=>array_values($builderGlobals??[]),'revisions'=>array_values($builderRevisions??[]),'theme'=>['slug'=>(string)($builderTheme['slug']??''),'name'=>(string)($builderTheme['name']??'Theme'),'version'=>(string)($builderTheme['version']??'')],'csrf'=>$csrf];
?>
<section class="sensecms-content-editor-workspace">
    <?php
    $builderSectionCount = count((array) ($builderDocument['blocks'] ?? []));
    $sectionHero = [
        'overline' => 'CONTENT · VISUAL COMPOSITION',
        'title' => 'Page Builder',
        'description' => 'Build multilingual pages from portable, theme-safe SenseCMS modules while keeping every section independently manageable.',
        'icon' => 'panels-top-left',
        'status' => ucfirst((string) ($builderDocument['status'] ?? 'draft')) . ' content',
        'status_detail' => $builderSectionCount . ' ' . ($builderSectionCount === 1 ? 'section' : 'sections') . ' · ' . count($builderCatalog) . ' available modules',
        'status_icon' => ($builderDocument['status'] ?? '') === 'published' ? 'circle-check' : 'pencil-line',
    ];
    require __DIR__ . '/console-section-hero.php';
    ?>
<section class="sensecms-builder" data-page-builder>
    <script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" type="application/json" data-builder-payload><?= json_encode($builderPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <header class="sensecms-builder-toolbar card">
        <div class="sensecms-builder-document">
            <span class="sensecms-builder-icon"><i data-lucide="panels-top-left"></i></span>
            <label><small>Editing page</small><select data-builder-page aria-label="Select page"><?php foreach ($pages as $builderPage): ?><option value="<?= (int)$builderPage['id'] ?>" <?= (int)$builderPage['id']===(int)$builderDocument['id']?'selected':'' ?>><?= $escape($builderPage['title']??'Untitled page') ?></option><?php endforeach; ?></select></label>
            <span class="sensecms-builder-state-stack">
                <span class="sensecms-builder-status <?= $builderDocument['status']==='published'?'is-published':'' ?>"><i data-lucide="<?= $builderDocument['status']==='published'?'circle-check':'pencil-line' ?>"></i><?= $escape(ucfirst((string)$builderDocument['status'])) ?></span>
                <span class="sensecms-builder-save-state" data-builder-state><i></i><b>All changes saved</b></span>
            </span>
        </div>
        <div class="sensecms-builder-actions">
            <button class="btn bg-default-150" type="button" data-builder-history><i data-lucide="history"></i>Revisions</button>
            <button class="btn bg-default-150" type="button" data-builder-preview><i data-lucide="monitor-smartphone"></i>Preview</button>
            <button class="btn bg-primary text-white" type="button" data-builder-save><i data-lucide="save"></i>Save changes</button>
        </div>
    </header>
    <nav class="sensecms-builder-locales" aria-label="Content language" data-builder-locales></nav>
    <div class="sensecms-builder-grid">
        <main class="sensecms-builder-canvas">
            <div class="sensecms-builder-canvas-head"><div><span>Page structure</span><h5><?= $escape($builderDocument['title']??'Untitled page') ?></h5><p>Drag sections into the required order. Every section has independent content for each active language.</p></div><div><b data-builder-count>0</b><small>sections</small></div></div>
            <div class="sensecms-builder-list" data-builder-list></div>
            <div class="sensecms-builder-empty" data-builder-empty hidden><i data-lucide="layout-template"></i><strong>Start building this page</strong><span>Choose a section from the library. You can rearrange, duplicate and configure it immediately.</span></div>
        </main>
        <aside class="sensecms-builder-library card">
            <header><div><small>System module library</small><strong>SenseCMS</strong><span><?= count($builderCatalog) ?> portable modules · <?= $escape($builderTheme['name']??'Theme') ?> presentation · plugin components</span></div><i data-lucide="badge-check"></i></header>
            <nav class="sensecms-builder-library-tabs" aria-label="Section library"><button type="button" class="is-active" data-library-tab="modules"><i data-lucide="layout-grid"></i>Modules</button><button type="button" data-library-tab="shared"><i data-lucide="combine"></i>Shared <span data-builder-global-count><?= count($builderGlobals??[]) ?></span></button></nav>
            <section data-library-panel="modules"><div class="sensecms-builder-library-search"><i data-lucide="search"></i><input type="search" placeholder="Find a section…" data-builder-search></div><div class="sensecms-builder-library-list" data-builder-library></div></section>
            <section data-library-panel="shared" hidden><div class="sensecms-builder-library-search"><i data-lucide="search"></i><input type="search" placeholder="Find a shared section…" data-builder-global-search></div><div class="sensecms-builder-library-list" data-builder-global-library></div></section>
            <footer><i data-lucide="shield-check"></i><p><strong>Portable, theme-safe content</strong><span>SenseCMS modules remain available after a theme change. Plugin components are loaded only from active, trusted extensions.</span></p></footer>
        </aside>
    </div>
    <div class="sensecms-builder-modal" data-builder-preview-dialog hidden><div class="sensecms-builder-dialog is-preview" role="dialog" aria-modal="true" aria-labelledby="builder-preview-title"><header><div><small>Responsive preview</small><strong id="builder-preview-title"><?= $escape($builderDocument['title']??'Untitled page') ?></strong></div><button type="button" data-dialog-close aria-label="Close preview"><i data-lucide="x"></i></button></header><nav class="sensecms-builder-devices" aria-label="Preview width"><button type="button" class="is-active" data-preview-device="desktop"><i data-lucide="monitor"></i>Desktop</button><button type="button" data-preview-device="tablet"><i data-lucide="tablet"></i>Tablet</button><button type="button" data-preview-device="mobile"><i data-lucide="smartphone"></i>Mobile</button><a href="<?= $escape($previewUrl) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Open page</a></nav><div class="sensecms-builder-preview-stage" data-preview-stage="desktop"><iframe title="Responsive page preview" data-builder-preview-frame loading="lazy"></iframe></div></div></div>
    <div class="sensecms-builder-modal" data-builder-history-dialog hidden><div class="sensecms-builder-dialog" role="dialog" aria-modal="true" aria-labelledby="builder-history-title"><header><div><small>Page history</small><strong id="builder-history-title">Revisions</strong></div><button type="button" data-dialog-close aria-label="Close history"><i data-lucide="x"></i></button></header><div class="sensecms-builder-dialog-copy"><i data-lucide="shield-check"></i><p><strong>Safe, non-destructive restore</strong><span>A restored revision becomes a new version. Shared sections are restored as independent sections so other pages never change unexpectedly.</span></p></div><div class="sensecms-builder-revisions" data-builder-revisions></div></div></div>
    <div class="sensecms-builder-modal" data-builder-media-dialog hidden><div class="sensecms-builder-dialog is-media" role="dialog" aria-modal="true" aria-labelledby="builder-media-title"><header><div><small>Visual asset picker</small><strong id="builder-media-title">Media library</strong></div><button type="button" data-dialog-close aria-label="Close media library"><i data-lucide="x"></i></button></header><div class="sensecms-builder-media-toolbar"><label><i data-lucide="search"></i><input type="search" placeholder="Search files…" data-media-search></label><select data-media-kind aria-label="Media type"><option value="all">All media</option><option value="image">Images</option><option value="video">Videos</option></select></div><div class="sensecms-builder-media-grid" data-media-grid></div><div class="sensecms-builder-media-pagination" data-media-pagination></div></div></div>
    <div class="sensecms-builder-modal" data-builder-share-dialog hidden><form class="sensecms-builder-dialog is-compact" data-builder-share-form role="dialog" aria-modal="true" aria-labelledby="builder-share-title"><header><div><small>Reusable content</small><strong id="builder-share-title">Create shared section</strong></div><button type="button" data-dialog-close aria-label="Close"><i data-lucide="x"></i></button></header><label class="sensecms-builder-share-name"><span>Section name</span><input class="form-input" type="text" name="name" minlength="2" maxlength="120" required placeholder="e.g. Admissions call to action"></label><p>Changes made to a linked shared section are reflected everywhere it is used.</p><footer><button type="button" class="btn bg-default-150" data-dialog-close>Cancel</button><button type="submit" class="btn bg-primary text-white"><i data-lucide="combine"></i>Create shared section</button></footer></form></div>
</section>
</section>
