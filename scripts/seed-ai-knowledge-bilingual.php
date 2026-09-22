<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3 || $argv[2] !== '--apply') exit("Usage: php seed-ai-knowledge-bilingual.php <installation> --apply\n");

$root=realpath($argv[1]);
if(!$root||!is_file($root.'/bootstrap.php'))throw new RuntimeException('A licensed Sense CMS installation is required.');
require $root.'/bootstrap.php';

$runtime=new App\Core\Runtime($root);$runtime->license()->enforce($runtime->baseUrl());$db=App\Core\Runtime::connect($runtime->read('installed')['database']);
$owner=(int)$db->query("SELECT ur.user_id FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id INNER JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
if(!$owner)throw new RuntimeException('An active Sense CMS owner is required.');
$knowledge=new App\Core\AiKnowledgeBase($db,$root);
$sources=[
    'en'=>['Sense CMS product and support overview', <<<'TEXT'
Sense CMS is a general-purpose content management system. Core owns content, media, permissions, editorial workflow, AI controls and Knowledge Base behaviour. Themes control public presentation. Extensions add independently licensed capabilities without replacing Core.

Create and maintain Pages, Posts, media, navigation, categories and SEO from the Workspace. Editorial suggestions never publish a page or post automatically: an editor reviews content, changes it when needed and chooses publication intentionally.

The Page Builder stores structured, reusable sections. A page can include independent content for every enabled language. Posts support a featured image, optional audio or video, SEO title, description and keywords. Published public content is eligible for the Knowledge Base unless an editor explicitly excludes it.

Sense CMS AI is provider-neutral. An administrator can configure an approved provider, model, priorities and request, token and budget limits. Page Builder and Posts use review-first assistance. AI suggestions remain editable and human approval remains required before publication.

The Knowledge Base is a local RAG index. It can contain approved manual text, private TXT, PDF, RTF, DOC and DOCX files, and selected published pages and posts. Sources can be corrected, disabled, reindexed or deleted. Draft, private, future and excluded website content is not used for retrieval.

The visitor assistant answers only from approved, indexed knowledge when sufficient context exists. It can show verified source links and hand a conversation to a human team. If a verified answer or an available person is not present, the conversation can request a callback email instead of inventing information.

Social Publishing keeps each network in a separate extension. An editor explicitly selects every destination for a post. Availability depends on the installed extension, a valid extension licence where required, and a connected account with the provider permissions needed for that action. Never share account passwords, API keys, licence keys or private documents in a chat.

Use the administration panels to manage users, roles, facilities, extensions, notifications and AI settings. For an operational problem, record the page, time and visible error message; do not send secrets. Sense CMS support can then investigate the relevant audit trail safely.
TEXT],
    'pl'=>['Sense CMS — informacje o produkcie i wsparciu', <<<'TEXT'
Sense CMS to uniwersalny system zarządzania treścią. Core odpowiada za treść, media, uprawnienia, obieg redakcyjny, ustawienia AI i działanie Bazy Wiedzy. Motywy sterują prezentacją publiczną. Dodatki wprowadzają niezależnie licencjonowane funkcje, nie zastępując Core.

W obszarze Workspace można tworzyć i utrzymywać Strony, Posty, media, nawigację, kategorie oraz SEO. Sugestie redakcyjne nigdy nie publikują strony ani postu automatycznie: redaktor sprawdza treść, poprawia ją w razie potrzeby i świadomie wybiera publikację.

Page Builder zapisuje uporządkowane, wielokrotnego użycia sekcje. Strona może mieć niezależną treść dla każdego aktywnego języka. Posty obsługują obraz główny, opcjonalne audio lub wideo, tytuł SEO, opis i słowa kluczowe. Opublikowana treść publiczna trafia do Bazy Wiedzy, chyba że redaktor wyraźnie ją wykluczy.

AI w Sense CMS jest niezależne od dostawcy. Administrator może skonfigurować zatwierdzonego dostawcę, model, priorytety oraz limity żądań, tokenów i budżetu. Page Builder i Posty działają w trybie review-first. Sugestie AI pozostają edytowalne, a zgoda człowieka jest nadal wymagana przed publikacją.

Baza Wiedzy jest lokalnym indeksem RAG. Może zawierać zatwierdzone teksty ręczne, prywatne pliki TXT, PDF, RTF, DOC i DOCX oraz wybrane opublikowane strony i posty. Źródła można poprawiać, wyłączać, ponownie indeksować lub usuwać. Szkice, treści prywatne, przyszłe oraz wykluczone ze strony nie są używane przy wyszukiwaniu odpowiedzi.

Asystent dla odwiedzających odpowiada wyłącznie na podstawie zatwierdzonej i zindeksowanej wiedzy, gdy ma wystarczający kontekst. Może pokazać zweryfikowane linki do źródeł i przekazać rozmowę zespołowi. Gdy nie ma potwierdzonej odpowiedzi ani dostępnej osoby, rozmowa może poprosić o adres e-mail do kontaktu zwrotnego zamiast wymyślać informacje.

Social Publishing utrzymuje każdą sieć w osobnym dodatku. Redaktor jawnie wybiera każde miejsce publikacji dla postu. Dostępność zależy od zainstalowanego dodatku, ważnej licencji dodatku, gdy jest wymagana, oraz połączonego konta z uprawnieniami wymaganymi przez dostawcę. Nie należy podawać w czacie haseł do kont, kluczy API, kluczy licencyjnych ani prywatnych dokumentów.

Panele administracyjne służą do zarządzania użytkownikami, rolami, placówkami, dodatkami, powiadomieniami i ustawieniami AI. Przy problemie operacyjnym należy zapisać stronę, czas i widoczny komunikat błędu; nie należy przesyłać sekretów. Dzięki temu wsparcie Sense CMS może bezpiecznie sprawdzić właściwy ślad audytowy.
TEXT],
];

$find=$db->prepare('SELECT id FROM ai_knowledge_documents WHERE source_type="manual" AND title=? AND locale=? ORDER BY id DESC LIMIT 1');$saved=[];
foreach($sources as$locale=>[$title,$body]){$find->execute([$title,$locale]);$id=(int)$find->fetchColumn();$saved[]=$knowledge->saveManual($id,$title,$body,$locale,true,$owner);}
echo json_encode(['saved'=>$saved,'locales'=>array_keys($sources)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
