<?php
declare(strict_types=1);

// Operator integration check. Uses the configured embedding API only on an explicitly named test clone.
use App\Core\{AiChatService,AiProviderClient,AiRepository,AiSemanticSearch,CmsRepository,EventBus,Runtime,Secrets};

if (PHP_SAPI !== 'cli' || $argc !== 3 || !preg_match('/^sensecms_semantic_qa_[0-9a-f]{12}$/D', $argv[2])) exit(2);
require $argv[1].'/bootstrap.php';
$runtime = new Runtime($argv[1]);
$config = $runtime->read('installed')['database'];
$config['name'] = $argv[2];
$db = Runtime::connect($config);
if ($db->query('SELECT current_database()')->fetchColumn() !== $argv[2]) throw new RuntimeException('Unexpected database.');
$secrets = new Secrets($runtime->read('workspace')['secret']);
$semantic = AiSemanticSearch::configured($runtime,$db,$secrets) ?? throw new RuntimeException('Not enabled.');
$repo = new AiRepository($db);
$chat = new AiChatService($repo,$secrets,new CmsRepository($db,new EventBus(),$runtime),new AiProviderClient(),$semantic);
foreach ([
    ['pl','Mam pusty serwer i domenę. Jak zacząć, żeby uruchomić na nim Sense CMS?', '/pl/docs-installation'],
    ['en','I have a fresh server and a domain. Where do I begin setting up the CMS?', '/docs/installation'],
] as [$locale,$question,$expected]) {
    $matches = $semantic->matches($question,$locale);
    $urls = array_column($matches,'url');
    echo $locale,' semantic sources: ',implode(', ',array_unique($urls)),PHP_EOL;
    if (!in_array($expected,$urls,true)) throw new RuntimeException('Expected installation guide was absent.');
    $answer = $chat->reply($question,$locale,bin2hex(random_bytes(16)));
    if ($answer['handoff'] || !str_contains((string)$answer['message'],$expected)) throw new RuntimeException('Chat did not cite the installation guide.');
    echo $locale," chatbot cited the expected guide.\n";
}
$usage = fn() => (int)$db->query("SELECT COALESCE(SUM(requests),0) FROM ai_semantic_usage WHERE purpose='index'")->fetchColumn();
$before = $usage();
$db->exec("UPDATE ai_knowledge_chunks SET embedding=NULL,embedding_model=NULL WHERE id=(SELECT MIN(id) FROM ai_knowledge_chunks)");
if ($semantic->indexBatch() !== 0 || $usage() !== $before) throw new RuntimeException('Cached content triggered another API call.');
if ((int)$db->query('SELECT COUNT(*) FROM ai_knowledge_chunks WHERE embedding IS NULL')->fetchColumn() !== 0) throw new RuntimeException('Cached vector was not restored.');
$db->exec("UPDATE ai_knowledge_documents SET status='archived' WHERE source_url='/pl/docs-installation'");
if (in_array('/pl/docs-installation',array_column($semantic->matches('instalacja systemu','pl'),'url'),true)) throw new RuntimeException('Archived document was returned.');
echo "Cache reuse and removal from search after archival passed.\n";
$db->exec("UPDATE ai_semantic_usage SET requests=300 WHERE purpose='query' AND day=CURRENT_DATE");
$fallback = $chat->reply('How do I install Sense CMS?', 'en', bin2hex(random_bytes(16)));
if ($fallback['handoff'] || !str_contains((string)$fallback['message'],'/docs/installation')) throw new RuntimeException('Keyword fallback failed.');
echo "Chat retained its source when semantic lookup hit its limit.\n";
