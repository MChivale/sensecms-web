<?php
declare(strict_types=1);

// Run on an isolated www snapshot with its bilingual installation FAQ scenarios.
use App\Core\{AiChatService, AiKnowledgeBase, AiProviderClient, AiRepository, CmsRepository, EventBus, PostgresConnection, Secrets};

if (PHP_SAPI !== 'cli' || $argc !== 3 || !preg_match('/^sensecms_citation_qa_[0-9a-f]{8,16}$/D', $argv[2])) exit(2);
require $argv[1].'/bootstrap.php';
// Capture what would be sent to a model. Chat routing, retrieval, history and citations use real Core.
final class OfflineCitationProvider
{
    public string $prompt = '';

    public function generate(array $provider, string $key, string $system): array
    {
        $this->prompt = $system;
        return ['text'=>'QA response', 'usage'=>['input'=>0, 'output'=>0]];
    }
}
class_alias(OfflineCitationProvider::class, AiProviderClient::class);
$db = new PostgresConnection('pgsql:host=/var/run/postgresql;dbname='.$argv[2], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ($db->query('SELECT current_database()')->fetchColumn() !== $argv[2]) exit(2);
$repository = new AiRepository($db);
$secrets = new Secrets(bin2hex(random_bytes(32)));
$provider = new OfflineCitationProvider();
$chat = new AiChatService($repository, $secrets, new CmsRepository($db, new EventBus()), $provider);
$urls = ['pl'=>'/pl/docs-installation', 'en'=>'/docs/installation'];
$questions = ['pl'=>'Jak zainstalować Sense CMS?', 'en'=>'How do I install Sense CMS?'];
$conversations = ['pl'=>bin2hex(random_bytes(16)), 'en'=>bin2hex(random_bytes(16))];
$errors = [];
$check = function (string $phase, bool $withdrawn) use ($chat, $repository, $provider, $urls, $questions, $conversations, &$errors): void {
    foreach ($urls as $locale => $url) {
        $expected = $locale !== 'pl' || !$withdrawn;
        $answer = $chat->reply($questions[$locale], $locale, $conversations[$locale])['message'];
        $query = $locale === 'pl' ? 'instalacji' : 'installation';
        $matches = $repository->contextMatches($query, $locale);
        $current = $repository->scenarios()->currentKnowledge($matches, $locale);
        $previous = 'Previous answer about the installation guide';
        $repository->message($conversations[$locale], 'assistant', $previous."\n\nSource: ".$url, 'qa-provider');
        $provider->prompt = '';
        $generated = $chat->reply($query, $locale, $conversations[$locale])['message'];
        if ($provider->prompt === '' && $current) throw new RuntimeException('Expected model input for the retrieval query.');
        $results = [
            'reply' => in_array('Source: '.$url, explode("\n", (string)$answer), true),
            'retrieval' => in_array($url, array_column($current, 'url'), true),
            'generated reply' => in_array('Source: '.$url, explode("\n", (string)$generated), true),
            'previous cited answer in model input' => str_contains($provider->prompt, $previous),
        ];
        foreach ($results as $path => $found) {
            echo "$phase $locale $path ", $found ? $url : '(no installation citation)', PHP_EOL;
            if ($found !== $expected) $errors[] = "$phase $locale $path";
        }
    }
};
$db->exec('CREATE TEMP TABLE ai_providers AS SELECT * FROM public.ai_providers WHERE FALSE');
$db->prepare("INSERT INTO ai_providers (id,slug,name,driver,default_model,enabled,priority,api_key_encrypted)
    SELECT id,'qa-provider','QA','openai-compatible','qa',1,1,? FROM public.ai_providers ORDER BY id LIMIT 1")
    ->execute([$secrets->encrypt('disposable-qa')]);
$save = $db->prepare("CREATE TEMP TABLE withdrawn_translation AS SELECT * FROM page_translations
    WHERE locale='pl' AND page_id=(SELECT source_id FROM ai_knowledge_documents WHERE source_type='page' AND source_url=?)");
$save->execute([$urls['pl']]);
$pageId = (int)$db->query('SELECT page_id FROM withdrawn_translation')->fetchColumn();
$knowledge = new AiKnowledgeBase($db, $argv[1]);
$sync = function () use ($knowledge, $pageId): void {
    $knowledge->queueWebsiteSource('page', $pageId);
    $result = $knowledge->processQueue(100);
    if ($result['locked'] || $result['failed'] || !$result['processed']) throw new RuntimeException('Index sync failed.');
};
$check('published', false);
$deleted = $db->exec('DELETE FROM page_translations WHERE id IN (SELECT id FROM withdrawn_translation)');
try {
    if ($deleted !== 1) throw new RuntimeException('Expected one Polish translation on the clone.');
    $check('withdrawn', true);
    $sync();
    $status = $db->prepare("SELECT status FROM ai_knowledge_documents WHERE source_type='page' AND source_url=?");
    $status->execute([$urls['pl']]);
    if ($status->fetchColumn() !== 'archived') throw new RuntimeException('Expected an archived Polish index entry.');
    $check('synced', true);
} finally {
    $db->exec('INSERT INTO page_translations SELECT * FROM withdrawn_translation');
    $sync();
}
$check('restored', false);
if ($errors) throw new RuntimeException('Incorrect citations: '.implode(', ', $errors));
