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
elseif($screen==='extension'&&($navigationLabels[$active]??'')!==$title)$crumbs[]=[$title,''];
$scopedScreens=['dashboard','workflow','pages','page-form','page-builder','posts','post-form','media-library','seo','form-inbox','surveys'];
$contextScoped=in_array($screen,$scopedScreens,true);
$scopeText=$contextScoped?($workspaceContext['id']?$workspaceContext['label']:'All accessible facilities'):'Shared settings';
if(!$contextScoped&&in_array($screen,['conversations','ai-knowledge','marketplace'],true))$scopeText='Shared across facilities';
if($screen==='facilities')$scopeText='Facility directory';
$scopeDetail=$contextScoped?'Content follows the selected facilities. Shared resources remain available.':'Applies to all facilities. Your facility selection is retained.';
if($screen==='facilities')$scopeDetail='Manage facilities here; choose a working context to focus the content workspace.';
if($screen==='seo')$scopeDetail='Document metadata follows your context. Site defaults apply to all facilities.';
$recordId=$screen==='page-builder'?(int)($builderDocument['id']??0):(in_array($screen,['page-form','post-form'],true)?(int)($document['id']??0):($screen==='seo'?(int)($selected??0):0));
$recordKind=$screen==='post-form'||($documentType??'')==='post'?'post':'page';
$recordBase='/content/'.($recordKind==='post'?'posts':'pages');
$returnQuery='&amp;return='.rawurlencode($contentReturn??$recordBase);
if($recordId&&in_array($screen,['page-builder','seo'],true))$crumbs=[['Overview','/dashboard'],[$recordKind==='post'?'Posts':'Pages',$contentReturn??$recordBase],['Details',$recordBase.'/'.$recordId.'/edit?return='.rawurlencode($contentReturn??$recordBase)],[$title,'']];
?>
<div class="sensecms-navigation-heading"><h1><?= $escape($title) ?></h1><nav class="sensecms-breadcrumbs" aria-label="Breadcrumb"><?php foreach($crumbs as$index=>[$label,$url]): ?><?php if($index): ?><span aria-hidden="true">›</span><?php endif; ?><?php if($url!==''&&$index<count($crumbs)-1): ?><a href="<?= $escape($url) ?>"><?= $escape($label) ?></a><?php else: ?><span aria-current="page"><?= $escape($label) ?></span><?php endif; ?><?php endforeach; ?></nav></div>
<div class="sensecms-workspace-status" data-workspace-id="<?= (int)($workspaceContext['id']??0) ?>" data-editor-languages="<?= $escape(json_encode(array_column($contentLanguages??[],'locale'))) ?>" data-editor-locale="<?= $escape($contentLocale??'en') ?>"><span><i data-lucide="<?= $contextScoped?'building-2':'globe' ?>"></i><strong><?= $escape($scopeText) ?></strong></span><small><?= $escape($scopeDetail) ?></small></div>
<?php if($recordId&&in_array($screen,['page-form','post-form','page-builder','seo'],true)): ?>
<nav class="sensecms-context-links sensecms-section-tabs" aria-label="Current <?= $recordKind ?> tools">
    <?php if($can($recordKind==='post'?'content.posts.view':'content.pages.view')): ?><a href="<?= $escape($contentReturn??$recordBase) ?>"><i data-lucide="arrow-left"></i><?= $recordKind==='post'?'Posts':'Pages' ?></a>
    <a href="<?= $recordBase ?>/<?= $recordId ?>/edit?return=<?= rawurlencode($contentReturn??$recordBase) ?>" <?= in_array($screen,['page-form','post-form'],true)?'aria-current="page"':'' ?>>Details</a><?php endif; ?>
    <?php if($recordKind==='page'&&$can('content.pages.collaborate')): ?><a href="/content/builder?document=<?= $recordId ?><?= $returnQuery ?>" <?= $screen==='page-builder'?'aria-current="page"':'' ?>>Page Builder</a><?php endif; ?>
    <?php if($can('content.seo.manage')): ?><a href="/seo?type=<?= $recordKind ?>&amp;document=<?= $recordId ?><?= $returnQuery ?>" <?= $screen==='seo'?'aria-current="page"':'' ?>>SEO</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php if(in_array($screen,['appearance','themes','marketplace','plugins'],true)): ?>
<nav class="sensecms-context-links sensecms-section-tabs" aria-label="Website and packages">
<?php foreach([['/appearance','Brand settings','appearance.manage'],['/appearance/themes','Installed themes','appearance.manage'],['/marketplace','Find packages','extensions.manage'],['/system/extensions','Installed extensions','extensions.manage']]as[$url,$label,$permission]):if(!$can($permission))continue; ?><a href="<?= $url ?>" <?= $active===$url?'aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
