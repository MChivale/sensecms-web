<?php
declare(strict_types=1);

// Disposable schema only; never accepts a database name or production connection.
if(PHP_SAPI!=='cli'||PHP_OS_FAMILY==='Windows')throw new RuntimeException('Run on private Linux MariaDB QA.');
$root=dirname(__DIR__).'/.cms/source';require $root.'/bootstrap.php';
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$schema='senseqa_knowledge_'.bin2hex(random_bytes(6));$created=false;$checks=0;
$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";};
try{
    $db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;$db->exec("USE `$schema`");
    foreach(explode(';',(string)file_get_contents($root.'/database/001_core.sql'))as$sql)if(trim($sql)!=='')$db->exec($sql);
    $db->prepare('INSERT INTO migrations (name,checksum,applied_at) VALUES (?,?,NOW())')->execute(['001_core',hash_file('sha256',$root.'/database/001_core.sql')]);
    (new App\Installer\WorkspaceMigration($db,$root))->apply();
    $db->exec("INSERT INTO facilities (uid,city_slug,facility_slug,status,is_primary,created_at,updated_at) VALUES (UUID(),'qa','qa','active',1,NOW(),NOW())");$facility=(int)$db->lastInsertId();
    $page=static function(string$slug,string$status='published',string$visibility='public')use($db,$facility):int{
        $db->prepare('INSERT INTO pages (facility_id,public_path,status,visibility,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())')->execute([$facility,'/'.$slug,$status,$visibility]);$id=(int)$db->lastInsertId();
        foreach(['en','pl']as$locale)$db->prepare('INSERT INTO page_translations (page_id,facility_id,locale,title,slug) VALUES (?,?,?,?,?)')->execute([$id,$facility,$locale,$slug,$slug.'-'.$locale]);return$id;
    };
    $first=$page('packages');$second=$page('pricing');$private=$page('private','published','private');$draft=$page('draft','draft');
    $db->exec("INSERT INTO global_sections (uid,name,type,source_theme,created_at,updated_at) VALUES (UUID(),'Shared pricing','text','core',NOW(),NOW())");$global=(int)$db->lastInsertId();
    $globalText=static function(string$locale,string$text)use($db,$global):void{$db->prepare('INSERT INTO global_section_translations (global_section_id,locale,data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)')->execute([$global,$locale,json_encode(['content'=>$text])]);};
    $globalText('en','The annual system price is USD 360.');$globalText('pl','Roczna cena systemu wynosi 360 USD.');
    $block=static function(int$pageId,?int$globalId,string$text,int$visible=1,?string$from=null)use($db):int{
        $db->prepare("INSERT INTO content_blocks (uid,page_id,type,source_theme,global_section_id,visible,visible_from,created_at,updated_at) VALUES (UUID(),?,'text','core',?,?,?,NOW(),NOW())")->execute([$pageId,$globalId,$visible,$from]);$id=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO content_block_translations (block_id,locale,data) VALUES (?,\'en\',?)')->execute([$id,json_encode(['content'=>$text])]);return$id;
    };
    foreach([$first,$second,$private,$draft]as$id)$block($id,$global,'Obsolete local copy.');
    $block($first,null,'Fallback public information.');$block($first,null,'Hidden marker.',0);$block($first,null,'Future marker.',1,'2099-01-01 00:00:00');
    $service=new App\Core\AiKnowledgeBase($db,$root);$result=$service->rebuild(0);
    $check($result['failed']===0&&$result['synced']===4,'Full rebuild indexes only the two public pages in two languages');
    $document=static function(int$id,string$locale)use($db):array{$q=$db->prepare("SELECT * FROM ai_knowledge_documents WHERE source_type='page' AND source_id=? AND locale=?");$q->execute([$id,$locale]);return$q->fetch()?:[];};
    $body=$document($first,'pl')['body'];
    $check($document($first,'en')['source_url']==='/packages'&&$document($first,'pl')['source_url']==='/pl/packages-pl','Source links preserve the default public path and translated page slug');
    $check(str_contains($body,'Roczna cena systemu wynosi 360 USD.')&&!str_contains($body,'Obsolete'),'Polish shared text overrides stale local block text');
    $check(str_contains($body,'Fallback public information.'),'Untranslated local blocks follow the public default-language fallback');
    $check(!str_contains($body,'Hidden marker')&&!str_contains($body,'Future marker'),'Hidden and future sections stay outside the index');
    $check($document($private,'pl')===[]&&$document($draft,'pl')===[],'Private and draft pages are excluded');
    $globalText('pl','Aktualna cena roczna wynosi 420 USD.');$service->queueWebsiteSource('page',$first);
    $check((int)$db->query('SELECT COUNT(*) FROM ai_knowledge_sync_queue')->fetchColumn()===4,'A shared edit queues all linked pages, deduplicated');
    $result=$service->processQueue(20);$check($result['failed']===0&&$result['processed']===4,'Incremental synchronization processes linked pages');
    foreach([$first,$second]as$id)$check(str_contains($document($id,'pl')['body'],'Aktualna cena roczna wynosi 420 USD.'),'Linked page receives the new shared translation');
    $check($document($second,'pl')['source_url']==='/pl/pricing-pl','Incremental synchronization retains localized source links');
    $db->prepare('UPDATE facilities SET homepage_page_id=? WHERE id=?')->execute([$first,$facility]);$service->rebuild(0);
    $check($document($first,'pl')['source_url']==='/pl/home','Translated facility homepage follows the existing public route');
    $db->prepare('UPDATE facilities SET is_primary=0 WHERE id=?')->execute([$facility]);$service->rebuild(0);
    $check($document($first,'pl')['source_url']==='/pl/facilities/qa/qa'&&$document($second,'pl')['source_url']==='/pl/facilities/qa/qa/pricing-pl','Non-primary facility URLs retain their scoped route');
    $db->prepare('UPDATE facilities SET is_primary=1 WHERE id=?')->execute([$facility]);
    $db->prepare("DELETE FROM global_section_translations WHERE global_section_id=? AND locale='pl'")->execute([$global]);
    $service->queueWebsiteSource('page',$first);$service->processQueue(20);
    $check(str_contains($document($first,'pl')['body'],'The annual system price is USD 360.'),'Missing shared translation falls back exactly as public rendering does');
    $db->prepare('UPDATE global_sections SET active=0 WHERE id=?')->execute([$global]);$service->rebuild(0);
    $check(str_contains($document($first,'pl')['body'],'Obsolete local copy.')&&!str_contains($document($first,'pl')['body'],'annual system price'),'Inactive shared section falls back to local content');
    $db->prepare("UPDATE pages SET visibility='private' WHERE id=?")->execute([$first]);$service->queueWebsiteSource('page',$first);$service->processQueue(20);
    $check($document($first,'pl')['status']==='archived','Changing visibility removes a page from retrieval');
    $q=$db->prepare('SELECT COUNT(*) FROM ai_knowledge_chunks WHERE document_id=?');$q->execute([$document($first,'pl')['id']]);$check((int)$q->fetchColumn()===0,'Removed public content leaves no retrievable chunks');
    $service->saveManual(0,'Sense CMS pricing','Sense CMS costs USD 360 per year.','en',true,0);
    $service->saveManual(0,'Cennik Sense CMS','Roczna cena systemu Sense CMS to 360 USD.','pl',true,0);
    $service->saveManual(0,'Telegram Notifications','Sense CMS supports Telegram notifications and channels.','en',true,0);
    $service->saveManual(0,'Sense CMS private price','Sense CMS costs USD 1 per year.','en',false,0);
    for($i=0;$i<10;$i++)$service->saveManual(0,'Contact '.$i,'Contact our team. Sense CMS is the system for your website and your content.','en',true,0);
    $repository=new App\Core\AiRepository($db);
    $matches=$repository->contextMatches('How much does Sense CMS cost?','en');
    $check(($matches[0]['title']??'')==='Sense CMS pricing','Price intent outranks common product names and contact copy');
    $check(!in_array('Sense CMS private price',array_column($matches,'title'),true),'Draft knowledge cannot enter ranked retrieval');
    $matches=$repository->contextMatches('Ile kosztuje Sense CMS?','pl');
    $check(($matches[0]['title']??'')==='Cennik Sense CMS','Polish price inflections retrieve the pricing source');
    $matches=$repository->contextMatches('Do you support Telegram?','en');
    $check(($matches[0]['title']??'')==='Telegram Notifications','Specific provider title outranks generic support words');
    $check($repository->contextMatches('quantumxyz','en')===[],'Unrelated queries return no invented match');
    $db->prepare("UPDATE facilities SET status='draft' WHERE id=?")->execute([$facility]);$service->rebuild(0);
    $check($document($second,'pl')['status']==='archived','Inactive facilities no longer contribute public knowledge');
    $other=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.$schema,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $lockName='sensecms.ai.knowledge.sync.'.substr(hash('sha256',$schema),0,24);
    $other->prepare('SELECT GET_LOCK(?,0)')->execute([$lockName]);
    try{
        $before=$service->summary();
        $check(!empty($service->rebuild(0)['locked']),'Full rebuild refuses an index lock held by another connection');
        $check(!empty($service->processQueue(10)['locked']),'Incremental work uses the same lock as full rebuild');
        $check($service->summary()===$before,'Rejected synchronization leaves the index unchanged');
    }finally{$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
    $check(empty($service->rebuild(0)['locked']),'Full rebuild succeeds after the competing lock is released');
    $free=$other->prepare('SELECT IS_FREE_LOCK(?)');$free->execute([$lockName]);$check((int)$free->fetchColumn()===1,'Successful rebuild releases its synchronization lock');
    $db->exec('RENAME TABLE pages TO pages_unavailable');$failed=false;
    try{$service->rebuild(0);}catch(Throwable){$failed=true;}finally{$db->exec('RENAME TABLE pages_unavailable TO pages');}
    $free->execute([$lockName]);$check($failed&&(int)$free->fetchColumn()===1&&!$db->inTransaction(),'Failed rebuild releases its lock and rolls back its transaction');
    echo "{$checks} isolated Knowledge Base runtime checks passed.\n";
}finally{if($db->inTransaction())$db->rollBack();if($created)$db->exec("DROP DATABASE `$schema`");}
