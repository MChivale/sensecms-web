<?php
$workspacePages = array_values((array) ($contentWorkspace['pages'] ?? []));
$workspaceSelected = (int) ($contentWorkspace['selected'] ?? 0);
$workspacePage = null;
foreach ($workspacePages as $candidate) {
    if ((int) ($candidate['id'] ?? 0) === $workspaceSelected) {
        $workspacePage = $candidate;
        break;
    }
}
$workspacePage ??= $workspacePages[0] ?? ['id' => 0, 'title' => 'Untitled page', 'slug' => ''];
$workspaceSelect = (string) ($contentWorkspace['select'] ?? '');
$workspaceForm = (string) ($contentWorkspace['form'] ?? '');
$workspaceIcon = (string) ($contentWorkspace['icon'] ?? 'panels-top-left');
$workspaceLabel = (string) ($contentWorkspace['label'] ?? 'Editing page');
$workspaceStatus = (string) ($contentWorkspace['status'] ?? 'Saved');
$workspaceAction = (string) ($contentWorkspace['action'] ?? 'Save changes');
$workspacePreview = (string) ($contentWorkspace['preview_url'] ?? '');
$workspaceTitle = (string) ($contentWorkspace['title'] ?? ($workspacePage['title'] ?? 'Untitled page'));
$workspaceBack = (string) ($contentWorkspace['back_url'] ?? '');
$workspaceSeoType = (string) ($contentWorkspace['seo_type'] ?? 'page');
?>
<header class="sensecms-content-toolbar card" data-content-workspace="<?= $escape($workspaceForm) ?>">
    <div class="sensecms-content-document">
        <span class="sensecms-content-icon"><i data-lucide="<?= $escape($workspaceIcon) ?>"></i></span>
        <label>
            <small><?= $escape($workspaceLabel) ?></small>
            <?php if (in_array($workspaceSelect, ['seo', 'popup'], true)): ?><select aria-label="Select <?= $workspaceSelect==='seo'?$escape($workspaceSeoType):'page' ?>" <?= $workspaceSelect === 'seo' ? 'data-seo-page data-seo-type="'.$escape($workspaceSeoType).'"' : 'data-popup-page' ?>>
                <?php foreach ($workspacePages as $page): ?>
                    <option value="<?= (int) ($page['id'] ?? 0) ?>" <?= (int) ($page['id'] ?? 0) === $workspaceSelected ? 'selected' : '' ?>><?= $escape((!empty($page['facility_name'])?$page['facility_name'].' · ':'').($page['title'] ?? ('Page #' . ($page['id'] ?? '')))) ?></option>
                <?php endforeach; ?>
            </select><?php else: ?><strong><?= $escape($workspaceTitle) ?></strong><?php endif; ?>
        </label>
        <span class="sensecms-content-state-stack">
            <span class="sensecms-content-status is-saved" data-content-status aria-live="polite"><i data-lucide="circle-check"></i><?= $escape($workspaceStatus) ?></span>
            <span class="sensecms-content-save-state" data-content-save-state><i></i><b>All changes saved</b></span>
        </span>
    </div>
    <div class="sensecms-content-actions">
        <?php if ($workspaceBack !== ''): ?><a class="btn bg-default-150" href="<?= $escape($workspaceBack) ?>" data-content-link><i data-lucide="arrow-left"></i>Back</a><?php endif; ?>
        <?php if ($workspacePreview !== ''): ?><a class="btn bg-default-150" href="<?= $escape($workspacePreview) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Preview</a><?php endif; ?>
        <button class="btn bg-primary text-white" type="submit" form="<?= $escape($workspaceForm) ?>"><i data-lucide="save"></i><?= $escape($workspaceAction) ?></button>
    </div>
</header>
