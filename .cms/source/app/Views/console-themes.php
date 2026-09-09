<?php
$themeItems = array_values($themeItems ?? []);
$themeSummary = array_replace(['themes'=>count($themeItems),'active'=>0,'updates'=>0], (array)($themeSummary ?? []));
$workspace=is_array($themeWorkspace??null)?$themeWorkspace:[];$workspaceTheme=(array)($workspace['theme']??[]);
?>
<?php $sectionHero=['overline'=>'EXPERIENCE · PROFESSIONAL THEMES','title'=>'Presentation themes','description'=>'Build independent presentation layers without coupling pages, languages, media or Page Builder content to one design.','icon'=>'panels-top-left','action_url'=>'/marketplace?type=theme','action_label'=>'Explore themes','action_icon'=>'store'];require __DIR__.'/console-section-hero.php'; ?>

<section class="sensecms-theme-overview">
    <article><i data-lucide="panels-top-left"></i><span><strong><?= (int)$themeSummary['themes'] ?></strong><small>Installed themes</small></span></article>
    <article><i data-lucide="circle-check"></i><span><strong><?= (int)$themeSummary['active'] ?></strong><small>Active presentation</small></span></article>
    <article><i data-lucide="blocks"></i><span><strong><?= (int) ($supportedModuleCount ?? 0) ?></strong><small>Supported sections</small></span></article>
    <article><i data-lucide="languages"></i><span><strong><?= count($languages ?? []) ?></strong><small>Active languages</small></span></article>
    <article><i data-lucide="arrow-up-circle"></i><span><strong><?= (int)$themeSummary['updates'] ?></strong><small>Updates available</small></span></article>
</section>
<div class="sensecms-index-utility"><a href="/system/extensions?tab=catalog" class="btn bg-default-150"><i data-lucide="package-plus"></i>Install signed ZIP</a></div>
<?php if (!$workspace && !$themeItems && empty($themeReleases)): ?><p>No presentation theme is installed yet. Install a signed theme package to configure the public website. Your administration panel is already available.</p><?php endif; ?>

<?php if (!empty($themeReleases)): ?>
<section class="sensecms-theme-workspace" aria-labelledby="installed-releases-title">
    <header class="sensecms-theme-workspace-head"><div><h2 id="installed-releases-title">Installed presentation releases</h2><p>Installing a ZIP does not switch the live website. Activation checks the signature, compatibility and file inventory again. Existing content stays in the CMS.</p></div></header>
    <div class="sensecms-theme-grid">
    <?php foreach ($themeReleases as $release): $isCurrent = ($activeRelease['directory'] ?? '') === $release['directory']; ?>
        <article class="sensecms-theme-card<?= $isCurrent ? ' is-active' : '' ?>"><div class="sensecms-theme-body">
            <h3><?= $escape($release['slug']) ?> · <?= $escape($release['version']) ?></h3>
            <p><?= $isCurrent ? 'Serving the public website' : 'Retained signed archive; trust is rechecked before activation' ?></p>
            <form method="post" action="/appearance/releases/activate" data-sensecms-confirm="Switch the public website to this exact verified release?">
                <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="directory" value="<?= $escape($release['directory']) ?>">
                <button class="btn bg-primary text-white" type="submit" <?= $isCurrent ? 'disabled' : '' ?>><?= $isCurrent ? 'Active release' : 'Activate release' ?></button>
            </form>
        </div></article>
    <?php endforeach; ?>
    </div>
    <form method="post" action="/appearance/releases/rollback" data-sensecms-confirm="Restore the previous public presentation after verifying its archive?">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="btn bg-default-150" type="submit">Restore previous presentation</button>
    </form>
</section>
<?php endif; ?>

<div class="sensecms-theme-grid">
<?php foreach ($themeItems as $item): $isActive=(bool)$item['active']; $screenshot=(string)($item['screenshot']??''); ?>
    <article class="sensecms-theme-card<?= $isActive?' is-active':'' ?>">
        <div class="sensecms-theme-preview<?= $screenshot!==''?' has-image':'' ?>">
            <?php if($screenshot!==''): ?><img src="<?= $escape($screenshot) ?>" alt="<?= $escape($item['name']) ?> theme preview" loading="lazy"><?php else: ?><i data-lucide="panels-top-left"></i><?php endif; ?>
            <span>THEME PREVIEW</span><?php if($isActive): ?><b><i data-lucide="circle-check"></i>Current theme</b><?php endif; ?>
        </div>
        <div class="sensecms-theme-body">
            <header><div><span><?= $escape(strtoupper($item['group'])) ?></span><h3><?= $escape($item['name']) ?></h3></div><span class="sensecms-status <?= $isActive?'is-active':'is-inactive' ?>"><?= $isActive?'Active':'Installed' ?></span></header>
            <p><?= $escape($item['description']) ?></p>
            <div class="sensecms-theme-meta"><span><i data-lucide="building-2"></i><?= $escape($item['publisher']) ?></span><span><i data-lucide="git-branch"></i>v<?= $escape($item['version']) ?> · <?= $escape(ucfirst($item['release_channel']??'stable')) ?></span><span><i data-lucide="<?= $item['trust']==='verified'?'badge-check':'shield-check' ?>"></i><?= $item['trust']==='verified'?'Signed package':'SenseCMS distribution' ?></span></div>
            <section class="sensecms-theme-capabilities"><div><strong><?= count($item['supported_blocks']) ?>/15</strong><small>Page Builder modules</small></div><div><strong><?= $item['compatible']?'Compatible':'Review required' ?></strong><small>SenseCMS <?= $escape($item['engine']) ?></small></div></section>
            <ul class="sensecms-theme-features"><?php foreach(array_slice($item['features'],0,3)as$feature): ?><li><i data-lucide="check"></i><?= $escape($feature) ?></li><?php endforeach; ?></ul>
            <?php if($item['update_available']): ?><div class="sensecms-theme-update"><i data-lucide="package-open"></i><span><strong>Version <?= $escape($item['available_version']) ?> is available</strong><small>Review the verified release before installation.</small></span><a href="/system/extensions?tab=catalog">Review update</a></div><?php endif; ?>
            <footer><a class="btn bg-default-150" href="/appearance/themes?configure=<?= rawurlencode((string)$item['slug']) ?>#theme-workspace"><i data-lucide="settings-2"></i>Configure</a><a class="btn bg-default-150" href="<?= $escape($item['preview_url']?:'/en/home') ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Public page</a><?php if(!$isActive): ?><form data-sensecms-confirm="Activate this compatible presentation theme?" method="post" action="/appearance/themes/<?= rawurlencode($item['slug']) ?>/activate"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="btn bg-primary text-white" type="submit"><i data-lucide="check"></i>Activate</button></form><?php endif; ?><?php if(($item['rollback_count']??0)>0): ?><button type="button" class="btn bg-default-150" data-package-rollback="theme:<?= $escape($item['slug']) ?>"><i data-lucide="history"></i>Rollback</button><?php endif; ?></footer>
        </div>
    </article>
<?php endforeach; ?>
</div>
<?php if($workspaceTheme): $isChild=(string)($workspaceTheme['parent']??'')!=='';$publishedJson=json_encode($workspace['published']??[],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
<section id="theme-workspace" class="sensecms-theme-workspace" data-theme-workspace data-slug="<?= $escape($workspace['slug']) ?>" data-csrf="<?= $escape($csrf) ?>" data-published="<?= $escape($publishedJson) ?>">
    <header class="sensecms-theme-workspace-head"><div class="sensecms-theme-workspace-brand"><i data-lucide="palette"></i><span><small>THEME CONFIGURATION · CONTRACT V<?= (int)($workspaceTheme['contract_version']??0) ?></small><h2><?= $escape($workspaceTheme['name']??'Theme') ?></h2><p>Prepare changes safely, inspect them in an authenticated preview and publish only when the presentation is ready.</p></span></div><div class="sensecms-theme-draft-state <?= !empty($workspace['is_draft'])?'is-draft':'is-published' ?>" data-theme-state><i data-lucide="<?= !empty($workspace['is_draft'])?'file-pen-line':'circle-check' ?>"></i><span><strong><?= !empty($workspace['is_draft'])?'Unpublished draft':'Published configuration' ?></strong><small data-theme-state-detail><?= !empty($workspace['draft_updated_at'])?$escape($workspace['draft_updated_at']):'Public settings are current' ?></small></span></div></header>
    <div class="sensecms-theme-contract-grid"><article><i data-lucide="git-branch"></i><span><strong><?= $isChild?'Child of '.$escape($workspaceTheme['parent']):'Independent theme' ?></strong><small>Controlled inheritance</small></span></article><article><i data-lucide="layout-template"></i><span><strong><?= count((array)($workspaceTheme['regions']??[])) ?> registered regions</strong><small>Navigation and extension slots</small></span></article><article><i data-lucide="swatch-book"></i><span><strong><?= count((array)($workspaceTheme['design_tokens']??[])) ?> design tokens</strong><small>Validated CSS variables</small></span></article><article><i data-lucide="radio-tower"></i><span><strong><?= $escape(ucfirst((string)($workspaceTheme['release_channel']??'stable'))) ?> channel</strong><small>Version <?= $escape($workspaceTheme['version']??'') ?></small></span></article></div>
    <form data-theme-form novalidate><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><div class="sensecms-theme-setting-grid">
        <?php foreach((array)($workspaceTheme['configuration']??[])as$field): $key=(string)$field['key'];$type=(string)$field['type'];$value=$workspace['settings'][$key]??$field['default']??'';$inherits=$isChild&&in_array($key,(array)($workspace['inherit']??[]),true); ?>
        <article class="sensecms-theme-setting" data-theme-setting="<?= $escape($key) ?>"><header><label for="theme-<?= $escape($key) ?>"><?= $escape($field['label']) ?></label><span><?= $escape(strtoupper($type)) ?></span></header>
            <?php if($type==='select'): ?><select id="theme-<?= $escape($key) ?>" name="<?= $escape($key) ?>" <?= $inherits?'disabled':'' ?>><?php foreach((array)$field['options']as$option=>$label): ?><option value="<?= $escape($option) ?>" <?= (string)$value===(string)$option?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select>
            <?php elseif($type==='toggle'): ?><label class="sensecms-theme-switch"><input id="theme-<?= $escape($key) ?>" name="<?= $escape($key) ?>" type="checkbox" <?= $value?'checked':'' ?> <?= $inherits?'disabled':'' ?>><span></span><em><?= $value?'Enabled':'Disabled' ?></em></label>
            <?php elseif($type==='text'&&(int)($field['max_length']??160)>160): ?><div class="sensecms-theme-input is-multiline"><textarea id="theme-<?= $escape($key) ?>" name="<?= $escape($key) ?>" rows="4" maxlength="<?= max(1,min(500,(int)$field['max_length'])) ?>" <?= $inherits?'disabled':'' ?>><?= $escape($value) ?></textarea></div>
            <?php else: ?><div class="sensecms-theme-input<?= $type==='color'?' has-colour':'' ?>"><?php if($type==='color'): ?><input type="color" data-theme-colour value="<?= $escape($value) ?>" tabindex="-1"><?php endif; ?><input id="theme-<?= $escape($key) ?>" name="<?= $escape($key) ?>" type="<?= $type==='number'?'number':'text' ?>" value="<?= $escape($value) ?>" <?= $type==='text'?'maxlength="'.max(1,min(500,(int)($field['max_length']??160))).'"':'' ?> <?= isset($field['min'])?'min="'.(float)$field['min'].'"':'' ?> <?= isset($field['max'])?'max="'.(float)$field['max'].'"':'' ?> <?= $inherits?'disabled':'' ?>><small><?= $escape($field['unit']??($type==='asset'?'Media Library path':'')) ?></small></div><?php endif; ?>
            <?php if($isChild): ?><label class="sensecms-theme-inherit"><input type="checkbox" data-theme-inherit="<?= $escape($key) ?>" <?= $inherits?'checked':'' ?>><span>Inherit from <?= $escape($workspaceTheme['parent']) ?></span></label><?php endif; ?>
        </article><?php endforeach; ?>
    </div><footer class="sensecms-theme-workspace-actions"><span data-theme-save-status><i></i>All published changes saved</span><div><button type="button" class="btn bg-default-150" data-theme-discard <?= empty($workspace['is_draft'])?'disabled':'' ?>><i data-lucide="rotate-ccw"></i>Discard draft</button><button type="button" class="btn bg-default-150" data-theme-preview><i data-lucide="eye"></i>Preview draft</button><button type="submit" class="btn bg-default-150"><i data-lucide="save"></i>Save draft</button><button type="button" class="btn bg-primary text-white" data-theme-publish <?= empty($workspace['is_draft'])?'disabled':'' ?>><i data-lucide="send"></i>Publish</button></div></footer></form>
</section>
<?php endif; ?>
<section class="sensecms-theme-contract"><i data-lucide="shield-check"></i><div><span>SENSECMS PORTABILITY CONTRACT</span><h3>Your content is never owned by a theme</h3><p>Switching presentation changes templates and styling only. Pages, multilingual content, facilities, forms, media and revisions remain in the platform data layer.</p></div><a href="/content/builder">Open Page Builder<i data-lucide="arrow-right"></i></a></section>
