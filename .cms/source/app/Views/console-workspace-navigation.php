<?php
$workspaceContext ??= ['id'=>0,'label'=>'All facilities','options'=>[]];
$navigationLabels=['/dashboard'=>'Overview','/content/facilities'=>'Facilities','/content/workflow'=>'Editorial workflow','/content/pages'=>'Pages','/content/builder'=>'Page Builder','/content/posts'=>'Posts','/content/categories'=>'Categories','/content/media'=>'Media Library','/content/navigation'=>'Navigation','/seo'=>'SEO','/conversations'=>'Live chat','/forms/submissions'=>'Form inbox','/surveys'=>'Surveys','/ai'=>'AI','/appearance'=>'Appearance','/appearance/themes'=>'Themes','/marketplace'=>'Marketplace','/system/extensions'=>'Extensions','/system/access'=>'Access control'];
$crumbs=[['Overview','/dashboard']];
if($active!=='/dashboard')$crumbs[]=[$navigationLabels[$active]??$title,$active];
if($screen==='ai-knowledge'){$crumbs[]=['Knowledge Base','/ai/knowledge'];$crumbs[]=[['sources'=>'Sources','content'=>'Website content','index'=>'RAG index','training'=>'Training & evals'][$knowledgeTab??($_GET['tab']??'sources')]??'Sources',''];}
elseif($screen==='live-chat-config')$crumbs[]=[$active==='/ai'?'Visitor assistant':'Configuration',''];
elseif($screen==='facilities'&&($_GET['tab']??'')==='geolocation')$crumbs[]=['Nearest-facility experience',''];
elseif(in_array($screen,['page-form','post-form'],true)){$crumbs[count($crumbs)-1][1]=$contentReturn??$active;$crumbs[]=[$title,''];}
elseif($screen==='access-control')$crumbs[]=[['users'=>'Users','teams'=>'Teams','roles'=>'Roles','audit'=>'Audit log'][$accessTab??'users']??'Users',''];
elseif($screen==='extension'&&$crumbs[count($crumbs)-1][0]!==$title)$crumbs[]=[$title,''];
$scopedScreens=['dashboard','workflow','pages','page-form','page-builder','posts','post-form','media-library','seo','form-inbox','surveys'];
$globalSeo = $screen === 'seo' && ($_GET['scope'] ?? '') === 'global';
$contextScoped=in_array($screen,$scopedScreens,true) && !$globalSeo;
$scopeText=$contextScoped?($workspaceContext['id']?$workspaceContext['label']:'All accessible facilities'):'Shared settings';
if(!$contextScoped&&in_array($screen,['conversations','ai-knowledge','marketplace'],true))$scopeText='Shared across facilities';
if($screen==='facilities')$scopeText='Facility directory';
$scopeDetail=$contextScoped?'Content follows the selected facilities. Shared resources remain available.':'Applies to all facilities. Your facility selection is retained.';
if($screen==='facilities')$scopeDetail='Manage facilities here; choose a working context to focus the content workspace.';
if($screen==='seo' && !$globalSeo)$scopeDetail='Document metadata follows your context. Site defaults apply to all facilities.';
$recordId=$screen==='page-builder'?(int)($builderDocument['id']??0):(in_array($screen,['page-form','post-form'],true)?(int)($document['id']??0):($screen==='seo' && !$globalSeo?(int)($selected??0):0));
$recordKind=$screen==='post-form'||($documentType??'')==='post'?'post':'page';
$recordBase='/content/'.($recordKind==='post'?'posts':'pages');
$returnQuery='&amp;return='.rawurlencode($contentReturn??$recordBase);
if($recordId&&in_array($screen,['page-builder','seo'],true))$crumbs=[['Overview','/dashboard'],[$recordKind==='post'?'Posts':'Pages',$contentReturn??$recordBase],['Details',$recordBase.'/'.$recordId.'/edit?return='.rawurlencode($contentReturn??$recordBase)],[$title,'']];
$headingDetails = [
    'dashboard'=>['layout-dashboard','Your content, activity and next steps at a glance.'],
    'facilities'=>['building-2','Manage your locations and their public presence.'],
    'workflow'=>['git-pull-request-arrow','Review content and coordinate publication.'],
    'pages'=>['files','Organize your website pages and translations.'],
    'page-form'=>['file-pen-line','Edit page content, translations and publication settings.'],
    'page-builder'=>['panels-top-left','Compose your page with reusable content blocks.'],
    'posts'=>['newspaper','Manage your stories and publication schedule.'],
    'post-form'=>['square-pen','Edit your story and prepare it for publication.'],
    'categories'=>['tags','Keep your content organized and easy to discover.'],
    'media-library'=>['images','Manage the images and files used across your website.'],
    'navigation'=>['list-tree','Shape how visitors find their way around your website.'],
    'seo'=>['search','Manage how your content appears in search results.'],
    'conversations'=>['messages-square','Stay connected with your website visitors.'],
    'live-chat-config'=>['messages-square','Configure visitor assistance and live support.'],
    'form-inbox'=>['inbox','Review and manage enquiries from your website.'],
    'surveys'=>['clipboard-list','Collect feedback and understand your audience.'],
    'ai'=>['bot-message-square','Manage AI assistance and provider connections.'],
    'ai-knowledge'=>['brain-circuit','Manage the knowledge available to your visitor assistant.'],
    'appearance'=>['palette','Define your website identity and visual defaults.'],
    'themes'=>['panels-top-left','Choose and configure your website presentation.'],
    'marketplace'=>['store','Discover themes and extensions for your website.'],
    'plugins'=>['blocks','Manage installed features and package updates.'],
    'access-control'=>['shield-user','Manage accounts, teams and access permissions.'],
    'email'=>['mail-check','Manage email delivery and message templates.'],
    'notification-settings'=>['bell-ring','Choose how your team receives updates.'],
    'system-update'=>['refresh-cw','Review available updates for your installation.'],
    'search-index'=>['scan-search','Keep administration search up to date.'],
    'cache'=>['database-zap','Manage cached data and application performance.'],
    'sounds'=>['volume-2','Customize audio feedback for application events.'],
    'captcha'=>['shield-check','Manage protection for administrator sign-in.'],
    'languages'=>['languages','Manage the languages available on your website.'],
    'license'=>['badge-check','Review and validate your installation license.'],
    'profile'=>['user-round','Manage your profile and contact information.'],
    'settings'=>['settings-2','Manage your account preferences and security.'],
    'home'=>['house','Manage your homepage content and presentation.'],
    'home-popup'=>['panel-top','Configure messages displayed to website visitors.'],
];
[$headingIcon,$headingDescription]=$headingDetails[$screen]??['blocks','Manage the settings and content for this section.'];
if($screen==='extension')foreach($extensionNavigation??[]as$item)if($item['url']===$active){$headingIcon=$item['icon'];break;}
if($screen==='extension')$headingDescription=match($active){'/calendar'=>'Coordinate events, facilities and resources.','/social-publishing'=>'Manage publishing destinations and delivery history.',default=>$headingDescription};
?>
<div class="sensecms-navigation-heading sensecms-page-heading">
<div class="sensecms-page-identity"><span class="sensecms-page-icon" aria-hidden="true"><i data-lucide="<?= $escape($headingIcon) ?>"></i></span><div>
<h1><?= $escape($uiText($title)) ?></h1>
<p><?= $escape($uiText($headingDescription)) ?></p></div></div>
<?php if(count($crumbs)>1): ?><nav class="sensecms-breadcrumbs" aria-label="<?= $escape($uiText('Breadcrumb')) ?>"><?php foreach($crumbs as$index=>[$label,$url]): ?><?php if($index): ?><span aria-hidden="true">›</span><?php endif; ?><?php if($url!==''&&$index<count($crumbs)-1): ?><a href="<?= $escape($url) ?>"><?= $escape($uiText($label)) ?></a><?php else: ?><span aria-current="page"><?= $escape($uiText($label)) ?></span><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
</div>
<div class="sensecms-workspace-status<?= $screen==='facilities'?' sensecms-facility-context-data':'' ?>" <?= $screen==='facilities'?'hidden':'' ?> data-workspace-id="<?= (int)($workspaceContext['id']??0) ?>" data-editor-languages="<?= $escape(json_encode(array_column($contentLanguages??[],'locale'))) ?>" data-editor-locale="<?= $escape($contentLocale??'en') ?>"><span><i data-lucide="<?= $contextScoped?'building-2':'globe' ?>"></i><strong><?= $escape($contextScoped && $workspaceContext['id'] ? $scopeText : $uiText($scopeText)) ?></strong></span><small><?= $escape($uiText($scopeDetail)) ?></small></div>
<?php if($recordId&&in_array($screen,['page-form','post-form','page-builder','seo'],true)): ?>
<nav class="sensecms-context-links sensecms-section-tabs" aria-label="Current <?= $recordKind ?> tools">
    <?php if($can($recordKind==='post'?'content.posts.view':'content.pages.view')): ?><a href="<?= $escape($contentReturn??$recordBase) ?>"><i data-lucide="arrow-left"></i><?= $recordKind==='post'?$uiText('Posts'):$uiText('Pages') ?></a>
    <a href="<?= $recordBase ?>/<?= $recordId ?>/edit?return=<?= rawurlencode($contentReturn??$recordBase) ?>" <?= in_array($screen,['page-form','post-form'],true)?'aria-current="page"':'' ?>><?= $escape($uiText('Details')) ?></a><?php endif; ?>
    <?php if($recordKind==='page'&&$can('content.pages.collaborate')): ?><a href="/content/builder?document=<?= $recordId ?><?= $returnQuery ?>" <?= $screen==='page-builder'?'aria-current="page"':'' ?>><?= $escape($uiText('Page Builder')) ?></a><?php endif; ?>
    <?php if($can('content.seo.manage')): ?><a href="/seo?type=<?= $recordKind ?>&amp;document=<?= $recordId ?><?= $returnQuery ?>" <?= $screen==='seo'?'aria-current="page"':'' ?>>SEO</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php if(in_array($screen,['appearance','themes','marketplace','plugins'],true)): ?>
<nav class="sensecms-context-links sensecms-section-tabs" aria-label="<?= $escape($uiText('Website and packages')) ?>">
<?php foreach([['/appearance','Brand settings','appearance.manage'],['/appearance/themes','Installed themes','appearance.manage'],['/marketplace','Find packages','extensions.manage'],['/system/extensions','Installed extensions','extensions.manage']]as[$url,$label,$permission]):if(!$can($permission))continue; ?><a href="<?= $url ?>" <?= $active===$url?'aria-current="page"':'' ?>><?= $escape($uiText($label)) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
