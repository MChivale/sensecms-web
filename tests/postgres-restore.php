<?php
declare(strict_types=1);

use App\Core\CmsRepository;
use App\Core\EventBus;
use App\Core\Runtime;

// Run only against a disposable PostgreSQL clone of a restored installation.
if (PHP_SAPI !== 'cli' || $argc !== 3 || !preg_match('/^sensecms_restore_qa_[a-f0-9]{12}$/D', $argv[2])) exit(2);
require $argv[1] . '/bootstrap.php';
$runtime = new Runtime($argv[1]);
$config = $runtime->read('installed')['database'];
$config['name'] = $argv[2];
$db = Runtime::connect($config);
if ($db->query('SELECT current_database()')->fetchColumn() !== $argv[2]) throw new RuntimeException('Wrong test database.');
$cms = new CmsRepository($db, new EventBus(), $runtime);
$page = (int)$db->query('SELECT id FROM pages ORDER BY id LIMIT 1')->fetchColumn();
$editor = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$document = $cms->builderDocument($page);
$version = (int)$document['builder_version'];
$sectionUid = bin2hex(random_bytes(16));
$sections = [['uid'=>$sectionUid,'type'=>'text','source'=>'core','shared'=>[],'layout'=>[],
    'appearance'=>[],'visible'=>true,'visible_from'=>null,'visible_until'=>null,
    'localized'=>['en'=>['title'=>'PostgreSQL restore test']]]];
$cms->saveBuilderDraft($page, $editor, $version, $sections);
$cms->saveBuilderDraft($page, $editor, $version, $sections);
if (!$cms->builderDraft($page, $editor)) throw new RuntimeException('Missing saved draft.');
$next = $cms->saveBuilderDocument($page, $version, $editor, 'core', $sections, []);
if ($next !== $version + 1 || $cms->builderDraft($page, $editor)) throw new RuntimeException('Failed to publish.');
$saved = $db->prepare('SELECT t.data FROM content_block_translations t JOIN content_blocks b ON b.id=t.block_id WHERE b.uid=? AND t.locale=?');
$saved->execute([$sectionUid,'en']);
if (json_decode((string)$saved->fetchColumn(),true)['title'] !== 'PostgreSQL restore test') throw new RuntimeException('New section or its generated ID was lost.');
try {
    $cms->saveBuilderDocument($page, $version, $editor, 'core', $sections, []);
    throw new RuntimeException('Accepted a stale publication.');
} catch (DomainException) {}
$cms->saveSetting('postgres_restore_test', ['revision'=>1]);
$cms->saveSetting('postgres_restore_test', ['revision'=>2]);
if ($cms->setting('postgres_restore_test') !== ['revision'=>2]) throw new RuntimeException('Setting did not update.');
$db->exec('CREATE TEMP TABLE qa_vector (label text, embedding vector(3))');
$db->exec("INSERT INTO qa_vector VALUES ('near','[1,0,0]'),('far','[0,1,0]')");
$nearest = $db->prepare('SELECT label FROM qa_vector ORDER BY embedding <=> CAST(? AS vector) LIMIT 1');
$nearest->execute(['[0.95,0.05,0]']);
if ($nearest->fetchColumn() !== 'near') throw new RuntimeException('Vector lookup failed.');
echo "Draft save/update, publication, stale editor protection, settings and vector retrieval passed.\n";
