<?php
$builderLanguages = array_values($languages ?? []);
$defaultLanguage = array_values(array_filter($builderLanguages, static fn(array $language): bool => (bool) ($language['is_default'] ?? false)))[0] ?? ($builderLanguages[0] ?? ['locale'=>'en','name'=>'English','native_name'=>'English','flag'=>'']);
$defaultLocale = (string) $defaultLanguage['locale'];
$pageTranslation = $builderDocument['translations'][$defaultLocale] ?? reset($builderDocument['translations']) ?: [];
$pageLocation = array_values(array_filter($pages, static fn(array $item): bool => (int) $item['id'] === (int) $builderDocument['id']))[0];
$previewUrl = '/' . rawurlencode($defaultLocale) . '/facilities/' . rawurlencode($pageLocation['city_slug']) . '/' . rawurlencode($pageLocation['facility_slug']) . '/' . rawurlencode((string) ($pageTranslation['slug'] ?? $builderDocument['slug'] ?? ''));
$previewUrl = (string) ($builderDocument['public_path'] ?? $previewUrl);
$builderPayload = ['page'=>['id'=>(int)$builderDocument['id'],'title'=>(string)($builderDocument['title']??'Untitled page'),'slug'=>(string)($builderDocument['slug']??''),'status'=>(string)$builderDocument['status'],'version'=>(int)$builderDocument['builder_version'],'preview_url'=>$previewUrl,'live_preview_url'=>'/content/builder/'.(int)$builderDocument['id'].'/live-preview'],'pages'=>$pages,'languages'=>$builderLanguages,'catalog'=>$builderCatalog,'patterns'=>array_values($builderPatterns??[]),'layouts'=>array_values($builderLayouts??[]),'appearance'=>$builderAppearance??[],'collection_options'=>$builderCollections??[],'quality'=>$builderQuality??[],'blocks'=>$builderDocument['blocks'],'draft'=>is_array($builderDraft??null)?$builderDraft:null,'clipboard'=>is_array($builderClipboard??null)?$builderClipboard:null,'globals'=>array_values($builderGlobals??[]),'revisions'=>array_values($builderRevisions??[]),'collaboration'=>$builderCollaboration??[],'collaborators'=>array_values($builderCollaborators??[]),'ai_providers'=>array_values($builderAiProviders??[]),'ai_actions'=>$builderAiActions??[],'permissions'=>$builderPermissions??['edit'=>true,'review'=>false,'ai'=>false],'theme'=>['slug'=>(string)($builderTheme['slug']??''),'name'=>(string)($builderTheme['name']??'Theme'),'version'=>(string)($builderTheme['version']??'')],'csrf'=>$csrf];
$builderCanEdit = !empty($builderPermissions['edit']);
$builderPayload['page'] = array_replace($builderPayload['page'], $builderDocument['publication'] ?? []);
$builderPayload['page']['timezone'] = $builderDocument['preview_timezone'] ?? 'UTC';
?>
<section class="sensecms-content-editor-workspace">
    <?php
    $builderSectionCount = count((array) ($builderDocument['blocks'] ?? []));
    $sectionHero = [
        'overline' => 'CONTENT · VISUAL COMPOSITION',
        'title' => 'Page Builder',
        'description' => 'Build multilingual pages from theme-safe Sense CMS sections while keeping every section independently manageable.',
        'icon' => 'panels-top-left',
        'status' => ucfirst((string) ($builderDocument['status'] ?? 'draft')) . ' content',
        'status_detail' => $builderSectionCount . ' ' . ($builderSectionCount === 1 ? 'section' : 'sections') . ' · ' . count($builderCatalog) . ' available sections',
        'status_icon' => ($builderDocument['status'] ?? '') === 'published' ? 'circle-check' : 'pencil-line',
        'status_neutral' => ($builderDocument['status'] ?? '') !== 'published',
    ];
    require __DIR__ . '/console-section-hero.php';
    ?>
<section class="sensecms-builder" data-page-builder>
    <script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" type="application/json" data-builder-payload><?= json_encode($builderPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <header class="sensecms-builder-toolbar card">
        <div class="sensecms-builder-document">
            <span class="sensecms-builder-icon"><i data-lucide="panels-top-left"></i></span>
            <label class="sensecms-document-picker">
                <small class="sensecms-editing-label"><?= $escape($uiText('Editing page')) ?></small>
                <select data-builder-page aria-label="<?= $escape($uiText('Select page')) ?>">
                    <button type="button"><selectedcontent></selectedcontent></button>
                    <?php foreach ($pages as $builderPage): ?><option value="<?= (int)$builderPage['id'] ?>" <?= (int)$builderPage['id']===(int)$builderDocument['id']?'selected':'' ?>><?= $escape($builderPage['title']??$uiText('Untitled page')) ?></option><?php endforeach; ?>
                </select>
            </label>
            <span class="sensecms-builder-state-stack">
                <span class="sensecms-builder-save-state" data-builder-state role="status" aria-live="polite" aria-atomic="true"><i></i><span><small data-state-caption><?= $escape($uiText('Draft')) ?></small><b></b><small data-builder-state-detail></small></span></span>
                <button type="button" class="btn bg-default-150" data-retry-draft hidden><?= $escape($uiText('Retry')) ?></button>
                <span class="sensecms-builder-save-state" data-visitor-state role="status" aria-live="polite" aria-atomic="true"><i></i><span><small data-state-caption><?= $escape($uiText('Visitors')) ?></small><b></b></span></span>
            </span>
        </div>
        <div class="sensecms-builder-actions">
            <span class="sensecms-builder-history-actions" aria-label="<?= $escape($uiText('Editing history')) ?>">
                <button class="btn bg-default-150" type="button" data-builder-undo disabled title="<?= $escape($uiText('Undo (Ctrl+Z)')) ?>" aria-label="<?= $escape($uiText('Undo')) ?>"><i data-lucide="undo-2"></i><span><?= $escape($uiText('Undo')) ?></span></button>
                <button class="btn bg-default-150" type="button" data-builder-redo disabled title="<?= $escape($uiText('Redo (Ctrl+Shift+Z)')) ?>" aria-label="<?= $escape($uiText('Redo')) ?>"><i data-lucide="redo-2"></i><span><?= $escape($uiText('Redo')) ?></span></button>
            </span>
            <button class="btn bg-default-150" type="button" data-builder-history><i data-lucide="history"></i><?= $escape($uiText('Revisions')) ?></button>
            <button class="btn bg-default-150 sensecms-builder-quality-open" type="button" data-builder-quality-open><i data-lucide="scan-search"></i><?= $escape($uiText('Quality')) ?> <span data-builder-quality-action-count><?= (int)(($builderQuality['counts']['error']??0)+($builderQuality['counts']['warning']??0)) ?></span></button>
            <button class="btn bg-default-150" type="button" data-builder-preview><i data-lucide="monitor-smartphone"></i><?= $escape($uiText('Preview')) ?></button>
            <?php if ($builderCanEdit): ?><button class="btn bg-default-150" type="button" data-changes-open aria-haspopup="dialog" hidden><i data-lucide="list-checks"></i><?= $escape($uiText('Review changes')) ?></button><?php endif; ?>
            <button class="btn bg-primary text-white" type="button" data-builder-save disabled <?= $builderCanEdit?'':'hidden' ?>><i data-lucide="save"></i><span data-save-label></span></button>
        </div>
    </header>
    <nav class="sensecms-builder-locales" aria-label="<?= $escape($uiText('Content language')) ?>" data-builder-locales></nav>
    <div class="sensecms-builder-grid">
        <main class="sensecms-builder-canvas">
            <button type="button" class="btn bg-default-150 sensecms-builder-jump" data-builder-jump="tools"><i data-lucide="sliders-horizontal"></i><?= $escape($uiText('Go to tools')) ?></button>
            <div class="sensecms-builder-canvas-head"><div><span><?= $escape($uiText('Page Builder')) ?></span><h5><?= $escape($builderDocument['title']??$uiText('Untitled page')) ?></h5><p data-builder-canvas-help><?= $escape($uiText('Drag sections into the required order. Every section has independent content for each active language.')) ?></p></div><div class="sensecms-builder-view-switch" role="group" aria-label="<?= $escape($uiText('Builder view')) ?>"><button type="button" class="is-active" data-builder-view="structure"><i data-lucide="list-tree"></i><?= $escape($uiText('Structure')) ?></button><button type="button" data-builder-view="live"><i data-lucide="scan-line"></i><?= $escape($uiText('Live Canvas')) ?></button><span><b data-builder-count>0</b><small><?= $escape($uiText('sections')) ?></small></span></div></div>
            <section data-builder-structure>
                <div class="sensecms-builder-list" data-builder-list></div>
            </section>
            <section class="sensecms-builder-live" data-builder-live hidden>
                <nav class="sensecms-builder-live-toolbar" aria-label="<?= $escape($uiText('Live Canvas controls')) ?>"><span><i data-lucide="shield-check"></i><b data-builder-live-state><?= $escape($uiText('Private draft preview')) ?></b></span><div role="group" aria-label="<?= $escape($uiText('Canvas width')) ?>"><button type="button" class="is-active" data-live-device="desktop" title="<?= $escape($uiText('Desktop')) ?>"><i data-lucide="monitor"></i><span><?= $escape($uiText('Desktop')) ?></span></button><button type="button" data-live-device="tablet" title="<?= $escape($uiText('Tablet')) ?>"><i data-lucide="tablet"></i><span><?= $escape($uiText('Tablet')) ?></span></button><button type="button" data-live-device="mobile" title="<?= $escape($uiText('Mobile')) ?>"><i data-lucide="smartphone"></i><span><?= $escape($uiText('Mobile')) ?></span></button></div><a href="<?= $escape($previewUrl) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i><?= $escape($uiText('Public page')) ?></a></nav>
                <?php $scheduleZone = $builderDocument['preview_timezone'] ?? 'UTC'; ?>
                <details class="sensecms-builder-schedule-preview" data-schedule-tools>
                    <summary><span data-schedule-label><?= $escape($uiText('Preview at a date and time')) ?></span></summary>
                    <form data-schedule-form>
                        <label><?= $escape($uiText('Date and time')) ?><input class="form-input" type="datetime-local" data-schedule-at required></label>
                        <label><?= $escape($uiText('Time zone')) ?><select class="form-input" data-schedule-clock><option value="facility"><?= $escape($scheduleZone) ?> (site)</option><option value="schedule"><?= $escape($uiText('Section schedule time (Inspector)')) ?></option><?php if ($scheduleZone !== 'UTC'): ?><option value="utc">UTC</option><?php endif; ?></select></label>
                        <button type="submit" class="btn bg-default-150"><?= $escape($uiText('Show scheduled preview')) ?></button>
                        <button type="button" class="btn bg-default-150" data-schedule-clear hidden><?= $escape($uiText('Return to editing view')) ?></button>
                    </form>
                    <small><?= $escape($uiText('Preview the current draft with page publication and section visibility evaluated at this time.')) ?></small>
                </details>
                <div class="sensecms-builder-live-stage" data-live-stage="desktop"><iframe title="<?= $escape($uiText('Live page canvas')) ?>" data-builder-live-frame loading="lazy"></iframe></div>
            </section>
            <div class="sensecms-builder-empty" data-builder-empty hidden><i data-lucide="layout-template"></i><strong><?= $escape($uiText('This page has no sections')) ?></strong><span><?= $builderCanEdit?$uiText('Add a section or choose a ready-made pattern to get started.'):$uiText('Sections appear here when an editor adds content.') ?></span></div>
            <?php if ($builderCanEdit): ?>
                <button type="button" class="sensecms-builder-gap" data-builder-append><i data-lucide="circle-plus"></i> <?= $escape($uiText('Add section')) ?></button>
                <dialog class="sensecms-builder-header-dialog" data-header-dialog aria-labelledby="sensecms-header-title">
                    <h3 id="sensecms-header-title"><?= $escape($uiText('Page header')) ?></h3><p></p>
                    <footer><button type="button" class="btn bg-default-150"><?= $escape($uiText('Back to canvas')) ?></button><button type="button" class="btn bg-primary text-white" data-header-go></button></footer>
                </dialog>
                <dialog class="sensecms-builder-inline" data-inline-dialog aria-labelledby="sensecms-inline-label">
                    <div class="sensecms-inline-fields">
                        <label for="sensecms-inline-value" id="sensecms-inline-label"></label>
                        <textarea id="sensecms-inline-value" rows="2" required></textarea>
                        <div class="sensecms-rich-field" data-inline-rich hidden><div data-rich-host></div></div>
                        <footer><small role="status"></small><button type="button" class="btn bg-default-150" data-inline-cancel><?= $escape($uiText('Cancel')) ?></button><button class="btn bg-primary text-white" type="button" data-inline-apply><?= $escape($uiText('Apply')) ?></button></footer>
                    </div>
                </dialog>
                <dialog class="sensecms-builder-header-dialog" data-element-dialog aria-labelledby="sensecms-element-title">
                    <form><h3 id="sensecms-element-title"></h3><p data-element-scope></p><fieldset data-element-fields></fieldset>
                    <p role="status"></p><footer><button type="button" class="btn bg-default-150" data-element-reset hidden><?= $escape($uiText('Restore original')) ?></button><button type="button" class="btn bg-default-150" data-element-cancel><?= $escape($uiText('Cancel')) ?></button><button type="submit" class="btn bg-primary text-white" data-element-apply><?= $escape($uiText('Apply')) ?></button></footer></form>
                </dialog>
                <dialog class="sensecms-builder-inserter" data-builder-inserter aria-labelledby="sensecms-insert-title">
                    <header><div><small data-insert-position></small><h3 id="sensecms-insert-title"><?= $escape($uiText('Add to page')) ?></h3></div><button type="button" class="btn bg-default-150" aria-label="<?= $escape($uiText('Close')) ?>" data-insert-close><i data-lucide="x"></i></button></header>
                    <input type="search" class="form-input" data-insert-search aria-label="<?= $escape($uiText('Search sections or patterns')) ?>" placeholder="<?= $escape($uiText('Search sections or patterns')) ?>" autofocus>
                    <nav class="sensecms-builder-view-switch" aria-label="<?= $escape($uiText('Choose what to add')) ?>"><button type="button" data-insert-kind="sections" aria-pressed="true"><?= $escape($uiText('Sections')) ?></button><button type="button" data-insert-kind="patterns" aria-pressed="false"><?= $escape($uiText('Patterns')) ?></button></nav>
                    <div class="sensecms-builder-insert-results" data-insert-results></div>
                </dialog>
            <?php endif; ?>
        </main>
        <aside class="sensecms-builder-library card">
            <button type="button" class="btn bg-default-150 sensecms-builder-jump" data-builder-jump="canvas"><i data-lucide="arrow-up"></i><?= $escape($uiText('Go to canvas')) ?></button>
            <button type="button" class="btn bg-default-150 sensecms-builder-back" data-builder-back><i data-lucide="arrow-left"></i><?= $escape($uiText('Back to page')) ?></button>
            <header><div><small><?= $escape($uiText('Section library')) ?></small><strong>SenseCMS</strong><span><?= count($builderCatalog) ?> available sections · <?= $escape($builderTheme['name']??'Theme') ?> presentation · plugin components</span></div><i data-lucide="badge-check"></i></header>
            <nav class="sensecms-builder-workspaces" aria-label="<?= $escape($uiText('Page Builder tools')) ?>">
                <button type="button" data-tool-workspace="edit" aria-pressed="true"><i data-lucide="pencil"></i><?= $escape($uiText('Edit')) ?></button>
                <button type="button" data-tool-workspace="build" aria-pressed="false"><i data-lucide="blocks"></i><?= $escape($uiText('Build')) ?></button>
                <button type="button" data-tool-workspace="review" aria-pressed="false"><i data-lucide="list-checks"></i><?= $escape($uiText('Review')) ?></button>
            </nav>
            <nav class="sensecms-builder-library-tabs" aria-label="<?= $escape($uiText('Editing tools')) ?>" data-tool-group="edit">
                <button type="button" data-library-tab="inspector"><i data-lucide="sliders-horizontal"></i><?= $escape($uiText('Inspector')) ?></button>
                <button type="button" class="is-active" data-library-tab="outline"><i data-lucide="list-tree"></i><?= $escape($uiText('Navigator')) ?> <span data-builder-outline-count><?= $builderSectionCount ?></span></button>
                <?php if(!empty($builderPermissions['ai'])): ?><button type="button" data-library-tab="ai"><i data-lucide="sparkles"></i><?= $escape($uiText('AI Copilot')) ?></button><?php endif; ?>
            </nav>
            <nav class="sensecms-builder-library-tabs" aria-label="<?= $escape($uiText('Building tools')) ?>" data-tool-group="build" hidden>
                <button type="button" data-library-tab="modules"><i data-lucide="layout-grid"></i><?= $escape($uiText('Sections')) ?></button>
                <button type="button" data-library-tab="patterns"><i data-lucide="layout-template"></i><?= $escape($uiText('Patterns')) ?> <span><?= count($builderPatterns??[]) ?></span></button>
                <button type="button" data-library-tab="layouts"><i data-lucide="columns-3"></i><?= $escape($uiText('Layouts')) ?></button>
                <button type="button" data-library-tab="shared"><i data-lucide="combine"></i><?= $escape($uiText('Shared')) ?> <span data-builder-global-count><?= count($builderGlobals??[]) ?></span></button>
            </nav>
            <nav class="sensecms-builder-library-tabs" aria-label="<?= $escape($uiText('Review tools')) ?>" data-tool-group="review" hidden>
                <button type="button" data-library-tab="review"><i data-lucide="messages-square"></i><?= $escape($uiText('Comments')) ?> <span data-builder-review-count><?= (int)($builderCollaboration['open_comments']??0) ?></span></button>
                <button type="button" data-library-tab="quality"><i data-lucide="scan-search"></i><?= $escape($uiText('Quality')) ?> <span data-builder-quality-count><?= count($builderQuality['issues']??[]) ?></span></button>
            </nav>
            <section data-library-panel="inspector" hidden><div class="sensecms-builder-inspector" data-builder-inspector><div class="sensecms-builder-global-empty"><i data-lucide="mouse-pointer-click"></i><strong><?= $escape($uiText('Select a section on the canvas')) ?></strong><span><?= $escape($uiText('Its content, visibility and responsive settings will appear here.')) ?></span></div></div></section>
            <section data-library-panel="review" hidden><div class="sensecms-builder-review" data-builder-review><div class="sensecms-builder-global-empty"><i data-lucide="messages-square"></i><strong><?= $escape($uiText('Select a section to review')) ?></strong><span><?= $escape($uiText('Comments, assignment and approval status are stored independently for every section.')) ?></span></div></div></section>
            <?php if(!empty($builderPermissions['ai'])): ?><section data-library-panel="ai" hidden><div class="sensecms-builder-ai" data-builder-ai><header><i data-lucide="sparkles"></i><div><small><?= $escape($uiText('CORE AI COPILOT')) ?></small><strong><?= $escape($uiText('Review-first assistance')) ?></strong><span><?= $escape($uiText('AI creates a proposal. It never saves or publishes your page.')) ?></span></div></header><?php if(empty($builderAiProviders)): ?><div class="sensecms-builder-ai-empty"><i data-lucide="plug-zap"></i><strong><?= $escape($uiText('No Builder provider configured')) ?></strong><span><?= $escape($uiText('Configure and enable at least one provider for Page Builder in AI assistant.')) ?></span><a class="btn bg-default-150" href="/ai"><i data-lucide="settings-2"></i><?= $escape($uiText('Configure providers')) ?></a></div><?php else: ?><form data-builder-ai-form><label><span><?= $escape($uiText('Provider and model')) ?></span><select name="provider" required><?php foreach($builderAiProviders as$provider): ?><option value="<?= $escape($provider['slug']) ?>"><?= $escape($provider['name'].' · '.$provider['model']) ?><?= empty($provider['verified'])?' · not verified':'' ?></option><?php endforeach; ?></select></label><div class="sensecms-builder-ai-pair"><label><span><?= $escape($uiText('Action')) ?></span><select name="action" required><?php foreach($builderAiActions as$key=>$action): ?><option value="<?= $escape($key) ?>" data-scopes="<?= $escape(implode(',',(array)$action['scope'])) ?>"><?= $escape($action['label']) ?></option><?php endforeach; ?></select></label><label><span><?= $escape($uiText('Scope')) ?></span><select name="scope"><option value="section"><?= $escape($uiText('Selected section')) ?></option><option value="page"><?= $escape($uiText('Whole page')) ?></option></select></label></div><div class="sensecms-builder-ai-target"><i data-lucide="mouse-pointer-click"></i><span><small><?= $escape($uiText('Current target')) ?></small><strong data-builder-ai-target><?= $escape($uiText('No section selected')) ?></strong></span></div><div class="sensecms-builder-ai-pair"><label><span><?= $escape($uiText('Language')) ?></span><select name="locale"><?php foreach($builderLanguages as$language): ?><option value="<?= $escape($language['locale']) ?>" <?= $language['locale']===$defaultLocale?'selected':'' ?>><?= $escape($language['native_name']??$language['name']??strtoupper($language['locale'])) ?></option><?php endforeach; ?></select></label><label><span><?= $escape($uiText('Tone')) ?></span><select name="tone"><option value=""><?= $escape($uiText('Keep current tone')) ?></option><option value="professional"><?= $escape($uiText('Professional')) ?></option><option value="friendly"><?= $escape($uiText('Friendly')) ?></option><option value="concise"><?= $escape($uiText('Concise')) ?></option><option value="confident"><?= $escape($uiText('Confident')) ?></option><option value="editorial"><?= $escape($uiText('Editorial')) ?></option></select></label></div><label><span><?= $escape($uiText('Audience')) ?></span><input name="audience" maxlength="120" placeholder="<?= $escape($uiText('e.g. Small business owners')) ?>"></label><label><span><?= $escape($uiText('Instructions')) ?></span><textarea name="instructions" maxlength="2000" rows="4" placeholder="Describe the goal, facts to preserve and desired result."></textarea></label><div class="sensecms-builder-ai-guard"><i data-lucide="shield-check"></i><span><strong><?= $escape($uiText('Human approval required')) ?></strong><small><?= $escape($uiText('The proposal stays in your unsaved Builder draft until you apply and manually save it.')) ?></small></span></div><button class="btn bg-primary text-white" type="submit" data-builder-ai-run><i data-lucide="sparkles"></i><?= $escape($uiText('Generate proposal')) ?></button><button class="btn bg-default-150" type="button" data-builder-ai-cancel hidden><i data-lucide="x"></i><?= $escape($uiText('Cancel request')) ?></button></form><div class="sensecms-builder-ai-result" data-builder-ai-result hidden></div><?php endif; ?></div></section><?php endif; ?>
            <section data-library-panel="outline"><div class="sensecms-builder-library-search"><i data-lucide="search"></i><input type="search" data-outline-query aria-label="<?= $escape($uiText('Find sections on this page')) ?>" placeholder="<?= $escape($uiText('Find by name or type…')) ?>"></div><small class="sensecms-builder-tool-note" data-outline-results role="status"></small><div class="sensecms-builder-outline-toolbar"><span><b data-builder-outline-selected>0</b> <?= $escape($uiText('selected')) ?></span><div><button type="button" data-builder-copy-selected disabled title="<?= $escape($uiText('Copy selected sections')) ?>"><i data-lucide="clipboard-copy"></i><?= $escape($uiText('Copy')) ?></button><button type="button" data-builder-paste title="<?= $escape($uiText('Paste copied sections')) ?>"><i data-lucide="clipboard-paste"></i><?= $escape($uiText('Paste')) ?></button></div></div><?php if ($builderCanEdit): ?><div class="sensecms-builder-style-tools" data-style-tools hidden><span data-style-source></span><button type="button" class="btn bg-default-150" data-apply-style disabled><?= $escape($uiText('Apply appearance to selected')) ?></button></div><?php endif; ?><div class="sensecms-builder-library-list sensecms-builder-outline" data-builder-outline></div><div class="sensecms-builder-clipboard" data-builder-clipboard></div></section>
            <section data-library-panel="quality" hidden><div class="sensecms-builder-quality" data-builder-quality aria-live="polite"><div class="sensecms-builder-global-empty"><i data-lucide="loader-circle"></i><strong><?= $escape($uiText('Preparing quality report')) ?></strong><span><?= $escape($uiText('Core is checking the current page draft.')) ?></span></div></div></section>
            <section data-library-panel="modules" hidden><div class="sensecms-builder-library-search"><i data-lucide="search"></i><input type="search" placeholder="<?= $escape($uiText('Find a section…')) ?>" data-builder-search></div><?php if ($builderCanEdit): ?><small class="sensecms-builder-tool-note"><?= $escape($uiText('Click to append, or drag onto Live Canvas. Use + Add section on the canvas to choose an exact position with the keyboard.')) ?></small><?php endif; ?><div class="sensecms-builder-library-list" data-builder-library></div></section>
            <section data-library-panel="patterns" hidden><div class="sensecms-builder-library-search"><i data-lucide="search"></i><input type="search" placeholder="<?= $escape($uiText('Find a page pattern…')) ?>" data-builder-pattern-search></div><div class="sensecms-builder-library-list sensecms-builder-patterns" data-builder-patterns></div></section>
            <section data-library-panel="layouts" hidden><div class="sensecms-builder-layout-intro"><i data-lucide="panels-top-left"></i><p><strong><b data-builder-layout-selected>0</b> <?= $escape($uiText('sections selected')) ?></strong><span><?= $escape($uiText('Select consecutive sections in Navigator, then apply a responsive structure.')) ?></span></p></div><div class="sensecms-builder-library-list sensecms-builder-layouts" data-builder-layouts></div></section>
            <section data-library-panel="shared" hidden><div class="sensecms-builder-library-search"><i data-lucide="search"></i><input type="search" placeholder="<?= $escape($uiText('Find a shared section…')) ?>" data-builder-global-search></div><div class="sensecms-builder-library-list" data-builder-global-library></div></section>
            <footer><i data-lucide="shield-check"></i><p><strong><?= $escape($uiText('Portable, theme-safe content')) ?></strong><span><?= $escape($uiText('Every compatible theme supports the complete Core section standard. Trusted extensions may add their own components.')) ?></span></p></footer>
        </aside>
    </div>
    <div class="sensecms-builder-modal" data-builder-preview-dialog hidden><div class="sensecms-builder-dialog is-preview" role="dialog" aria-modal="true" aria-labelledby="builder-preview-title"><header><div><small><?= $escape($uiText('Responsive preview')) ?></small><strong id="builder-preview-title"><?= $escape($builderDocument['title']??$uiText('Untitled page')) ?></strong></div><button type="button" data-dialog-close aria-label="<?= $escape($uiText('Close preview')) ?>"><i data-lucide="x"></i></button></header><nav class="sensecms-builder-devices" aria-label="<?= $escape($uiText('Preview width')) ?>"><button type="button" class="is-active" data-preview-device="desktop"><i data-lucide="monitor"></i><?= $escape($uiText('Desktop')) ?></button><button type="button" data-preview-device="tablet"><i data-lucide="tablet"></i><?= $escape($uiText('Tablet')) ?></button><button type="button" data-preview-device="mobile"><i data-lucide="smartphone"></i><?= $escape($uiText('Mobile')) ?></button><a href="<?= $escape($previewUrl) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i><?= $escape($uiText('Open page')) ?></a></nav><div class="sensecms-builder-preview-stage" data-preview-stage="desktop"><iframe title="<?= $escape($uiText('Responsive page preview')) ?>" data-builder-preview-frame loading="lazy"></iframe></div></div></div>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-move" data-banner-dialog aria-labelledby="banner-edit-title">
        <form data-banner-form>
            <h3 id="banner-edit-title"><?= $escape($uiText('AI Chatbot banner')) ?></h3>
            <p data-banner-scope></p>
            <fieldset data-banner-fields disabled>
                <label><?= $escape($uiText('Heading')) ?><input class="form-input" name="title" required></label>
                <label><?= $escape($uiText('Introduction')) ?><textarea class="form-input" rows="2" name="intro" required></textarea></label>
                <label><?= $escape($uiText('Description')) ?><textarea class="form-input" rows="4" name="text" required></textarea></label>
            </fieldset>
            <small role="status" data-banner-status></small>
            <a data-banner-settings target="_blank" rel="noopener" hidden><?= $escape($uiText('Open theme settings')) ?></a>
            <footer><button type="button" class="btn bg-default-150" data-dialog-close><?= $escape($uiText('Close')) ?></button><button type="submit" class="btn bg-primary text-white" data-banner-publish disabled><?= $escape($uiText('Apply to draft')) ?></button></footer>
        </form>
    </dialog>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-move" data-shared-usage aria-labelledby="builder-usage-title">
        <h3 id="builder-usage-title"><?= $escape($uiText('Pages using this section')) ?></h3>
        <p data-usage-name></p><small role="status" data-usage-status></small>
        <ul class="sensecms-builder-changes-list" data-usage-pages></ul>
        <footer><button type="button" class="btn bg-default-150" data-dialog-close><?= $escape($uiText('Close')) ?></button></footer>
    </dialog>
    <dialog class="sensecms-builder-dialog is-history sensecms-builder-native" data-builder-history-dialog aria-labelledby="builder-history-title"><header><div><small><?= $escape($uiText('Page history')) ?></small><strong id="builder-history-title"><?= $escape($uiText('Revisions')) ?></strong></div><button type="button" data-dialog-close aria-label="<?= $escape($uiText('Close history')) ?>"><i data-lucide="x"></i></button></header><div class="sensecms-builder-dialog-copy"><i data-lucide="shield-check"></i><p><strong><?= $escape($uiText('Safe, non-destructive history')) ?></strong><span><?= $escape($uiText('Compare two versions, restore one section to the draft, or restore the full page as a new version.')) ?></span></p></div><div class="sensecms-builder-revisions" data-builder-revisions></div><div class="sensecms-builder-revision-comparison" data-builder-revision-comparison hidden></div><footer class="sensecms-builder-history-footer"><span data-builder-compare-hint><?= $escape($uiText('Select two revisions to compare')) ?></span><button class="btn bg-default-150" type="button" data-builder-compare disabled><i data-lucide="git-compare-arrows"></i><?= $escape($uiText('Compare selected')) ?></button></footer></dialog>
    <dialog class="sensecms-builder-header-dialog" data-restore-confirm aria-labelledby="builder-restore-title">
        <h3 id="builder-restore-title"><?= $escape($uiText('Restore revision')) ?></h3><p><?= $escape($uiText('The selected revision will become a new version. Shared sections from the snapshot become independent copies.')) ?></p>
        <footer><button type="button" class="btn bg-default-150" value="cancel" autofocus><?= $escape($uiText('Cancel')) ?></button><button type="button" class="btn bg-primary text-white" value="restore"><?= $escape($uiText('Restore revision')) ?></button></footer>
    </dialog>
    <?php if ($builderCanEdit): ?>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-move" data-publication-dialog aria-labelledby="builder-publication-title">
        <header><h3 id="builder-publication-title"></h3></header>
        <p data-publication-copy></p><ul class="sensecms-builder-changes-list" data-publication-pages></ul>
        <footer><button type="button" class="btn bg-default-150" data-publication-cancel autofocus></button><button type="button" class="btn bg-primary text-white" data-publication-confirm></button></footer>
    </dialog>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-move" data-changes-dialog aria-labelledby="builder-changes-title">
        <h3 id="builder-changes-title"><?= $escape($uiText('Changes since last save')) ?></h3>
        <ul class="sensecms-builder-changes-list" data-changes-list></ul>
        <footer><button type="button" class="btn bg-default-150" data-dialog-close><?= $escape($uiText('Back to editing')) ?></button></footer>
    </dialog>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-move" data-revision-section-dialog aria-labelledby="revision-section-title">
        <h3 id="revision-section-title"><?= $escape($uiText('Restore one section')) ?></h3>
        <p data-revision-section-version></p>
        <label><?= $escape($uiText('Section')) ?><select class="form-input" data-revision-section-choice></select></label>
        <small role="status" data-revision-section-note></small>
        <footer><button type="button" class="btn bg-default-150" data-dialog-close><?= $escape($uiText('Cancel')) ?></button><button type="button" class="btn bg-primary text-white" data-revision-section-apply disabled><?= $escape($uiText('Restore to draft')) ?></button></footer>
    </dialog>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-move" data-builder-move-dialog aria-labelledby="builder-move-title">
        <h3 id="builder-move-title"><?= $escape($uiText('Move to position')) ?></h3><p data-move-source></p>
        <label><?= $escape($uiText('Place')) ?><select class="form-input" data-move-side><option value="before"><?= $escape($uiText('Before')) ?></option><option value="after"><?= $escape($uiText('After')) ?></option></select></label>
        <label><?= $escape($uiText('Destination on this page')) ?><select class="form-input" data-move-target></select></label>
        <small role="status" data-move-note></small>
        <footer><button type="button" class="btn bg-default-150" data-move-cancel><?= $escape($uiText('Cancel')) ?></button><button type="button" class="btn bg-primary text-white" data-move-apply><?= $escape($uiText('Move')) ?></button></footer>
    </dialog>
    <?php endif; ?>
    <dialog class="sensecms-builder-header-dialog sensecms-builder-merge" data-merge-dialog aria-labelledby="builder-merge-title">
        <h3 id="builder-merge-title"></h3><p data-merge-summary role="status"></p>
        <div data-merge-fields></div><p role="alert" data-merge-error hidden></p>
        <footer><button type="button" class="btn bg-default-150" data-merge-back autofocus></button>
            <button type="button" class="btn bg-primary text-white" data-merge-apply disabled></button></footer>
    </dialog>
    <dialog class="sensecms-builder-header-dialog" data-builder-recovery-dialog aria-labelledby="builder-recovery-title" aria-describedby="builder-recovery-copy">
        <h3 id="builder-recovery-title"></h3>
        <p id="builder-recovery-copy"></p>
        <span data-builder-recovery-meta></span>
        <p data-builder-recovery-warning hidden></p>
        <p data-builder-recovery-error role="alert" hidden></p>
        <footer>
            <button type="button" class="btn bg-default-150" data-builder-recovery-discard></button>
            <button type="button" class="btn bg-primary text-white" data-builder-recovery-restore autofocus></button>
        </footer>
    </dialog>
    <div class="sensecms-builder-modal" data-builder-share-dialog hidden><form class="sensecms-builder-dialog is-compact" data-builder-share-form role="dialog" aria-modal="true" aria-labelledby="builder-share-title"><header><div><small><?= $escape($uiText('Reusable content')) ?></small><strong id="builder-share-title"><?= $escape($uiText('Create shared section')) ?></strong></div><button type="button" data-dialog-close aria-label="<?= $escape($uiText('Close')) ?>"><i data-lucide="x"></i></button></header><label class="sensecms-builder-share-name"><span><?= $escape($uiText('Section name')) ?></span><input class="form-input" type="text" name="name" minlength="2" maxlength="120" required placeholder="<?= $escape($uiText('e.g. Contact call to action')) ?>"></label><p><?= $escape($uiText('Changes made to a linked shared section are reflected everywhere it is used.')) ?></p><footer><button type="button" class="btn bg-default-150" data-dialog-close><?= $escape($uiText('Cancel')) ?></button><button type="submit" class="btn bg-primary text-white"><i data-lucide="combine"></i><?= $escape($uiText('Create shared section')) ?></button></footer></form></div>
    <div class="sensecms-builder-modal" data-builder-layout-dialog hidden><form class="sensecms-builder-dialog is-layout" data-builder-layout-form role="dialog" aria-modal="true" aria-labelledby="builder-layout-title"><header><div><small><?= $escape($uiText('Responsive layout')) ?></small><strong id="builder-layout-title"><?= $escape($uiText('Section layout')) ?></strong></div><button type="button" data-dialog-close aria-label="<?= $escape($uiText('Close layout settings')) ?>"><i data-lucide="x"></i></button></header><div class="sensecms-builder-layout-form"><section><header><i data-lucide="panels-top-left"></i><div><strong><?= $escape($uiText('Container and flow')) ?></strong><span><?= $escape($uiText('Shared by every section in this layout group.')) ?></span></div></header><div class="sensecms-builder-layout-fields"><label><span><?= $escape($uiText('Maximum width')) ?></span><select name="container"><option value="content"><?= $escape($uiText('Content')) ?></option><option value="wide"><?= $escape($uiText('Wide')) ?></option><option value="full"><?= $escape($uiText('Full viewport')) ?></option></select></label><label><span><?= $escape($uiText('Gap')) ?></span><select name="gap"><option value="none"><?= $escape($uiText('None')) ?></option><option value="small"><?= $escape($uiText('Small')) ?></option><option value="medium"><?= $escape($uiText('Medium')) ?></option><option value="large"><?= $escape($uiText('Large')) ?></option></select></label><label><span><?= $escape($uiText('Alignment')) ?></span><select name="align"><option value="start"><?= $escape($uiText('Start')) ?></option><option value="center"><?= $escape($uiText('Center')) ?></option><option value="end"><?= $escape($uiText('End')) ?></option><option value="stretch"><?= $escape($uiText('Stretch')) ?></option></select></label><label class="is-check"><input type="checkbox" name="wrap"><span><?= $escape($uiText('Wrap items responsively')) ?></span></label></div></section><section><header><i data-lucide="monitor-smartphone"></i><div><strong><?= $escape($uiText('Selected section by device')) ?></strong><span><?= $escape($uiText('Control its span, order and visibility at each breakpoint.')) ?></span></div></header><div class="sensecms-builder-breakpoints"><?php foreach (['desktop'=>['monitor','Desktop'],'tablet'=>['tablet','Tablet'],'mobile'=>['smartphone','Mobile']] as $key=>$device): ?><fieldset data-layout-breakpoint="<?= $key ?>"><legend><i data-lucide="<?= $device[0] ?>"></i><?= $escape($uiText($device[1])) ?></legend><label><span><?= $escape($uiText('Span')) ?></span><select name="<?= $key ?>_span"><?php for($span=1;$span<=12;$span++): ?><option value="<?= $span ?>"><?= $span ?> / 12</option><?php endfor; ?></select></label><label><span><?= $escape($uiText('Order')) ?></span><input type="number" name="<?= $key ?>_order" min="-20" max="20" value="0"></label><label class="is-check"><input type="checkbox" name="<?= $key ?>_hidden"><span><?= $escape($uiText('Hide')) ?></span></label></fieldset><?php endforeach; ?></div></section></div><footer><button type="button" class="btn bg-default-150" data-builder-layout-ungroup><i data-lucide="ungroup"></i><?= $escape($uiText('Remove group')) ?></button><span></span><button type="button" class="btn bg-default-150" data-dialog-close><?= $escape($uiText('Cancel')) ?></button><button type="submit" class="btn bg-primary text-white"><i data-lucide="check"></i><?= $escape($uiText('Apply layout')) ?></button></footer></form></div>
</section>
</section>
