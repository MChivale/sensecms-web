<?php
declare(strict_types=1);

// Official-site publication only. No customer seed, demo import or external messages.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--check','--qa','--apply','--verify','--rollback'], true)) throw new RuntimeException('Choose a publication mode.');
$mode=$argv[1];$stage=realpath($argv[2]??'');$backup=realpath($argv[3]??'');$root='/home/sensecms.com/web';
if(!$stage||!is_file($stage.'/.themes/sensecms/industry-pages.php'))throw new RuntimeException('Missing reviewed source.');
require $root.'/bootstrap.php';
$definitions=require $stage.'/.themes/sensecms/industry-pages.php';
if(array_keys($definitions)!==['/platform/companies','/platform/schools','/platform/hospitality','/platform/beauty'])throw new RuntimeException('Unexpected publication scope.');
$schema=null;$db=null;
try {
    if($mode==='--qa'){
        $schema='senseindustry_'.bin2hex(random_bytes(6));
        $db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$schema`");
        foreach(explode(';',(string)file_get_contents($root.'/database/001_core.sql'))as$sql)if(trim($sql)!=='')$db->exec($sql);
        $db->exec("INSERT INTO users(name,email,password,created_at) VALUES('Publication QA','publication@example.test','disabled',NOW()); INSERT INTO roles(slug,name) VALUES('owner','Owner'); INSERT INTO user_roles VALUES(1,1)");
        $db->prepare('INSERT INTO migrations(name,checksum,applied_at) VALUES(?,?,NOW())')->execute(['001_core',hash_file('sha256',$root.'/database/001_core.sql')]);
        (new App\Installer\WorkspaceMigration($db,$root))->apply();
        $db->exec("UPDATE languages SET enabled=1,is_default=(locale='en') WHERE locale IN ('en','pl'); INSERT INTO facilities(uid,city_slug,facility_slug,status,is_primary,created_at,updated_at) VALUES(UUID(),'qa','qa','active',1,NOW(),NOW()); INSERT INTO pages(facility_id,public_path,status,visibility,created_at,updated_at) VALUES(1,'/platform','published','public',NOW(),NOW())");
    } else {
        $runtime=new App\Core\Runtime($root);
        if($runtime->baseUrl()!=='https://www.sensecms.com')throw new RuntimeException('Wrong target installation.');
        $db=App\Core\Runtime::connect($runtime->read('installed')['database']);
    }
    $cms=new App\Core\CmsRepository($db,new App\Core\EventBus());
    $parent=$db->query("SELECT id,facility_id FROM pages WHERE public_path='/platform' AND status='published' AND visibility='public'")->fetch();
    if(!$parent)throw new RuntimeException('Published Platform parent missing.');
    $owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
    if(!$owner)throw new RuntimeException('No active owner.');
    $catalog=App\Core\PageBuilder::catalog(json_decode(file_get_contents($stage.'/.themes/sensecms/theme.json'),true,512,JSON_THROW_ON_ERROR));
    $journal=$backup?$backup.'/industry-pages.json':null;
    $state=['pages'=>[],'settings'=>[]];
    $persist=static function()use(&$state,$mode,$journal):void{if($mode==='--apply'){if(!$journal||file_put_contents($journal.'.tmp',json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false||!rename($journal.'.tmp',$journal))throw new RuntimeException('Cannot persist recovery journal.');}};
    $verify=static function()use($cms,$db,&$state):void{
        foreach($state['pages']as$doc){
            if($cms->builderDocument((int)$doc['id'])!==$doc)throw new RuntimeException('New editorial work prevents recovery.');
            foreach(['page_builder_drafts','page_builder_section_locks']as$table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE page_id=?');$q->execute([$doc['id']]);if((int)$q->fetchColumn())throw new RuntimeException('Active editorial work prevents recovery.');}
        }
        foreach($state['settings']as$key=>$expected){$q=$db->prepare('SELECT value FROM settings WHERE `key`=?');$q->execute([$key]);if($q->fetchColumn()!==$expected)throw new RuntimeException('New SEO edits prevent recovery.');}
    };
    $rollback=static function()use($verify,$cms,$owner,&$state):void{$verify();foreach($state['pages']as$doc){if(!$cms->setPageStatus((int)$doc['id'],'archived',$owner))throw new RuntimeException('Could not archive published page.');if($cms->pageAdmin((int)$doc['id'])['status']!=='archived')throw new RuntimeException('Recovery status differs.');}};
    if(in_array($mode,['--verify','--rollback'],true)){
        if(!$journal||!is_file($journal))throw new RuntimeException('Recovery journal missing.');
        $state=json_decode(file_get_contents($journal),true,512,JSON_THROW_ON_ERROR);
        if(array_diff(array_keys($state['pages']),array_keys($definitions)))throw new RuntimeException('Journal scope differs.');
        if($mode==='--verify'){$verify();echo "Created pages and SEO match the publication journal.\n";}
        else{$rollback();foreach($state['pages']as$doc)(new App\Core\AiKnowledgeBase($db,$root))->queueWebsiteSource('page',(int)$doc['id']);echo "Created pages archived; data retained.\n";}
        exit;
    }
    foreach($definitions as$path=>$definition){
        $q=$db->prepare('SELECT COUNT(*) FROM pages WHERE public_path=?');$q->execute([$path]);
        if((int)$q->fetchColumn())throw new RuntimeException('Page path already exists: '.$path);
        $q=$db->prepare("SELECT COUNT(*) FROM page_translations WHERE slug=? AND locale IN ('en','pl')");$q->execute([$definition['slug']]);
        if((int)$q->fetchColumn())throw new RuntimeException('Translation slug already exists.');
    }
    if($mode==='--check'){echo "Four new EN/PL routes are available; existing pages will not be edited.\n";exit;}
    if($mode==='--apply'&&(!$backup||is_file($journal)))throw new RuntimeException('Choose a fresh recovery directory.');
    foreach($definitions as$path=>$definition){
        $translations=[];$blocks=[];
        foreach(['en','pl']as$locale){$copy=$definition[$locale];$translations[$locale]=['title'=>$copy['title'],'slug'=>$definition['slug'],'excerpt'=>$copy['description'],'seo_title'=>$copy['title'].' · Sense CMS','seo_description'=>$copy['description']];}
        $id=$cms->savePage(['id'=>0,'public_path'=>$path,'parent_id'=>$parent['id'],'facility_id'=>$parent['facility_id'],'template'=>'product','status'=>'published','visibility'=>'public','published_at'=>''],$translations,$owner);
        $state['pages'][$path]=$cms->builderDocument($id);$persist();
        foreach($definition['en']['sections']as$i=>$_){
            $block=['uid'=>sprintf('28000000-0000-4000-8000-%012d',$id*100+$i),'type'=>'text','source_theme'=>'core','visible'=>true,'shared'=>[],'localized'=>[]];
            foreach(['en','pl']as$locale){[$title,$text]=$definition[$locale]['sections'][$i];$block['localized'][$locale]=['title'=>$title,'text'=>'<p>'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>'];}
            $blocks[]=$block;
        }
        $blocks=App\Core\PageBuilder::sanitizeBlocks($blocks,$catalog,['en','pl']);
        foreach($blocks as&$block)$block['source']='core';unset($block);
        $cms->saveBuilderDocument($id,0,$owner,'sensecms',$blocks,array_keys($catalog));
        $state['pages'][$path]=$cms->builderDocument($id);$persist();
        foreach(['en','pl']as$locale){
            $copy=$definition[$locale];$key='seo_page_'.$id.'_'.$locale;
            $meta=App\Core\SeoMeta::sanitizeDocument(['title'=>$copy['title'].' · Sense CMS','description'=>$copy['description'],'keywords'=>$copy['keywords'],'og_image'=>'/theme-assets/share-platform-'.$locale.'.png','og_image_alt'=>$copy['title'],'og_image_width'=>1200,'og_image_height'=>630,'og_image_type'=>'image/png'],'page');
            $cms->saveSetting($key,$meta);$q=$db->prepare('SELECT value FROM settings WHERE `key`=?');$q->execute([$key]);$state['settings'][$key]=(string)$q->fetchColumn();$persist();
        }
        if($state['pages'][$path]['public_path']!==$path||count($state['pages'][$path]['blocks'])!==5||count($state['pages'][$path]['translations'])!==2)throw new RuntimeException('Incomplete published document.');
    }
    $verify();
    if($mode==='--qa'){
        $first=(int)reset($state['pages'])['id'];$db->prepare("UPDATE page_translations SET title='Intervening edit' WHERE page_id=? AND locale='en'")->execute([$first]);
        $rejected=false;try{$verify();}catch(RuntimeException){$rejected=true;}
        if(!$rejected)throw new RuntimeException('Recovery must reject newer edits.');
        $db->prepare("UPDATE page_translations SET title=? WHERE page_id=? AND locale='en'")->execute([reset($state['pages'])['translations']['en']['title'],$first]);
        $rollback();echo "Isolated creation, multilingual Builder/SEO verification, edit-conflict guard and archival rollback passed.\n";
    }else{foreach($state['pages']as$doc)(new App\Core\AiKnowledgeBase($db,$root))->queueWebsiteSource('page',(int)$doc['id']);echo "Four editable pages, eight translations and eight SEO records published.\n";}
} finally {
    if($schema!==null&&preg_match('/^senseindustry_[a-f0-9]{12}$/D',$schema))$db->exec("DROP DATABASE `$schema`");
}
