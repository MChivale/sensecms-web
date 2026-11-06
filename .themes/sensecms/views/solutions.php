<?php declare(strict_types=1); ?>
<section id="local-websites" class="solutions-section" aria-labelledby="local-websites-title">
    <div class="solutions-heading">
        <span class="eyebrow"><?= $e($tr('ONE BRAND. A LOCAL EXPERIENCE.', 'JEDNA MARKA. LOKALNE DOŚWIADCZENIE.')) ?></span>
        <h2 id="local-websites-title"><?= $e($tr('One website address. Your nearest branch’s own website.', 'Jeden adres. Spersonalizowana strona najbliższej placówki.')) ?></h2>
        <p><?= $e($tr('Give every location a recognisable place within your brand. Visitors enter one shared website address and can be directed to the nearest branch’s personalised website, with its own offer, local news and contact details.', 'Daj każdej placówce własne miejsce w ramach wspólnej marki. Odwiedzający wpisuje jeden adres strony i może trafić na spersonalizowaną stronę najbliższej placówki — z jej ofertą, lokalnymi aktualnościami i danymi kontaktowymi.')) ?></p>
    </div>
    <?php if (($page['kind']??'')==='home'): ?>
    <nav class="industry-links" aria-label="<?= $e($tr('Sense CMS by industry','Sense CMS dla branż')) ?>">
      <?php foreach (require dirname(__DIR__) . '/industry-pages.php' as $route => $example): ?>
        <a href="<?= $e($localUrl($route)) ?>">
          <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $e($example['icon']) ?>"/></svg>
          <?= $e($example[$tr('en','pl')]['label']) ?> <span aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>
    </nav>
    <a class="text-link" href="<?= $e($localUrl('/platform')) ?>#local-websites"><?= $e($tr('How branch websites work','Jak działają strony placówek')) ?> →</a>
    <?php else: ?>
    <figure class="branch-visual">
        <div class="branch-entry"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 6h14M5 18h14"/></svg><div><small><?= $e($tr('ONE SHARED ADDRESS', 'JEDEN WSPÓLNY ADRES')) ?></small><strong><?= $e($tr('your-brand.example', 'twoja-marka.example')) ?></strong></div></div>
        <div class="branch-routing"><span aria-hidden="true">↓</span><p><?= $e($tr('Location shared with permission → nearest branch', 'Lokalizacja za zgodą odwiedzającego → najbliższa placówka')) ?></p><small><?= $e($tr('Or choose a location yourself', 'Lub samodzielny wybór placówki')) ?></small></div>
        <div class="branch-websites">
            <?php foreach ([
                ['North branch','Placówka Północ','Local services','Lokalne usługi','Meet the local team','Poznaj lokalny zespół','north','polnoc',false],
                ['City Centre branch','Placówka Centrum','Your local offer','Twoja lokalna oferta','News from your branch','Aktualności Twojej placówki','centre','centrum',true],
                ['South branch','Placówka Południe','Discover this location','Poznaj tę lokalizację','What’s happening here','Co nowego w placówce','south','poludnie',false],
            ] as [$nameEn,$namePl,$offerEn,$offerPl,$newsEn,$newsPl,$slugEn,$slugPl,$selected]): ?>
            <div class="branch-site<?= $selected ? ' is-nearest' : '' ?>">
                <div class="branch-site-label"><?= $e($selected ? $tr('NEAREST · PERSONALISED WEBSITE', 'NAJBLIŻSZA · SPERSONALIZOWANA STRONA') : $tr('ANOTHER BRANCH WEBSITE', 'STRONA INNEJ PLACÓWKI')) ?></div>
                <div class="branch-address"><span aria-hidden="true">● ● ●</span> /<?= $e($tr($slugEn,$slugPl)) ?></div>
                <div class="branch-page">
                    <div class="branch-brand"><img src="/theme-assets/logo.svg" alt="" width="18" height="20"><span><?= $e($tr('YOUR BRAND', 'TWOJA MARKA')) ?></span></div>
                    <svg class="branch-building" viewBox="0 0 240 86" fill="none" aria-hidden="true"><path d="M0 74h240M30 74V27l30-15 30 15v47M103 74V9h58v65M174 74V36h40v38" stroke="currentColor" stroke-width="2"/><path d="M43 34h12v12H43zm23 0h12v12H66zm-23 22h12v18H43zm23 0h12v18H66zM115 21h12v12h-12zm24 0h10v12h-10zm-24 23h12v12h-12zm24 0h10v12h-10zm46 3h17v12h-17z" fill="currentColor" opacity=".25"/><path d="M131 74V63h12v11" stroke="currentColor" stroke-width="2"/></svg>
                    <h3><?= $e($tr($nameEn,$namePl)) ?></h3><p><?= $e($tr($offerEn,$offerPl)) ?></p>
                    <div class="branch-local-content"><strong><?= $e($tr($newsEn,$newsPl)) ?></strong><span><?= $e($tr('Local news & events', 'Lokalne aktualności i wydarzenia')) ?></span></div>
                    <div class="branch-local-contact"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/></svg><span><?= $e($tr('This branch’s address & contact', 'Adres i kontakt tej placówki')) ?></span></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <figcaption><?= $e($tr('Illustrative journey and page designs. Local content within one brand; the location flow and theme are configured for your deployment.', 'Przykładowa ścieżka i projekty stron. Lokalne treści w ramach jednej marki; wybór lokalizacji i motyw dostosowujemy do wdrożenia.')) ?></figcaption>
    </figure>
    <div class="branch-benefits">
        <div><h3><?= $e($tr('A website for each location', 'Własna strona każdej placówki')) ?></h3><p><?= $e($tr('Present a local offer, team, news and contact details. Each branch keeps its own page address, so a visitor can return directly.', 'Prezentuj lokalną ofertę, zespół, aktualności i kontakt. Każda placówka zachowuje własny adres strony, pod który odwiedzający może wrócić bezpośrednio.')) ?></p></div>
        <div><h3><?= $e($tr('One place to manage the network', 'Jedno miejsce do zarządzania siecią')) ?></h3><p><?= $e($tr('Manage shared content alongside branch-specific pages. Assign access by facility and prepare content in the languages your visitors use.', 'Zarządzaj wspólnymi treściami i stronami poszczególnych placówek. Przydzielaj dostęp według placówek i przygotowuj treści w językach odwiedzających.')) ?></p></div>
        <div><h3><?= $e($tr('A location flow fitted to your project', 'Wybór lokalizacji dopasowany do projektu')) ?></h3><p><?= $e($tr('We configure nearest-branch routing in your theme, including location permission and manual selection. If location is unavailable, visitors can still choose their branch.', 'Konfigurujemy w motywie kierowanie do najbliższej placówki, zgodę na lokalizację i ręczny wybór. Gdy lokalizacja jest niedostępna, odwiedzający nadal może sam wybrać placówkę.')) ?></p></div>
    </div>
    <div class="solutions-heading solutions-industries-heading"><span class="eyebrow"><?= $e($tr('BUILT AROUND YOUR ORGANISATION', 'DOPASOWANY DO TWOJEJ ORGANIZACJI')) ?></span><h2><?= $e($tr('A shared platform. Different ways to use it.', 'Wspólna platforma. Różne zastosowania.')) ?></h2></div>
    <ul class="solutions-industries">
        <?php foreach ([
            ['Companies','Firmy','Your services, expertise, news and customer contact in one multilingual website.','Usługi, doświadczenie, aktualności i kontakt z klientami w jednym wielojęzycznym serwisie.'],
            ['Multi-branch businesses','Firmy z wieloma oddziałami','One entry address, personalised local websites and clear responsibilities for each branch.','Jeden adres wejściowy, spersonalizowane lokalne strony i jasny podział odpowiedzialności za oddziały.'],
            ['Schools','Szkoły','School profiles, educational offers, announcements and information for families.','Prezentacja szkół, oferta edukacyjna, ogłoszenia i informacje dla rodziców.'],
            ['Tourism & accommodation','Turystyka i noclegi','Present properties, rooms and local attractions; connect your reservation service as part of the project.','Prezentuj obiekty, pokoje i lokalne atrakcje; w ramach projektu połącz serwis z obsługą rezerwacji.'],
            ['Beauty salons','Gabinety kosmetyczne','Local treatments, teams and contact details, with appointment integrations tailored to your workflow.','Lokalna oferta zabiegów, zespoły i dane kontaktowe oraz integracje zapisów dopasowane do sposobu pracy.'],
        ] as $industryIndex=>[$titleEn,$titlePl,$textEn,$textPl]): ?>
        <li><h3><?= $e($tr($titleEn,$titlePl)) ?></h3><p><?= $e($tr($textEn,$textPl)) ?></p><a class="text-link" href="<?= $e($localUrl(['/platform/companies','/platform/companies','/platform/schools','/platform/hospitality','/platform/beauty'][$industryIndex])) ?>"><?= $e($tr('Explore this use case →','Poznaj zastosowanie →')) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <a class="text-link" href="<?= $e($localUrl('/contact')) ?>"><?= $e($tr('Plan your website and branch experience →', 'Zaplanuj serwis i strony swoich placówek →')) ?></a>
    <?php endif; ?>
</section>
<section id="your-platform" class="solutions-section solutions-offering" aria-labelledby="your-platform-title">
    <div class="solutions-heading"><span class="eyebrow"><?= $e($tr('SOFTWARE. HOSTING. ROOM TO GROW.', 'SYSTEM. HOSTING. MOŻLIWOŚCI ROZWOJU.')) ?></span><h2 id="your-platform-title"><?= $e($tr('What do you get with Sense CMS?', 'Co zyskujesz z Sense CMS?')) ?></h2><p><?= $e($tr('Start with a shared foundation for your website and team. Choose the hosting, everyday tools and integrations that fit your organisation. We also build custom addons and connections for requirements unique to your business.', 'Zacznij od wspólnej podstawy dla strony i zespołu. Wybierz hosting, narzędzia codziennej pracy i integracje dopasowane do organizacji. Tworzymy również dodatki i połączenia na zamówienie, odpowiadające na indywidualne potrzeby firmy.')) ?></p></div>
    <?php if (($page['kind']??'')==='home'): ?>
    <a class="text-link" href="<?= $e($localUrl('/platform')) ?>#your-platform"><?= $e($tr('Explore features, hosting and extensions','Poznaj funkcje, hosting i rozszerzenia')) ?> →</a>
    <?php else: ?>
    <div class="solutions-offer-grid">
        <?php foreach ([
            ['01','CORE','RDZEŃ SYSTEMU','Your content and team','Twoje treści i zespół','Multilingual pages, media, editorial work, users, roles and facility-scoped access. An integrated personalised AI chatbot can answer using your materials and a configured AI provider.','Wielojęzyczne strony, media, praca redakcyjna, użytkownicy, role i dostęp według placówek. Zintegrowany spersonalizowany AI Chatbot odpowiada na podstawie Twoich materiałów, z użyciem skonfigurowanego dostawcy AI.','/platform/ai','Explore the AI chatbot','Poznaj AI Chatbota'],
            ['02','HOSTING CHOICE','WYBÓR HOSTINGU','Your servers. Or ours.','Twoje serwery. Lub nasze.','Sense CMS can run on your own infrastructure or be hosted on our servers. We agree the hosting scope and operational responsibilities with you as part of the deployment.','Sense CMS może działać na Twojej infrastrukturze lub być utrzymywany na naszych serwerach. Zakres hostingu i odpowiedzialność za utrzymanie ustalamy wspólnie w ramach wdrożenia.','/contact','Discuss hosting','Porozmawiaj o hostingu'],
            ['03','OPTIONAL ADDON','OPCJONALNY DODATEK','Calendars that support your work','Kalendarze wspierające pracę','Plan work and events, organise participants, reserve resources and set reminders with Calendar. Client signups, appointment booking and accommodation reservations can be delivered through a tailored addon or integration.','Planuj pracę i wydarzenia, organizuj uczestników, rezerwuj zasoby i ustawiaj przypomnienia z dodatkiem Calendar. Zapisy klientów, umawianie wizyt i rezerwacje noclegów realizujemy poprzez dopasowany dodatek lub integrację.','/extensions/addons','Explore calendar addons','Poznaj dodatki kalendarzowe'],
            ['04','OPTIONAL INTEGRATIONS','OPCJONALNE INTEGRACJE','Keep people informed','Informuj na bieżąco','Connect notifications to supported messengers and publish through social-media integrations. Select the channels you need; package availability, account permissions and provider approval are checked for each connection.','Połącz powiadomienia z obsługiwanymi komunikatorami i publikuj przez integracje z mediami społecznościowymi. Wybierz potrzebne kanały; dla każdego sprawdzamy dostępność pakietu, uprawnienia konta i wymagania dostawcy.','/extensions/plugins','Explore integrations','Poznaj integracje'],
            ['05','MADE TO ORDER','NA ZAMÓWIENIE','Addons for the way you work','Dodatki do Twojego sposobu pracy','Need a particular work plan, registration process or booking flow? We build custom addons around your processes, with the scope and delivery agreed before implementation.','Potrzebujesz konkretnego planu pracy, procesu zapisów lub obsługi rezerwacji? Tworzymy dodatki wokół Twoich procesów, uzgadniając zakres i realizację przed wdrożeniem.','/contact','Discuss your addon','Omów swój dodatek'],
            ['06','CUSTOM CONNECTIONS','INDYWIDUALNE POŁĄCZENIA','Make your systems work together','Połącz używane systemy','We develop unusual integrations with external services, business systems and social platforms. Feasibility depends on the available API, access permissions and the provider’s terms.','Wykonujemy nietypowe integracje z usługami zewnętrznymi, systemami firmowymi i platformami społecznościowymi. Możliwości zależą od dostępnego API, uprawnień i zasad dostawcy.','/contact','Plan an integration','Zaplanuj integrację'],
        ] as [$number,$typeEn,$typePl,$titleEn,$titlePl,$textEn,$textPl,$url,$linkEn,$linkPl]): ?>
        <article><div class="solutions-card-top"><span><?= $number ?></span><small><?= $e($tr($typeEn,$typePl)) ?></small></div><h3><?= $e($tr($titleEn,$titlePl)) ?></h3><p><?= $e($tr($textEn,$textPl)) ?></p><a class="text-link" href="<?= $e($localUrl($url)) ?>"><?= $e($tr($linkEn,$linkPl)) ?> <span aria-hidden="true">→</span></a></article>
        <?php endforeach; ?>
    </div>
    <p class="solutions-availability"><?= $e($tr('Core capabilities, optional packages and custom services have separate scopes. Check the catalogue for each package’s current availability and requirements; custom development and hosting are agreed individually.', 'Funkcje rdzenia, opcjonalne pakiety i usługi na zamówienie mają odrębny zakres. Aktualną dostępność i wymagania pakietów znajdziesz w katalogu; prace indywidualne i hosting ustalamy osobno.')) ?></p>
    <?php endif; ?>
</section>
