<?php
$activeLanguageCount=count(array_filter($languages,static fn(array $language):bool=>(bool)$language['enabled']));
$sectionHero=['overline'=>'SYSTEM · LOCALIZATION','title'=>'Languages','description'=>'Manage installed locales, native labels, flags, activation and the public fallback language.','icon'=>'languages','status'=>$activeLanguageCount.' active '.($activeLanguageCount===1?'language':'languages'),'status_detail'=>count($languages).' installed locales'];
$form=$editingLanguage??['locale'=>'','name'=>'','native_name'=>'','flag'=>'/sensecms/images/flags/gb.svg','enabled'=>1,'is_default'=>0,'sort_order'=>count($languages)+1];
require __DIR__.'/console-section-hero.php';
?>
<div class="grid gap-5 xl:grid-cols-3">
    <section class="card xl:col-span-2">
        <div class="card-header"><div><h6 class="card-title">Configured languages</h6><p class="mt-1 text-sm text-default-500">The default language is always enabled and supplies missing public translations.</p></div></div>
        <div class="card-body space-y-3">
            <?php foreach($languages as$language):$isDefault=(bool)$language['is_default'];$isEnabled=(bool)$language['enabled']; ?>
            <article class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-default-200 p-4">
                <span class="flex min-w-0 items-center gap-3"><img class="h-8 w-11 rounded-md border border-default-200 object-cover" src="<?= $escape($language['flag']) ?>" alt=""><span class="min-w-0"><span class="flex flex-wrap items-center gap-2"><strong class="block text-sm"><?= $escape($language['name']) ?></strong><?php if($isDefault): ?><em class="rounded-full bg-primary/10 px-2 py-1 text-[10px] font-semibold not-italic text-primary">DEFAULT · FALLBACK</em><?php endif; ?></span><small class="text-default-500"><?= $escape(mb_strtoupper($language['locale'])) ?> · <?= $escape($language['native_name']) ?> · order <?= (int)$language['sort_order'] ?></small></span></span>
                <span class="flex flex-wrap items-center justify-end gap-2">
                    <?php if(!$isDefault): ?><form method="post" action="/system/languages"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="default"><input type="hidden" name="locale" value="<?= $escape($language['locale']) ?>"><button class="btn bg-primary/10 text-primary" type="submit"><i data-lucide="star" class="size-4"></i>Set default</button></form><?php endif; ?>
                    <form method="post" action="/system/languages"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="locale" value="<?= $escape($language['locale']) ?>"><input type="hidden" name="enabled" value="<?= $isEnabled?'0':'1' ?>"><button class="btn <?= $isEnabled?'bg-success/10 text-success':'bg-default-150 text-default-600' ?>" type="submit" <?= $isDefault?'disabled title="Choose another default language before disabling this one"':'' ?>><i data-lucide="<?= $isEnabled?'toggle-right':'toggle-left' ?>" class="size-4"></i><?= $isEnabled?'Enabled':'Disabled' ?></button></form>
                    <a class="btn bg-default-150 text-default-700" href="/system/languages?edit=<?= rawurlencode((string)$language['locale']) ?>"><i data-lucide="pencil" class="size-4"></i>Edit</a>
                </span>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <aside class="card h-fit">
        <div class="card-header"><div><h6 class="card-title"><?= $editingLanguage?'Edit language':'Add language' ?></h6><p class="mt-1 text-sm text-default-500">Flags are selected from verified local assets.</p></div><?php if($editingLanguage): ?><a class="text-xs font-semibold text-primary" href="/system/languages">Add new</a><?php endif; ?></div>
        <div class="card-body"><form method="post" action="/system/languages" class="grid gap-4"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save">
            <label><span class="mb-2 block text-sm font-medium">Locale</span><input class="form-input" name="locale" pattern="[a-z]{2,5}" maxlength="5" placeholder="e.g. en" value="<?= $escape($form['locale']) ?>" <?= $editingLanguage?'readonly':'' ?> required><small class="sensecms-field-help">Locale cannot be renamed after content has been created.</small></label>
            <label><span class="mb-2 block text-sm font-medium">Name</span><input class="form-input" name="name" maxlength="80" placeholder="English" value="<?= $escape($form['name']) ?>" required></label>
            <label><span class="mb-2 block text-sm font-medium">Native name</span><input class="form-input" name="native_name" maxlength="80" placeholder="English" value="<?= $escape($form['native_name']) ?>" required></label>
            <label><span class="mb-2 block text-sm font-medium">Flag</span><select class="form-input" name="flag" required><?php foreach($languageFlags as$path=>$label): ?><option value="<?= $escape($path) ?>" <?= $form['flag']===$path?'selected':'' ?>><?= $escape($label) ?> · <?= $escape(basename($path)) ?></option><?php endforeach; ?></select></label>
            <label><span class="mb-2 block text-sm font-medium">Order</span><input class="form-input" type="number" min="0" max="999" name="sort_order" value="<?= (int)$form['sort_order'] ?>"></label>
            <label class="flex items-center gap-3 rounded-lg border border-default-200 p-3 text-sm"><?php if(!empty($form['is_default'])): ?><input type="hidden" name="enabled" value="1"><?php endif; ?><input class="form-checkbox" type="checkbox" name="enabled" <?= !empty($form['enabled'])?'checked':'' ?> <?= !empty($form['is_default'])?'disabled':'' ?>><span><strong class="block">Enabled</strong><small class="text-default-500">Available in public routing and content editors.</small></span></label>
            <label class="flex items-center gap-3 rounded-lg border border-default-200 p-3 text-sm"><input class="form-checkbox" type="checkbox" name="is_default" <?= !empty($form['is_default'])?'checked':'' ?>><span><strong class="block">Default and fallback</strong><small class="text-default-500">Used whenever a localized value is missing.</small></span></label>
            <button class="btn w-full bg-primary text-white" type="submit"><i data-lucide="save" class="size-4"></i><?= $editingLanguage?'Save language':'Add language' ?></button>
        </form></div>
    </aside>
</div>
