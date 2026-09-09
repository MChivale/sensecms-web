<?php
$editorId = (string) ($menuEditor['id'] ?? 'content-navigation-form');
$editorLocation = (string) ($menuEditor['location'] ?? 'footer');
$editorTitle = (string) ($menuEditor['title'] ?? 'Navigation links');
$editorOverline = (string) ($menuEditor['overline'] ?? 'MULTILINGUAL MENU STRUCTURE');
$editorDescription = (string) ($menuEditor['description'] ?? 'Drag or move links into order. Every active language has an independent label and destination.');
$editorAction = (string) ($menuEditor['action'] ?? ('Save ' . strtolower($editorTitle)));
$items = array_values((array) ($menuEditor['items'] ?? []));
?>
<form id="<?= $escape($editorId) ?>" method="post" action="/content/navigation" class="card sensecms-navigation-editor" data-navigation-editor data-content-form>
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="location" value="<?= $escape($editorLocation) ?>">
    <input type="hidden" name="ui_locale" value="<?= $escape($activeLocale) ?>" data-ui-locale>
    <header class="card-header"><div><span class="sensecms-overline"><?= $escape($editorOverline) ?></span><h6 class="card-title"><?= $escape($editorTitle) ?></h6><p class="mt-1 text-sm text-default-500"><?= $escape($editorDescription) ?></p></div><button class="btn bg-primary/10 text-primary" type="button" data-navigation-add><i data-lucide="plus"></i>Add link</button></header>
    <?php $localeTabsId=$editorId.'-locales';$localeTabsActive=$activeLocale;require __DIR__.'/console-language-tabs.php'; ?>
    <div class="sensecms-navigation-items" data-navigation-items><?php foreach($items as$index=>$item):require __DIR__.'/console-content-navigation-item.php';endforeach; ?></div>
    <div class="sensecms-navigation-empty" data-navigation-empty <?= $items?'hidden':'' ?>><i data-lucide="menu-square"></i><strong>This list is empty</strong><span>Add the first link and provide its destination in each language.</span><button class="btn bg-primary text-white" type="button" data-navigation-add><i data-lucide="plus"></i>Add first link</button></div>
    <footer class="sensecms-navigation-footer"><p><i data-lucide="info"></i>Hidden links stay configured and can be re-enabled later.</p><button class="btn bg-primary text-white" type="submit"><i data-lucide="save"></i><?= $escape($editorAction) ?></button></footer>
</form>
