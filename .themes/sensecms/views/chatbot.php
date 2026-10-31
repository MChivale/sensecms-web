<?php
declare(strict_types=1);
$chatbotFull = in_array($canonicalPath, ['/', '/platform/ai'], true);
$chatbotConfig = (array) ($themeSettings ?? []);
$chatbotSetting = static function(string $key, string $en, string $pl) use ($chatbotConfig, $locale, $tr): string {
    return trim((string) ($chatbotConfig[$key . ($locale === 'pl' ? '_pl' : '')] ?? '')) ?: $tr($en, $pl);
};
?>
<section id="private-ai-chatbot" class="chatbot-feature<?= $chatbotFull ? '' : ' chatbot-compact' ?>" aria-labelledby="chatbot-title">
    <div class="chatbot-copy">
        <span class="eyebrow"><?= $e($tr('BUILT INTO SENSE CMS', 'ZINTEGROWANY Z SENSE CMS')) ?></span>
        <h2 id="chatbot-title"><?= $e($chatbotSetting('chatbot_title', 'Your Private, Personalized AI Chatbot.', 'Twój prywatny, spersonalizowany AI Chatbot.')) ?></h2>
        <p class="chatbot-lead"><?= $e($chatbotSetting('chatbot_intro', 'Your knowledge. Your website. An assistant that speaks for your organisation.', 'Twoja wiedza. Twoja strona. Asystent, który reprezentuje Twoją organizację.')) ?></p>
        <p><?= $e($chatbotSetting('chatbot_text', 'Teach it with your own texts, documents and approved website pages. Sense CMS brings your materials into a knowledge base so your integrated chatbot can help visitors with answers grounded in your information.', 'Ucz go na własnych tekstach, dokumentach i zatwierdzonych stronach. Sense CMS łączy Twoje materiały w Bazę Wiedzy, dzięki której zintegrowany chatbot pomaga odwiedzającym, opierając odpowiedzi na Twoich informacjach.')) ?></p>
        <div class="actions"><a class="button inverse" href="<?= $e($localUrl($canonicalPath === '/platform/ai' ? '/contact' : '/platform/ai') . ($canonicalPath === '/platform/ai' ? '' : '#private-ai-chatbot')) ?>"><?= $e($canonicalPath === '/platform/ai' ? $tr('Plan your own AI chatbot', 'Zaplanuj własnego AI Chatbota') : $tr('Meet your AI chatbot', 'Poznaj swojego AI Chatbota')) ?> <span aria-hidden="true">→</span></a></div>
        <p class="chatbot-provider"><?= $e($tr('Your knowledge base is managed in your CMS. Replies use the AI provider you configure.', 'Bazą Wiedzy zarządzasz w swoim CMS. Odpowiedzi powstają z użyciem skonfigurowanego dostawcy AI.')) ?></p>
    </div>
    <?php if ($chatbotFull): ?>
    <figure class="chatbot-visual">
        <figcaption><?= $e($tr('YOUR MATERIALS BECOME USEFUL ANSWERS', 'TWOJE MATERIAŁY STAJĄ SIĘ POMOCNYMI ODPOWIEDZIAMI')) ?></figcaption>
        <ul class="chatbot-sources">
            <?php foreach ([['Your texts','Twoje teksty','M5 4h14v16H5zM8 8h8M8 12h8M8 16h5'],['Documents','Dokumenty','M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6'],['Website pages','Strony WWW','M3 4h18v16H3zM3 9h18M7 6.5h.01M10 6.5h.01']] as [$en,$pl,$icon]): ?>
            <li><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $icon ?>"/></svg><span><?= $e($tr($en,$pl)) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <div class="chatbot-connector" aria-hidden="true">↓</div>
        <div class="chatbot-identity"><span class="chatbot-symbol" aria-hidden="true"><svg viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12h24a7 7 0 0 1 7 7v13a7 7 0 0 1-7 7H22l-10 6v-6a7 7 0 0 1-7-7V19a7 7 0 0 1 7-7ZM24 12V5M20 5h8M15 23h.01M33 23h.01M18 31c4 3 8 3 12 0"/></svg></span><div><small><?= $e($tr('POWERED BY YOUR KNOWLEDGE', 'OPARTY NA TWOJEJ WIEDZY')) ?></small><strong><?= $e($tr('Your AI Chatbot', 'Twój AI Chatbot')) ?></strong><span><?= $e($tr('A dedicated assistant for your website', 'Asystent dedykowany Twojej stronie')) ?></span></div></div>
        <div class="chatbot-connector" aria-hidden="true">↓</div>
        <div class="chatbot-outcome"><span class="chatbot-outcome-icon" aria-hidden="true">✓</span><div><strong><?= $e($tr('Answers shaped around your organisation', 'Odpowiedzi dopasowane do Twojej organizacji')) ?></strong><span><?= $e($tr('Relevant information. Source links. A route to your team.', 'Właściwe informacje. Odnośniki do źródeł. Kontakt z zespołem.')) ?></span></div></div>
    </figure>
    <ol class="chatbot-benefits">
        <li><span>01</span><div><h3><?= $e($tr('Give it your knowledge', 'Przekaż mu swoją wiedzę')) ?></h3><p><?= $e($tr('Add service descriptions, practical guides and answers to common questions. Choose which materials are approved for visitor answers.', 'Dodaj opisy usług, praktyczne poradniki i odpowiedzi na częste pytania. Wybieraj materiały dopuszczone do odpowiedzi odwiedzającym.')) ?></p></div></li>
        <li><span>02</span><div><h3><?= $e($tr('Keep it personal and current', 'Dbaj o dopasowanie i aktualność')) ?></h3><p><?= $e($tr('Correct, update or disable sources as your organisation changes. Test the answers in the languages your visitors use.', 'Poprawiaj, aktualizuj lub wyłączaj źródła, gdy zmienia się Twoja organizacja. Sprawdzaj odpowiedzi w językach odwiedzających.')) ?></p></div></li>
        <li><span>03</span><div><h3><?= $e($tr('Connect answers with people', 'Połącz odpowiedzi z obsługą')) ?></h3><p><?= $e($tr('Help visitors find useful information and linked sources. When a question needs a person, the conversation can move to your team.', 'Pomagaj znaleźć przydatne informacje i wskazane źródła. Gdy pytanie wymaga udziału człowieka, rozmowę można przekazać zespołowi.')) ?></p></div></li>
    </ol>
    <details class="chatbot-explainer"><summary><?= $e($tr('What do “private” and “teach it” mean?', 'Co oznaczają „prywatny” i „uczenie” chatbota?')) ?></summary><p><?= $e($tr('You manage a dedicated knowledge base in your Sense CMS installation and decide which sources the assistant may use. To generate a reply, the selected AI provider receives the question, relevant source excerpts and recent conversation context. Adding materials enriches the knowledge used for answers; it does not retrain the underlying model. Review provider terms and share only material suitable for visitor support.', 'Zarządzasz własną Bazą Wiedzy w instalacji Sense CMS i decydujesz, z jakich źródeł może korzystać asystent. Aby przygotować odpowiedź, wybrany dostawca AI otrzymuje pytanie, odpowiednie fragmenty źródeł i kontekst ostatnich wiadomości. Dodawanie materiałów poszerza wiedzę wykorzystywaną w odpowiedziach; nie jest ponownym trenowaniem modelu. Sprawdź warunki dostawcy i udostępniaj materiały odpowiednie do obsługi odwiedzających.')) ?></p></details>
    <?php endif; ?>
</section>
<?php unset($chatbotFull, $chatbotSetting, $chatbotConfig); ?>
