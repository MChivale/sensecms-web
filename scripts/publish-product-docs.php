<?php
declare(strict_types=1);

// Reviewed public-site editorial release, 2026-10-31. Never a general importer.
$pl=static fn(string$title,string$text,array$extra=[]):array=>['pl'=>['title'=>$title,'text'=>$text]+$extra];
$copy=[
 'blocks'=>[
  1=>$pl('Jeden panel do zarządzania stroną','<p>Zarządzaj stronami, wpisami, mediami, menu i SEO w jednym panelu. Zmiana wyglądu witryny nie wymaga wymiany narzędzi, z których korzysta Twój zespół.</p>'),
  2=>$pl('Wiele placówek. Jeden system.','<p>Każda placówka może mieć własne treści i zespół. Przydzielaj dostęp według obowiązków, a wspólne ustawienia organizacji utrzymuj w jednym miejscu.</p>'),
  3=>$pl('Wygląd dopasowany do Ciebie','<p>Wybierz zgodny motyw lub przygotuj własny projekt. Możesz zmieniać wygląd witryny, zachowując jej treści, tłumaczenia i adresy stron.</p>',['cta_label'=>'Poznaj tworzenie motywów →']),
  4=>$pl('Dobre przygotowanie ułatwia start','<p>Instalator prowadzi przez weryfikację licencji, sprawdzenie serwera, konfigurację bazy danych i utworzenie konta właściciela. Dokumentacja wyjaśnia, jak przygotować środowisko i bezpiecznie przechowywać prywatne pliki.</p>',['cta_label'=>'Sprawdź przebieg instalacji →']),
  449=>$pl('AI wspiera. Redaktor decyduje.','<p>Przygotowuj i poprawiaj sekcje stron w Page Builderze oraz treści wpisów. Wybierz dostawcę AI i udostępnij asystentowi zatwierdzoną Bazę Wiedzy. Przed publikacją sprawdzasz propozycje i decydujesz, co trafi na stronę.</p>'),
  5=>$pl('Cała organizacja w jednym systemie','<p>Sense CMS pomaga prowadzić strony internetowe i pracę zespołów w jednej lub wielu placówkach. Panel administracyjny działa niezależnie od motywu, który określa wygląd witryny.</p>'),
  6=>$pl('Treści i publikacja','<p>Twórz strony i wpisy w wielu językach, porządkuj kategorie i układaj sekcje w Page Builderze. Zarządzaj mediami, menu i SEO. Wersje robocze oraz etap zatwierdzania ułatwiają przygotowanie treści do publikacji.</p>'),
  7=>$pl('Zespoły i placówki','<p>Przydzielaj role oraz dostęp do konkretnych placówek. Zespół każdej placówki pracuje nad swoimi treściami, a uprawnione osoby mogą zarządzać całą organizacją.</p>'),
  8=>$pl('Zmieniaj wygląd, zachowaj treści','<p>Po zmianie motywu strony, tłumaczenia i adresy pozostają w systemie. Zgodny motyw określa wygląd witryny, dostępne szablony i ustawienia, a panel administracyjny zachowuje swoją niezależność.</p>',['cta_label'=>'Poznaj zasady tworzenia motywów →']),
  450=>$pl('AI z wybranym dostawcą','<p>Korzystaj z pomocy AI przy redagowaniu treści i odpowiadaniu na pytania odwiedzających. Zarządzaj źródłami wiedzy oraz limitami użycia, a decyzje o publikacji pozostaw zespołowi.</p>',['cta_label'=>'Poznaj funkcje AI →']),
  451=>$pl('Pomoc w codziennej pracy redakcyjnej','<p>Twórz i poprawiaj wersje robocze sekcji stron w Page Builderze oraz treści wpisów. Propozycje AI możesz edytować i porównywać. Ich wygenerowanie nie publikuje treści — ostateczna decyzja należy do redaktora.</p>'),
  452=>$pl('Ty wybierasz dostawcę AI','<p>Podłącz OpenAI, Anthropic, Google Gemini lub zgodny interfejs API. Dla dostawców możesz ustawić priorytety i limity użycia. Klucze dostępu są przechowywane w zaszyfrowanej postaci w Twojej instalacji.</p>'),
  453=>$pl('Baza Wiedzy oparta na Twoich źródłach','<p>Połącz zatwierdzone treści witryny, ręcznie dodane informacje i dokumenty tekstowe. System wyszukuje w nich kontekst potrzebny do odpowiedzi. Każde źródło możesz sprawdzić, poprawić lub wyłączyć.</p>'),
  454=>$pl('Wiedza aktualizowana razem ze stroną','<p>Po zmianie lub wycofaniu opublikowanej strony albo wpisu system zleca odświeżenie indeksu wiedzy. Treści robocze, prywatne, zaplanowane na przyszłość i wyłączone z indeksowania nie są udostępniane asystentowi odwiedzających.</p>'),
  455=>$pl('Przykłady do oceny jakości AI','<p>Przygotuj sprawdzone pytania i odpowiedzi, aby oceniać jakość działania AI. System pozwala lokalnie utworzyć zestaw danych do dalszej pracy. Płatne dostrajanie modelu u dostawcy pozostaje wyłączone i wymaga osobnej decyzji.</p>'),
  456=>$pl('Asystent odwiedzających i kontakt z zespołem','<p>Asystent odpowiada na podstawie zatwierdzonej wiedzy i może wskazać źródła. Gdy informacji brakuje, rozmowę można przekazać zespołowi. Limity odpowiedzi pomagają kontrolować wykorzystanie usługi.</p>'),
  15=>[
   'en'=>['text'=>'<p>Start with the server requirements, a licensed domain and a new, empty database. Follow the setup sequence from licence verification to your first owner login.</p><p>The guide also explains how to resume an interrupted installation and which private files to preserve. Use the development installer in an isolated environment; check release availability before planning deployment.</p>'],
   'pl'=>['text'=>'<p>Zacznij od wymagań serwera, domeny z licencją i nowej, pustej bazy danych. Przejdź przez konfigurację od weryfikacji licencji do pierwszego logowania właściciela.</p><p>Poradnik wyjaśnia także, jak wznowić przerwaną instalację i które prywatne pliki zachować. Instalator rozwojowy sprawdzaj w osobnym środowisku; przed wdrożeniem zapoznaj się ze statusem wydania.</p>','cta_label'=>'Przejdź do instrukcji instalacji →'],
  ],
  16=>[
   'en'=>['text'=>'<p>Understand how the system licence is tied to your installation domain and how verification works. Learn which extensions need a separate product licence and what to prepare before changing a domain or key.</p>'],
   'pl'=>['text'=>'<p>Sprawdź, jak licencja systemowa jest powiązana z domeną i jak przebiega jej weryfikacja. Dowiedz się, które rozszerzenia wymagają osobnej licencji oraz co przygotować przed zmianą domeny lub klucza.</p>','cta_label'=>'Poznaj zasady licencjonowania →'],
  ],
  17=>[
   'en'=>['text'=>'<p>Compare themes, modules, plugins and add-ons. Review package identity, compatible versions, dependencies and signature verification before installing code from a trusted publisher.</p>'],
   'pl'=>['title'=>'Specyfikacja pakietów','text'=>'<p>Poznaj różnice między motywami, modułami, wtyczkami i dodatkami. Przed instalacją sprawdź tożsamość pakietu, zgodne wersje, zależności i podpis zaufanego wydawcy.</p>','cta_label'=>'Przeczytaj specyfikację pakietów →'],
  ],
  18=>[
   'en'=>['text'=>'<p>Create a public website theme while keeping content and administration in Core. Follow the rendering contract for page templates, editable sections, assets and multilingual links.</p>'],
   'pl'=>['text'=>'<p>Przygotuj wygląd witryny, zachowując treści i administrację w Core. Poznaj zasady obsługi szablonów stron, edytowalnych sekcji, plików motywu i odnośników w różnych językach.</p>','cta_label'=>'Poznaj zasady tworzenia motywów →'],
  ],
  19=>[
   'en'=>['text'=>'<p>Configure HTTPS, PHP and the public document root for Nginx or Apache. Keep private application files outside the web root and verify routing, access restrictions and recovery before deployment.</p>'],
   'pl'=>['text'=>'<p>Skonfiguruj HTTPS, PHP i katalog publiczny w Nginx lub Apache. Oddziel prywatne pliki aplikacji od zasobów WWW. Przed wdrożeniem sprawdź routing, ograniczenia dostępu i odtwarzanie kopii zapasowych.</p>','cta_label'=>'Sprawdź konfigurację serwera →'],
  ],
  22=>$pl('Wskaż katalog publiczny witryny','<p>Rozpakuj aplikację poza katalogiem udostępnianym przez WWW. Jako DocumentRoot ustaw jej katalog public. Kod aplikacji, dane logowania i prywatny katalog storage nie mogą być dostępne z przeglądarki. Przed otwarciem instalatora skonfiguruj HTTPS oraz docelową domenę.</p>'),
  25=>$pl('2. Sprawdź środowisko','<p>Przejrzyj wyniki kontroli serwera. Przed kolejnym krokiem uzupełnij brakujące rozszerzenia PHP i popraw ustawienia pamięci lub uprawnienia do zapisu.</p>'),
  26=>$pl('3. Skonfiguruj instalację','<p>Podaj dane nowej bazy danych, nazwę witryny i dane konta właściciela. Ustaw unikalne hasło o długości 14–200 znaków. Po zakończeniu instalator zamknie dostęp do konfiguracji i przekieruje Cię do /login.</p>'),
  28=>$pl('Zacznij od ważnej licencji','<p>Instalator sprawdza klucz dla docelowej domeny i produktu Sense CMS / Sense CMS System / 1.0. Numer wydania oprogramowania jest niezależny od wersji zapisanej w tożsamości licencyjnej produktu.</p>'),
  33=>$pl('Cztery typy pakietów','<p>Moduły dodają funkcje aplikacji. Wtyczki łączą system z usługami i zdarzeniami. Dodatki rozszerzają narzędzia administracyjne, a motywy określają wygląd witryny. Panel administracyjny jest częścią Core i działa niezależnie od motywu.</p>'),
  34=>$pl('Zgodność i zależności','<p>Plik sense-package.json określa typ, identyfikator, wersję i wydawcę pakietu, obsługiwane wersje PHP oraz Core i wymagane zależności. Dolna granica zakresu wersji jest uwzględniana, a górna — wykluczona.</p>'),
  35=>$pl('Podpis i integralność plików','<p>Archiwum zawiera manifest, podpis Ed25519 i pliki z sumami kontrolnymi SHA-256. Klucz wydawcy musi pochodzić z niezależnego, zaufanego źródła. Podpis potwierdza pochodzenie i integralność; nie izoluje działania kodu PHP.</p>'),
  36=>$pl('Dozwolone pliki i limity','<p>Weryfikacja odrzuca ścieżki wychodzące poza katalog pakietu, dowiązania symboliczne, prywatne pliki, pliki spoza manifestu oraz nazwy różniące się wyłącznie wielkością liter. Limit archiwum wynosi 25 MiB, a po rozpakowaniu: 100 MiB i 2000 plików.</p>'),
  37=>[
   'en'=>['title'=>'Package availability and lifecycle','text'=>'<p>The public catalogue lists each product and its current availability. Supported package types use signed archives, compatibility and dependency checks, installation and controlled lifecycle operations. Selected development packages can be downloaded after the required licence is verified.</p><p>Availability is specific to each product; a catalogue entry does not mean a package is ready to download or approved as Stable. Review its detail page and prepare a backup before installation or upgrade.</p>'],
   'pl'=>['title'=>'Dostępność i obsługa pakietów','text'=>'<p>Publiczny katalog pokazuje produkty i ich aktualną dostępność. Obsługiwane typy pakietów korzystają z podpisanych archiwów, kontroli zgodności i zależności oraz operacji instalacji i zarządzania. Wybrane pakiety rozwojowe można pobrać po weryfikacji wymaganej licencji.</p><p>Każdy produkt ma własny status. Obecność w katalogu nie oznacza gotowości do pobrania ani statusu Stable. Przed instalacją lub aktualizacją sprawdź szczegóły produktu i przygotuj kopię zapasową.</p>'],
  ],
  38=>$pl('Udostępniaj wyłącznie katalog public','<p>Ustaw public jako DocumentRoot serwera WWW. Kod aplikacji, dane licencyjne, dane logowania do bazy i kopie zapasowe przechowuj poza nim. Proces PHP powinien móc zapisywać dane wyłącznie tam, gdzie wymaga tego aplikacja, przede wszystkim w prywatnym katalogu storage.</p>'),
  40=>[
   'en'=>['text'=>'<p>The distribution includes Apache rewrite rules. Enable mod_rewrite and the required AllowOverride permissions, and use public as the document root. The current development installer has passed isolated licensed installation tests on Apache over HTTPS. Validate your own server configuration before deployment.</p>'],
   'pl'=>['text'=>'<p>Dystrybucja zawiera reguły routingu dla Apache. Włącz mod_rewrite i wymagane uprawnienia AllowOverride, a jako DocumentRoot ustaw katalog public. Obecny instalator rozwojowy przeszedł izolowane testy instalacji z licencją pod Apache przez HTTPS. Przed wdrożeniem sprawdź konfigurację własnego serwera.</p>'],
  ],
  44=>$pl('Dostępne możliwości','<p>Instalacja rozpoczynająca się od licencji, konto właściciela, wielojęzyczne strony i wpisy, media, menu i SEO. System obsługuje dostęp według placówek oraz ustawienia i podgląd motywu. Podpisane motywy można instalować, aktywować i wycofywać do poprzedniej wersji.</p>'),
  55=>$pl('Dodatkowe narzędzia dla zespołu','<p>Dodatki rozszerzają panel administracyjny o narzędzia do codziennej pracy organizacji. Zakres i dostępność każdego dodatku opisujemy osobno w katalogu.</p>'),
  90=>[
   'en'=>['text'=>'<p>School Theme is intended for school and educational institution websites. Its focus is presenting the school, its educational offer, news and contact information in a coherent public website.</p><p>The Sense CMS package is still being adapted and verified. This description defines its intended use, not a promise of a released feature set. Downloading the free package, when available, requires a valid Sense CMS system licence.</p>'],
   'pl'=>['text'=>'<p>School Theme to motyw przeznaczony dla szkół i placówek edukacyjnych. Jego celem jest spójna prezentacja szkoły, oferty edukacyjnej, aktualności i danych kontaktowych.</p><p>Pakiet Sense CMS jest nadal dostosowywany i sprawdzany. Opis określa jego przeznaczenie, a nie zakres ukończonego wydania. Pobranie bezpłatnego pakietu po jego udostępnieniu będzie wymagało ważnej licencji systemowej Sense CMS.</p>'],
  ],
 ],
 'pages'=>[
  '/docs'=>['en'=>['excerpt'=>'Start with installation, then explore licensing, website development and day-to-day use. Choose the guide for your next task.'],'pl'=>['title'=>'Dokumentacja krok po kroku.','excerpt'=>'Zacznij od instalacji, poznaj zasady licencjonowania i przygotuj witrynę do pracy. Wybierz poradnik odpowiadający temu, co chcesz zrobić.']],
  '/docs/installation'=>['pl'=>['excerpt'=>'Przygotuj serwer, zweryfikuj licencję i skonfiguruj nową instalację Sense CMS.']],
  '/docs/packages'=>['pl'=>['title'=>'Specyfikacja pakietów rozszerzeń','seo_title'=>'Specyfikacja pakietów rozszerzeń']],
  '/docs/server'=>['pl'=>['excerpt'=>'Konfiguracja katalogu publicznego, HTTPS i prywatnych plików w Nginx oraz Apache.']],
  '/platform/ai'=>['pl'=>['title'=>'AI, która wspiera Twój zespół.','excerpt'=>'Pomoc w redagowaniu treści, uporządkowana Baza Wiedzy i asystent odwiedzających. Ty wybierasz źródła, dostawcę i zasady publikacji.']],
  '/extensions/catalog/theme/shoudu'=>[
   'en'=>['title'=>'School Theme','seo_title'=>'School Theme — a website theme for schools','excerpt'=>'A website theme for schools and educational institutions. The Sense CMS package is being adapted and verified; it is not yet available to download.','seo_description'=>'School Theme for school and educational institution websites. Review its intended use, licensing and current adaptation status.'],
   'pl'=>['title'=>'School Theme','seo_title'=>'School Theme — motyw stron internetowych dla szkół','excerpt'=>'Motyw stron internetowych dla szkół i placówek edukacyjnych. Pakiet Sense CMS jest dostosowywany i sprawdzany; nie jest jeszcze dostępny do pobrania.','seo_description'=>'School Theme: motyw dla szkół i placówek edukacyjnych. Poznaj jego przeznaczenie, zasady licencjonowania i aktualny status.'],
  ],
 ],
 'add'=>[
  ['uid'=>'e63cb435-e821-4a22-a2c7-3252c0883588','url'=>'/platform/ai','en'=>['title'=>'Content and AI','text'=>'<p>Explore editorial assistance, approved knowledge sources and the visitor assistant. Understand where human review fits and how provider settings and usage limits support your team.</p>','cta_label'=>'Explore content and AI →'],'pl'=>['title'=>'Treści i AI','text'=>'<p>Poznaj pomoc AI w redagowaniu, zatwierdzone źródła wiedzy i asystenta odwiedzających. Sprawdź rolę redaktora oraz znaczenie ustawień dostawcy i limitów użycia.</p>','cta_label'=>'Poznaj pracę z AI →']],
  ['uid'=>'26659ccb-d737-4b71-a751-8d5f6ba3b06d','url'=>'/update','en'=>['title'=>'Updates and release status','text'=>'<p>Review published Stable release notes when available and check the current development status. Plan updates with a backup and a recovery procedure; automatic Core installation is not available.</p>','cta_label'=>'Check updates →'],'pl'=>['title'=>'Aktualizacje i status wydania','text'=>'<p>Sprawdzaj informacje o opublikowanych wydaniach Stable oraz bieżący status rozwoju. Aktualizację planuj z kopią zapasową i procedurą odzyskiwania; automatyczna instalacja aktualizacji Core nie jest dostępna.</p>','cta_label'=>'Sprawdź aktualizacje →']],
 ],
 'globalDescriptions'=>[
  1=>['pl'=>'Uniwersalny system zarządzania treścią: placówki, publikacja, uprawnienia i instalator rozpoczynający konfigurację od weryfikacji licencji.'],
  3=>['en'=>'A website theme for schools and educational institutions. The Sense CMS package is being adapted and verified; it is not yet available to download.','pl'=>'Motyw stron internetowych dla szkół i placówek edukacyjnych. Pakiet Sense CMS jest dostosowywany i sprawdzany; nie jest jeszcze dostępny do pobrania.'],
  5=>['pl'=>'Planowanie wydarzeń dla placówek: własne kategorie, wydarzenia cykliczne, zasoby i wykrywanie konfliktów terminów.'],
  13=>['pl'=>'Aplikacja na komputer do pracy z panelem CMS online. Wydanie dla Windows przeznaczone dla Sense CMS nie jest jeszcze dostępne.'],
 ],
];
if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return$copy;
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',['--check','--qa','--apply','--rollback'],true))throw new RuntimeException('Choose --check, --qa, --apply or --rollback, private stage and backup.');
$mode=$argv[1];$stage=realpath($argv[2]??'');$backup=realpath($argv[3]??'');
if($stage!=='/root/sensecms-editorial-20261031'||(in_array($mode,['--apply','--rollback'],true)&&(!$backup||!str_starts_with($backup,'/root/sensecms-backups/'))))throw new RuntimeException('Unexpected recovery paths');
$root='/home/sensecms.com/web';require$root.'/bootstrap.php';$r=new App\Core\Runtime($root);
if($r->baseUrl()!=='https://www.sensecms.com')throw new RuntimeException('Unexpected installation');
$db=App\Core\Runtime::connect($r->read('installed')['database']);
if($db->query('SELECT DATABASE()')->fetchColumn()!=='sensecms_site')throw new RuntimeException('Unexpected database');
$baseline=json_decode(file_get_contents($stage.'/baseline.json'),true,512,JSON_THROW_ON_ERROR);$schema=null;
try{
 if($mode==='--qa'){
  $db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $schema='senseedit_'.bin2hex(random_bytes(6));$db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$schema`");
  $copied=['languages','pages','page_translations','content_blocks','content_block_translations','page_builder_states','global_sections','global_section_translations','menus','menu_items','menu_item_translations'];
  foreach([...$copied,'page_builder_revisions','page_builder_drafts','page_builder_section_locks','page_builder_section_workflow','users','activity_log','ai_knowledge_sync_queue']as$table){$db->exec("CREATE TABLE `$table` LIKE sensecms_site.`$table`");if(in_array($table,$copied,true))$db->exec("INSERT INTO `$table` SELECT * FROM sensecms_site.`$table`");}
 }
 $cms=new App\Core\CmsRepository($db,new App\Core\EventBus());$catalog=App\Core\PageBuilder::catalog(json_decode(file_get_contents($stage.'/.themes/sensecms/theme.json'),true));
 $owner=$mode==='--qa'?1:(int)$db->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.slug='owner' AND u.active=1 ORDER BY ur.user_id LIMIT 1")->fetchColumn();
 if(!$owner)throw new RuntimeException('No active owner');
 $journal=$backup?$backup.'/editorial-applied.json':null;
 $state=$mode==='--rollback'?json_decode(file_get_contents($journal),true,512,JSON_THROW_ON_ERROR):['pages'=>[],'menu'=>false,'renamed'=>false];
 $persist=static function()use(&$state,$journal,$mode):void{if($mode==='--apply'&&file_put_contents($journal,json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false)throw new RuntimeException('Cannot write recovery journal');};
 if($mode==='--apply'&&is_file($journal))throw new RuntimeException('Publication already attempted');
 $targets=[];$matched=[];
 foreach($baseline['pages']as$path=>$before){
  $blocks=$before['blocks'];$changed=isset($copy['pages'][$path])||$path==='/docs';
  foreach($blocks as&$block){
   $id=(int)$block['id'];$global=(int)($block['global_section_id']??0);
   if(isset($copy['blocks'][$id])){if($global)throw new RuntimeException('Local copy targets a shared section');foreach($copy['blocks'][$id]as$locale=>$fields)$block['localized'][$locale]=array_replace($block['localized'][$locale],$fields);$matched[$id]=true;$changed=true;}
   if(isset($copy['globalDescriptions'][$global])){foreach($copy['globalDescriptions'][$global]as$locale=>$description){$data=&$block['localized'][$locale];$count=0;$data['text']=preg_replace_callback('~(<p>.*?</p>)<p>.*?</p>(<p>.*?</p>)$~s',static fn($m)=>$m[1].'<p>'.htmlspecialchars($description,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>'.$m[2],$data['text'],1,$count);if($count!==1)throw new RuntimeException('Unexpected catalogue description structure');if($global===3)$data['title']='School Theme';unset($data);}$changed=true;}
  }unset($block);
  if(!$changed)continue;
  $current=$cms->builderDocument((int)$before['id']);
  if($mode==='--rollback'){if(!isset($state['pages'][$path]))continue;if($current!==$state['pages'][$path])throw new RuntimeException('New editorial work prevents rollback: '.$path);$blocks=$before['blocks'];}
  elseif($current!==$before)throw new RuntimeException('Baseline changed: '.$path);
  foreach(['page_builder_drafts','page_builder_section_locks']as$table){$q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE page_id=?');$q->execute([$before['id']]);if((int)$q->fetchColumn())throw new RuntimeException('Editor work found');}
  if($path==='/docs'&&$mode!=='--rollback')foreach($copy['add']as$item){$block=['uid'=>$item['uid'],'type'=>'text','source_theme'=>'core','visible'=>true,'shared'=>[],'localized'=>[]];foreach(['en','pl']as$locale)$block['localized'][$locale]=$item[$locale]+['cta_url'=>$item['url']];$blocks[]=$block;}
  $sanitized=App\Core\PageBuilder::sanitizeBlocks($blocks,$catalog,array_keys($before['translations']));
  foreach($sanitized as$index=>&$block){$block['source']=$blocks[$index]['source_theme'];if($mode==='--rollback'&&$block['global_section_id'])foreach($current['blocks']as$now)if($now['uid']===$block['uid'])$block['global_version']=(int)$now['global_version'];}unset($block);
  $targets[$path]=[$current,$sanitized];
 }
 if(count($matched)!==count($copy['blocks']))throw new RuntimeException('Missing editorial target');
 if($mode!=='--rollback'&&$cms->navigation()!==$baseline['navigation'])throw new RuntimeException('Navigation changed');
 if($mode==='--rollback'&&$state['menu']&&$cms->navigation()!==$state['navigation'])throw new RuntimeException('New menu changes prevent rollback');
 if($mode==='--check'){echo count($targets)." pages and shared-section references, EN/PL and navigation guards verified.\n";exit;}
 if($mode==='--apply')foreach($targets as$path=>[$current,$_])$state['pages'][$path]=$current;
 $persist();
 foreach($targets as$path=>[$current,$blocks]){
  $cms->saveBuilderDocument((int)$current['id'],(int)$current['builder_version'],$owner,'sensecms',$blocks,array_keys($catalog));
  // Shared sections update other pages too: capture every applied document after each save.
  $state['pages'][$path]=null;foreach($state['pages']as$key=>$_)$state['pages'][$key]=$cms->builderDocument((int)$baseline['pages'][$key]['id']);$persist();
  foreach($copy['pages'][$path]??[]as$locale=>$fields){if($mode==='--rollback')foreach($fields as$key=>$_)$fields[$key]=$baseline['pages'][$path]['translations'][$locale][$key];$sets=[];foreach($fields as$key=>$value){if(!in_array($key,['title','excerpt','seo_title','seo_description'],true))throw new RuntimeException('Unexpected metadata field');$sets[]='`'.$key.'`=?';}$q=$db->prepare('UPDATE page_translations SET '.implode(',',$sets).' WHERE page_id=? AND locale=?');$q->execute([...array_values($fields),$current['id'],$locale]);}
  $state['pages'][$path]=$cms->builderDocument((int)$current['id']);$persist();
  foreach($state['pages'][$path]['blocks']as$index=>$block)if($block['localized']!==$blocks[$index]['localized']||$block['source_theme']!==$blocks[$index]['source'])throw new RuntimeException('Content readback differs');
  (new App\Core\AiKnowledgeBase($db,$root))->queueWebsiteSource('page',(int)$current['id']);
 }
 $school=array_values(array_filter($baseline['tables']['global_sections'],static fn($g)=>(int)$g['id']===3))[0];
 $cms->renameGlobalSection(3,$mode==='--rollback'?$school['name']:'Marketplace: School Theme',$owner);$state['renamed']=true;
 foreach($state['pages']as$path=>$_)$state['pages'][$path]=$cms->builderDocument((int)$baseline['pages'][$path]['id']);$persist();
 $primary=array_values(array_filter($baseline['navigation'],static fn($item)=>$item['location']==='primary'));
 if($mode!=='--rollback'){
  $order=[[1,'Platform','Platforma','/platform'],[11,'AI','AI','/platform/ai'],[2,'Extensions','Rozszerzenia','/extensions'],[5,'Licensing','Licencjonowanie','/docs/licensing'],[3,'Documentation','Dokumentacja','/docs'],[4,'Contact','Kontakt','/contact']];$primary=[];
  foreach($order as[$id,$en,$pl,$url])$primary[]=['id'=>$id,'target'=>'_self','visible'=>true,'translations'=>['en'=>['label'=>$en,'url'=>$url],'pl'=>['label'=>$pl,'url'=>$url]]];
 }
 $menuName=array_values(array_filter($baseline['tables']['menus'],static fn($menu)=>$menu['location']==='primary'))[0]['name'];
 $cms->saveNavigation('primary',$menuName,$primary,$owner);$state['menu']=true;$state['navigation']=$cms->navigation();$persist();
 if($mode==='--qa'){
  file_put_contents($stage.'/accepted.json',json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
  // Rehearse restoration against the same disposable database, including shared sections.
  foreach($targets as$path=>$_){
   $before=$baseline['pages'][$path];$now=$cms->builderDocument((int)$before['id']);$restored=App\Core\PageBuilder::sanitizeBlocks($before['blocks'],$catalog,array_keys($before['translations']));
   foreach($restored as$i=>&$block){$block['source']=$before['blocks'][$i]['source_theme'];foreach($now['blocks']as$current)if($current['uid']===$block['uid'])$block['global_version']=(int)$current['global_version'];}unset($block);
   $cms->saveBuilderDocument((int)$now['id'],(int)$now['builder_version'],$owner,'sensecms',$restored,array_keys($catalog));
   foreach($copy['pages'][$path]??[]as$locale=>$fields){$sets=[];$values=[];foreach($fields as$key=>$_){$sets[]='`'.$key.'`=?';$values[]=$before['translations'][$locale][$key];}$db->prepare('UPDATE page_translations SET '.implode(',',$sets).' WHERE page_id=? AND locale=?')->execute([...$values,$now['id'],$locale]);}
  }
  $cms->renameGlobalSection(3,$school['name'],$owner);$cms->saveNavigation('primary',$menuName,array_values(array_filter($baseline['navigation'],static fn($item)=>$item['location']==='primary')),$owner);
  $comparable=static function(array$doc):array{unset($doc['builder_version'],$doc['updated_at']);foreach($doc['blocks']as&$block)unset($block['updated_at'],$block['global_version']);unset($block);return$doc;};
  foreach($baseline['pages']as$path=>$before)if($comparable($before)!==$comparable($cms->builderDocument((int)$before['id'])))throw new RuntimeException('Rollback rehearsal differs: '.$path);
  if($cms->navigation()!==$baseline['navigation'])throw new RuntimeException('Navigation rollback differs');
  echo "Isolated rollback restored all 52 public documents, metadata and navigation.\n";
 }
 echo count($targets)." pages verified; shared descriptions, original identities and bilingual navigation preserved.\n";
}finally{if($schema!==null&&preg_match('/^senseedit_[a-f0-9]{12}$/D',$schema))$db->exec("DROP DATABASE `$schema`");}
