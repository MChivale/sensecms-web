<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2], ['--apply','--rollback'], true)) exit("Usage: php promote-marketplace.php <installation> --apply|--rollback <private-backup>\n");
umask(0077);
$root=realpath($argv[1]); $backup=realpath($argv[3]);
if (!$root || !$backup || !str_starts_with($backup,'/root/')) throw new RuntimeException('Private existing operator paths required.');
require $root.'/bootstrap.php';
$r=new App\Core\Runtime($root); $r->license()->enforce($r->baseUrl());
$db=App\Core\Runtime::connect($r->read('installed')['database']);
$cms=new App\Core\CmsRepository($db,new App\Core\EventBus(),$r);
$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
if (!$owner || $cms->defaultLocale()!=='en') throw new RuntimeException('Expected licensed English product website.');
$theme=(new App\Core\Packages\ThemeManager($r))->activePath();
$catalog=App\Core\PageBuilder::catalog(json_decode(file_get_contents($theme.'/theme.json'),true));
$save=static function(array $doc,array $blocks) use($cms,$catalog,$owner):void {
    $current=$cms->builderDocument((int)$doc['id']);
    $cms->saveBuilderDocument((int)$doc['id'],(int)$current['builder_version'],$owner,'sensecms',App\Core\PageBuilder::sanitizeBlocks($blocks,$catalog,array_keys($doc['translations'])),array_keys($catalog));
};
$file=$backup.'/entry-before.json';
$lock=fopen($backup.'/entry.lock','c+b');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Promotion already running.');
if ($argv[2]==='--rollback') {
    $state=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
    foreach ($state['pages'] as $doc) $save($doc,$doc['blocks']);
    $doc=$state['pages']['/extensions']; $cms->savePage($doc,$doc['translations'],$owner);
    foreach ($state['global'] as $id) $cms->archiveGlobalSection($id,$owner);
    echo "Restored original pages; content retained.\n"; exit;
}
if (file_exists($file)) throw new RuntimeException('Existing promotion journal; inspect before rerun.');
$state=['pages'=>[],'global'=>[]];
foreach (['/extensions','/extensions/catalog','/extensions/catalog/theme','/extensions/catalog/plugin','/extensions/catalog/addon','/extensions/catalog/module'] as $url) {
    $page=$cms->pageAtPath($url);
    if (!$page) throw new RuntimeException('Missing route: '.$url);
    $state['pages'][$url]=$cms->builderDocument((int)$page['id']);
}
$source=$state['pages']['/extensions/catalog'];
if (count($source['blocks'])!==13 || count($state['pages']['/extensions']['blocks'])!==6) throw new RuntimeException('Editorial baseline changed; review before promotion.');
$persist=static function()use(&$state,$file):void { if(file_put_contents($file,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Cannot save journal.'); };
$persist(); $shared=[];
foreach (App\Core\PageBuilder::sanitizeBlocks($source['blocks'],$catalog,array_keys($source['translations'])) as $block) {
    $url=$block['localized']['en']['cta_url']??'';
    if (!preg_match('~^/extensions/catalog/(system|theme|plugin|addon|application)/[a-z0-9-]+$~D',$url) || !empty($block['global_section_id'])) throw new RuntimeException('Unexpected product block.');
    $global=$cms->createGlobalSection('Marketplace: '.$block['localized']['en']['title'],$block,$owner);
    $state['global'][]=$global['id']; $persist();
    $block['global_section_id']=$global['id']; $block['global_version']=0; $shared[$url]=$block;
}
// Link existing category and catalogue cards to one editable shared product record.
foreach ($state['pages'] as $url=>$doc) {
    if ($url==='/extensions') continue;
    $blocks=$doc['blocks'];
    foreach ($blocks as &$block) {
        $link=$block['localized']['en']['cta_url']??'';
        if (isset($shared[$link])) $block=array_replace($shared[$link],['uid'=>$block['uid']]);
    }
    unset($block); $save($doc,$blocks);
}
$entry=$state['pages']['/extensions']; $blocks=array_values($shared);
foreach ($blocks as &$block) { $s=bin2hex(random_bytes(16)); $block['uid']=substr($s,0,8).'-'.substr($s,8,4).'-4'.substr($s,13,3).'-a'.substr($s,17,3).'-'.substr($s,20); }
unset($block); $save($entry,$blocks);
$entry['translations']['en']=array_replace($entry['translations']['en'],['title'=>'The right tools. One connected ecosystem.','excerpt'=>'Discover themes, integrations and extensions for Sense CMS. Find the tools that fit your project, with clear pricing and licence terms.','seo_title'=>'Sense CMS Marketplace — Themes, plugins and extensions','seo_description'=>'Explore the Sense CMS marketplace: themes, plugins, addons and applications with clear pricing and release availability.']);
$cms->savePage($entry,$entry['translations'],$owner);
echo "Promoted /extensions: 13 products with shared CMS content. Existing catalogue URLs retained.\n";
