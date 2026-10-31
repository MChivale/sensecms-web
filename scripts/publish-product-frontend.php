<?php
declare(strict_types=1);

// Scoped 2026-10-31 editorial release. Not a general content importer.
$changes = [
    '/platform' => [9 => [
        'en'=>['title'=>'Explore the working demo','text'=>'<p>Sense CMS runs this website, and a separate public demo is available to explore. The current installer has passed isolated licensed installation tests on Nginx and Apache over HTTPS. It remains a development candidate; no Stable Core release has been published.</p>','cta_label'=>'Try the demo →','cta_url'=>'https://demo.sensecms.com/'],
        'pl'=>['title'=>'Poznaj działające demo','text'=>'<p>Ta strona działa na Sense CMS. Możesz też poznać system w osobnym, publicznym demo. Obecny instalator przeszedł izolowane testy instalacji z licencją pod Nginx i Apache przez HTTPS. Pozostaje wersją rozwojową; stabilne wydanie Core nie zostało jeszcze opublikowane.</p>','cta_label'=>'Wypróbuj demo →','cta_url'=>'https://demo.sensecms.com/'],
    ]],
    '/download' => [
        43=>[
            'en'=>['text'=>'<p>The current installer has passed isolated licensed installation tests, including Nginx and Apache over HTTPS. It remains a development candidate. No Stable Core release has been published, and automatic Core installation is not available.</p>'],
            'pl'=>['text'=>'<p>Obecny instalator przeszedł izolowane testy instalacji z licencją, także pod Nginx i Apache przez HTTPS. Nadal jest wersją rozwojową. Stabilne wydanie Core nie zostało jeszcze opublikowane, a automatyczna instalacja aktualizacji Core nie jest dostępna.</p>'],
        ],
        45=>[
            'en'=>['title'=>'Demo and package availability','text'=>'<p>The public demo is open. Selected signed development packages are available through the catalogue with the required licence; each product lists its own status. Stable Core publication remains separate from the completed installation tests.</p>','cta_label'=>'Try the demo →','cta_url'=>'https://demo.sensecms.com/'],
            'pl'=>['title'=>'Demo i dostępność pakietów','text'=>'<p>Publiczne demo jest dostępne. Wybrane podpisane pakiety rozwojowe można pobrać z katalogu po weryfikacji wymaganej licencji; każdy produkt ma własny status dostępności. Publikacja stabilnego Core pozostaje osobnym etapem po zakończonych testach instalacji.</p>','cta_label'=>'Wypróbuj demo →','cta_url'=>'https://demo.sensecms.com/'],
        ],
    ],
    '/extensions/catalog/system/sensecms' => [
        82=>[
            'en'=>['text'=>'<p>The annual Sense CMS system licence is bound to your installation domain.</p><p>Core includes multilingual pages and posts, Page Builder, media, navigation and SEO; teams, editorial workflow and facility-scoped access; editorial AI, Knowledge Base and visitor assistant with your configured AI provider.</p><p>Paid extensions require separate product licences. Contact us to discuss deployment and confirm the scope for your project.</p>','cta_label'=>'Discuss deployment →','cta_url'=>'/contact'],
            'pl'=>['text'=>'<p>Roczna licencja systemu Sense CMS jest powiązana z domeną instalacji.</p><p>Core obejmuje wielojęzyczne strony i wpisy, Page Builder, media, nawigację i SEO; zespoły, proces redakcyjny i uprawnienia według placówek; AI dla redaktorów, Bazę Wiedzy i asystenta odwiedzających z wybranym i skonfigurowanym dostawcą AI.</p><p>Płatne rozszerzenia wymagają osobnych licencji produktowych. Skontaktuj się z nami, aby omówić wdrożenie i zakres dla Twojego projektu.</p>','cta_label'=>'Zapytaj o wdrożenie →','cta_url'=>'/contact'],
        ],
        83=>[
            'en'=>['text'=>'<p>The development installer has passed isolated licensed installation tests on Nginx and Apache over HTTPS. No Stable Core release or public Core download is available yet.</p>'],
            'pl'=>['text'=>'<p>Instalator rozwojowy przeszedł izolowane testy instalacji z licencją pod Nginx i Apache przez HTTPS. Stabilne wydanie Core ani publiczne pobieranie Core nie są jeszcze dostępne.</p>'],
        ],
    ],
];
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return $changes;
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--check','--qa','--apply','--rollback'], true)) throw new RuntimeException('Use --check, --qa, --apply or --rollback with the private stage and recovery directory.');
$mode=$argv[1];$stage=realpath($argv[2]??'');$backup=realpath($argv[3]??'');
if($stage!=='/root/sensecms-frontend-20261031'||($mode!=='--check'&&$mode!=='--qa'&&(!$backup||!str_starts_with($backup,'/root/sensecms-backups/'))))throw new RuntimeException('Unexpected private paths');
$root='/home/sensecms.com/web';require $root.'/bootstrap.php';
$runtime=new App\Core\Runtime($root);
if($runtime->baseUrl()!=='https://www.sensecms.com')throw new RuntimeException('Unexpected target');
$db=App\Core\Runtime::connect($runtime->read('installed')['database']);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='sensecms_site')throw new RuntimeException('Unexpected database');
$baseline=json_decode((string)file_get_contents($stage.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);
$schema=null;
try {
    if($mode==='--qa'){
        $db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $schema='sensefront_'.bin2hex(random_bytes(6));$db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$schema`");
        $tables=['languages','pages','page_translations','content_blocks','content_block_translations','page_builder_states','page_builder_revisions','page_builder_drafts','page_builder_section_locks','page_builder_section_workflow','global_sections','global_section_translations','users','activity_log','ai_knowledge_sync_queue'];
        foreach($tables as$table)$db->exec("CREATE TABLE `$table` LIKE sensecms_site.`$table`");
        foreach(['languages'=>'1','pages'=>'id IN (2,9,17)','page_translations'=>'page_id IN (2,9,17)','content_blocks'=>'page_id IN (2,9,17)','content_block_translations'=>'block_id IN (SELECT id FROM sensecms_site.content_blocks WHERE page_id IN (2,9,17))','page_builder_states'=>'page_id IN (2,9,17)']as$table=>$where)$db->exec("INSERT INTO `$table` SELECT * FROM sensecms_site.`$table` WHERE $where");
    }
    $cms=new App\Core\CmsRepository($db,new App\Core\EventBus());
    $catalog=App\Core\PageBuilder::catalog(json_decode((string)file_get_contents($stage.'/.themes/sensecms/theme.json'),true));
    $owner=$mode==='--qa'?1:(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
    if(!$owner)throw new RuntimeException('No active owner');
    $journal=$backup? $backup.'/content-applied.json':'';
    $applied=$mode==='--rollback'?json_decode((string)file_get_contents($journal),true,512,JSON_THROW_ON_ERROR):[];
    $prepared=[];
    foreach($changes as$path=>$edits){
        $before=$baseline['pages'][$path];$doc=$cms->builderDocument((int)$before['id']);
        if(!$doc||$doc['public_path']!==$path||$doc['status']!=='published')throw new RuntimeException('Page identity changed');
        if($mode==='--rollback'){
            if(!isset($applied[$path]))continue;
            if($doc!==$applied[$path])throw new RuntimeException('Newer editorial changes prevent rollback');
        }elseif($doc!==$before)throw new RuntimeException('Editorial baseline changed: '.$path);
        foreach(['page_builder_drafts','page_builder_section_locks']as$table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE page_id=?');$q->execute([$doc['id']]);if((int)$q->fetchColumn())throw new RuntimeException('Page has editor work');}
        $blocks=$doc['blocks'];$matched=0;
        foreach($blocks as&$block)if(isset($edits[(int)$block['id']])){
            if($block['global_section_id']||$block['type']!=='text')throw new RuntimeException('Expected a local text section');
            foreach($edits[(int)$block['id']]as$locale=>$values){
                if(!isset($block['localized'][$locale]))throw new RuntimeException('Missing translation');
                if($mode==='--rollback'){$old=array_values(array_filter($before['blocks'],static fn($b)=>$b['id']===$block['id']))[0];foreach(array_keys($values)as$key)$values[$key]=$old['localized'][$locale][$key];}
                $block['localized'][$locale]=array_replace($block['localized'][$locale],$values);
            }
            $matched++;
        }
        unset($block);
        if($matched!==count($edits))throw new RuntimeException('Missing target section');
        $sanitized=App\Core\PageBuilder::sanitizeBlocks($blocks,$catalog,array_keys($doc['translations']));
        // An editorial text update must retain each existing section's provenance.
        foreach($sanitized as$index=>&$block)$block['source']=$blocks[$index]['source_theme'];
        unset($block);
        $prepared[$path]=[$doc,$sanitized];
    }
    if($mode==='--check'){echo "Three pages, five local sections, EN/PL and editor guards verified.\n";exit;}
    if($mode==='--apply'&&is_file($journal))throw new RuntimeException('Publication already attempted');
    foreach($prepared as$path=>[$doc,$blocks]){
        $cms->saveBuilderDocument((int)$doc['id'],(int)$doc['builder_version'],$owner,'sensecms',$blocks,array_keys($catalog));
        $current=$cms->builderDocument((int)$doc['id']);$applied[$path]=$current;
        if($mode==='--apply')file_put_contents($journal,json_encode($applied,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        (new App\Core\AiKnowledgeBase($db,$root))->queueWebsiteSource('page',(int)$doc['id']);
        foreach($current['blocks']as$index=>$block){if($block['localized']!==$blocks[$index]['localized']||$block['source_theme']!==$blocks[$index]['source'])throw new RuntimeException('Content readback differs');}
        echo 'Verified '.$path.' revision '.$current['builder_version']."\n";
    }
    if($mode==='--qa')file_put_contents($stage.'/content-accepted.json',json_encode($applied,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
} finally {
    if($schema!==null&&preg_match('/^sensefront_[a-f0-9]{12}$/D',$schema))$db->exec("DROP DATABASE `$schema`");
}
