<?php
$location=in_array($_GET['location']??'',['primary','footer'],true)?(string)$_GET['location']:'primary';
$activeLocale=(string)($_GET['lang']??($languages[0]['locale']??'en'));$localeCodes=array_column($languages,'locale');if(!in_array($activeLocale,$localeCodes,true))$activeLocale=(string)($languages[0]['locale']??'en');
$items=array_values(array_filter($navigation,static fn(array$item):bool=>($item['location']??'')===$location&&!empty($item['id'])));
$connectItems=$location==='footer'?array_values(array_filter($navigation,static fn(array$item):bool=>($item['location']??'')==='footer-connect'&&!empty($item['id']))):[];
$defaultLanguage=array_values(array_filter($languages,static fn(array$language):bool=>(bool)($language['is_default']??false)))[0]??($languages[0]??['locale'=>'en']);$defaultLocale=(string)$defaultLanguage['locale'];
$workspaceName=$location==='primary'?'Header navigation':'Footer navigation';
$chromeLocation=$location==='primary'?'header':'footer';$chromeDefaults=\App\Core\SiteChrome::defaults()['localized'];$chromeStored=is_array($siteChrome['localized']??null)?$siteChrome['localized']:[];$chromeValues=[];
foreach($languages as$language){$code=(string)$language['locale'];$chromeValues[$code]=array_replace((array)$chromeDefaults['en'],(array)($chromeDefaults[$defaultLocale]??[]),(array)($chromeStored[$defaultLocale]??[]),(array)($chromeDefaults[$code]??[]),(array)($chromeStored[$code]??[]));}
$contentWorkspace=['pages'=>[],'selected'=>0,'select'=>'none','form'=>'content-navigation-form','icon'=>'menu','label'=>'Editing navigation','title'=>$workspaceName,'status'=>'Saved','action'=>'Save navigation'];
?>
<section class="sensecms-content-editor-workspace">
<?php
$navigationItemCount = count($items) + count($connectItems);
$sectionHero = [
    'overline' => 'CONTENT · SITE STRUCTURE',
    'title' => 'Navigation',
    'description' => 'Manage multilingual header and footer navigation with clear destinations, visibility controls and predictable ordering.',
    'icon' => 'menu',
    'status' => $workspaceName,
    'status_detail' => $navigationItemCount . ' configured ' . ($navigationItemCount === 1 ? 'link' : 'links'),
    'status_icon' => $location === 'primary' ? 'panel-top' : 'panel-bottom',
];
require __DIR__ . '/console-section-hero.php';
require __DIR__ . '/console-content-workspace-header.php';
?>
<section class="sensecms-navigation-workspace"><nav class="sensecms-location-tabs" aria-label="Navigation location"><a href="/content/navigation?location=primary&lang=<?= rawurlencode($activeLocale) ?>" data-content-link class="<?= $location==='primary'?'is-active':'' ?>"><i data-lucide="panel-top"></i><span><strong>Header</strong><small>Primary website navigation</small></span></a><a href="/content/navigation?location=footer&lang=<?= rawurlencode($activeLocale) ?>" data-content-link class="<?= $location==='footer'?'is-active':'' ?>"><i data-lucide="panel-bottom"></i><span><strong>Footer</strong><small>Supporting and legal links</small></span></a></nav>
<form method="post" action="/content/site-chrome" class="card sensecms-navigation-editor" data-content-form><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="location" value="<?= $escape($chromeLocation) ?>"><input type="hidden" name="ui_locale" value="<?= $escape($activeLocale) ?>" data-ui-locale>
    <header class="card-header"><div><span class="sensecms-overline"><?= $location==='primary'?'HEADER COPY':'FOOTER COPY' ?></span><h6 class="card-title"><?= $location==='primary'?'Top bar and header actions':'Footer identity and legal copy' ?></h6><p class="mt-1 text-sm text-default-500"><?= $location==='primary'?'Edit the top message and both header action buttons.':'Edit the description below the logo, section labels, call to action and both copyright lines.' ?></p></div><button class="btn bg-primary text-white" type="submit"><i data-lucide="save"></i>Save <?= $escape($chromeLocation) ?></button></header>
    <?php $localeTabsId='site-chrome-locales';$localeTabsActive=$activeLocale;require __DIR__.'/console-language-tabs.php'; ?>
    <div class="card-body"><?php foreach($languages as$language):$code=(string)$language['locale'];$copy=$chromeValues[$code]; ?><section data-language-panel="<?= $escape($code) ?>" <?= $code!==$activeLocale?'hidden':'' ?>>
        <div class="sensecms-editor-language-head"><img src="<?= $escape($language['flag']) ?>" alt=""><div><strong><?= $escape($language['native_name']?:$language['name']) ?></strong><span><?= $code===$defaultLocale?'Default copy · public fallback':'Localized header and footer copy' ?></span></div></div>
        <?php if($location==='primary'): ?><div class="grid gap-4 md:grid-cols-2"><label class="md:col-span-2"><span>Top message</span><input class="form-input" maxlength="180" name="chrome[<?= $escape($code) ?>][utility_text]" value="<?= $escape($copy['utility_text']) ?>"></label><label><span>Top contact label</span><input class="form-input" maxlength="80" name="chrome[<?= $escape($code) ?>][utility_contact_label]" value="<?= $escape($copy['utility_contact_label']) ?>"></label><label><span>Top contact destination</span><input class="form-input" maxlength="500" name="chrome[<?= $escape($code) ?>][utility_contact_url]" value="<?= $escape($copy['utility_contact_url']) ?>"></label><label><span>Header CTA label</span><input class="form-input" maxlength="80" name="chrome[<?= $escape($code) ?>][header_cta_label]" value="<?= $escape($copy['header_cta_label']) ?>"></label><label><span>Header CTA destination</span><input class="form-input" maxlength="500" name="chrome[<?= $escape($code) ?>][header_cta_url]" value="<?= $escape($copy['header_cta_url']) ?>"></label></div>
        <?php else: ?><div class="grid gap-4 md:grid-cols-2"><label class="md:col-span-2"><span>Description below logo</span><textarea class="form-input" rows="4" maxlength="800" name="chrome[<?= $escape($code) ?>][footer_description]"><?= $escape($copy['footer_description']) ?></textarea></label><label><span>Directions label</span><input class="form-input" maxlength="80" name="chrome[<?= $escape($code) ?>][footer_directions_label]" value="<?= $escape($copy['footer_directions_label']) ?>"></label><label><span>Directions destination</span><input class="form-input" maxlength="500" name="chrome[<?= $escape($code) ?>][footer_directions_url]" value="<?= $escape($copy['footer_directions_url']) ?>"></label><label><span>Explore heading</span><input class="form-input" maxlength="80" name="chrome[<?= $escape($code) ?>][footer_explore_title]" value="<?= $escape($copy['footer_explore_title']) ?>"></label><label><span>Connect heading</span><input class="form-input" maxlength="80" name="chrome[<?= $escape($code) ?>][footer_connect_title]" value="<?= $escape($copy['footer_connect_title']) ?>"></label><label><span>Copyright line 1</span><input class="form-input" maxlength="240" name="chrome[<?= $escape($code) ?>][copyright_line_1]" value="<?= $escape($copy['copyright_line_1']) ?>"></label><label><span>Copyright line 2</span><input class="form-input" maxlength="240" name="chrome[<?= $escape($code) ?>][copyright_line_2]" value="<?= $escape($copy['copyright_line_2']) ?>"></label><p class="md:col-span-2 text-xs text-default-500">The address and contact data come from the school profile. Explore and Connect links are managed below. Available tokens: <code>{{year}}</code>, <code>{{school}}</code>, <code>{{email}}</code> and <code>{{map_url}}</code>.</p></div><?php endif; ?>
    </section><?php endforeach; ?></div>
</form>
<?php
$menuEditor=['id'=>'content-navigation-form','location'=>$location,'title'=>$location==='primary'?'Header navigation':'Explore links','overline'=>'MULTILINGUAL MENU STRUCTURE','description'=>$location==='primary'?'Links displayed in the primary website navigation.':'Links displayed in the third footer column under Explore.','action'=>$location==='primary'?'Save header navigation':'Save Explore links','items'=>$items];
require __DIR__.'/console-content-navigation-editor.php';
if($location==='footer'){
    $menuEditor=['id'=>'footer-connect-navigation-form','location'=>'footer-connect','title'=>'Connect links','overline'=>'FOOTER · CONNECT COLUMN','description'=>'Additional calls to action displayed below the school contact details. Add, translate, reorder, hide or remove links here.','action'=>'Save Connect links','items'=>$connectItems];
    require __DIR__.'/console-content-navigation-editor.php';
}
?>
<template data-navigation-template><?php $index='__INDEX__';$item=['id'=>0,'target'=>'_self','visible'=>1,'translations'=>[]];require __DIR__.'/console-content-navigation-item.php'; ?></template></section>
</section>
