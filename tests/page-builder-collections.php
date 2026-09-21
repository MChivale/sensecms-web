<?php
declare(strict_types=1);
require dirname(__DIR__) . '/.cms/source/bootstrap.php';

use App\Core\CmsRepository;
use App\Core\EventBus;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP pdo_sqlite is unavailable; collection SQL requires an isolated database.\n";
    exit(0);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->sqliteCreateFunction('NOW', static fn(): string => '2026-09-21 12:00:00');
$db->sqliteCreateFunction('CONCAT', static fn(mixed ...$parts): string => implode('', array_map('strval', $parts)));
foreach ([
    'CREATE TABLE categories (id INTEGER PRIMARY KEY,slug TEXT,archived_at TEXT)',
    'CREATE TABLE category_translations (category_id INTEGER,locale TEXT,name TEXT)',
    'CREATE TABLE media (id INTEGER PRIMARY KEY,path TEXT,alt_text TEXT,status TEXT)',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY,facility_id INTEGER,category_id INTEGER,featured_media_id INTEGER,status TEXT,published_at TEXT,updated_at TEXT)',
    'CREATE TABLE post_translations (post_id INTEGER,locale TEXT,title TEXT,slug TEXT,excerpt TEXT)',
    'CREATE TABLE pages (id INTEGER PRIMARY KEY,facility_id INTEGER,visibility TEXT,status TEXT,published_at TEXT,updated_at TEXT,public_path TEXT)',
    'CREATE TABLE page_translations (page_id INTEGER,locale TEXT,title TEXT,slug TEXT,excerpt TEXT)',
] as $sql) $db->exec($sql);

$db->exec("INSERT INTO categories VALUES (10,'updates',NULL),(20,'resources',NULL),(30,'archived','2026-01-01')");
$db->exec("INSERT INTO category_translations VALUES (10,'en','Updates'),(20,'en','Resources'),(30,'en','Archived')");
$db->exec("INSERT INTO media VALUES (90,'/media/a.webp','A','active'),(91,'/media/hidden.webp','Hidden','trashed')");
$db->exec("INSERT INTO posts VALUES (1,1,10,90,'published','2026-09-01 10:00:00','2026-09-01 10:00:00'),(2,1,10,NULL,'scheduled','2026-09-20 10:00:00','2026-09-20 10:00:00'),(3,1,10,91,'draft',NULL,'2026-09-21 10:00:00'),(4,1,20,NULL,'published','2026-08-01 10:00:00','2026-08-01 10:00:00'),(5,2,10,NULL,'published','2026-09-21 10:00:00','2026-09-21 10:00:00')");
$db->exec("INSERT INTO post_translations VALUES (1,'en','Alpha','alpha','Alpha excerpt'),(2,'en','Beta','beta','Beta excerpt'),(3,'en','Draft','draft','Draft excerpt'),(4,'en','Resource','resource','Resource excerpt'),(5,'en','Other facility','other','Other excerpt')");
$db->exec("INSERT INTO pages VALUES (11,1,'public','published','2026-09-02 10:00:00','2026-09-02 10:00:00','/platform'),(12,1,'private','published','2026-09-03 10:00:00','2026-09-03 10:00:00',NULL),(13,1,'public','published','2026-09-04 10:00:00','2026-09-04 10:00:00',NULL),(14,1,'public','draft',NULL,'2026-09-05 10:00:00',NULL)");
$db->exec("INSERT INTO page_translations VALUES (11,'en','Platform','platform','Platform excerpt'),(12,'en','Private','private','Private excerpt'),(13,'en','Current','current','Current excerpt'),(14,'en','Draft page','draft-page','Draft excerpt')");

$cms = new CmsRepository($db, new EventBus());
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void { if (!$ok) throw new RuntimeException($label); $count++; echo "PASS {$label}\n"; };
$facility = ['id'=>1,'is_primary'=>1,'homepage_page_id'=>13,'city_slug'=>'london','facility_slug'=>'hq'];

$options = $cms->builderCollectionOptions(1, 'en', 'en', 13);
$check(array_column($options['sources']['posts']['items'], 'value') === [2,1,4], 'Picker includes only live facility posts in newest order');
$check(array_column($options['sources']['pages']['items'], 'value') === [11], 'Picker includes only public live facility pages and excludes the current document');
$check(array_column($options['categories'], 'value') === [20,10], 'Picker omits archived categories and reports localized options');

$block = static fn(array $shared): array => ['type'=>'news','payload'=>['title'=>'Collection'],'shared'=>$shared];
$automatic = $cms->hydratePageCollections([$block(['source'=>'posts','selection'=>'automatic','category_id'=>'10','order'=>'title_desc','limit'=>2])], 'en', 'en', $facility, 13);
$check(array_column($automatic[0]['collection']['items'], 'id') === [2,1], 'Automatic post collection applies category, order and limit');
$check($automatic[0]['collection']['items'][1]['image'] === '/media/a.webp' && $automatic[0]['collection']['items'][0]['url'] === '/en/posts/beta', 'Post collection exposes active media and scoped public URLs');

$selected = $cms->hydratePageCollections([$block(['source'=>'posts','selection'=>'selected','selected_ids'=>[4,1],'limit'=>12])], 'en', 'en', $facility, 13);
$check(array_column($selected[0]['collection']['items'], 'id') === [4,1], 'Selected content retains the editors explicit order');

$pages = $cms->hydratePageCollections([$block(['source'=>'pages','selection'=>'automatic','order'=>'newest','limit'=>12])], 'en', 'en', $facility, 13);
$check(array_column($pages[0]['collection']['items'], 'id') === [11] && $pages[0]['collection']['items'][0]['url'] === '/platform', 'Page collection excludes the current page and respects its public path');

echo "{$count} dynamic collection database checks passed; only an isolated in-memory database was used.\n";
