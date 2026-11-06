<?php declare(strict_types=1); ?>
<div class="product-hero">
  <section class="container product-intro">
    <div class="hero-copy">
      <span class="eyebrow"><span class="status-dot"></span> <?= $e($tr('SENSE CMS · CONTENT MANAGEMENT','SENSE CMS · ZARZĄDZANIE TREŚCIĄ')) ?></span>
      <h1><?= $e($themeSettings['home_title']) ?><br><span class="blue"><?= $e($themeSettings['home_accent']) ?></span></h1>
      <p class="intro"><?= $e($themeSettings['home_intro']) ?> <?= $e($themeSettings['home_intro_end']) ?></p>
      <div class="actions"><a class="button" href="https://demo.sensecms.com/" target="_blank" rel="noopener noreferrer"><?= $e($tr('Try the demo','Wypróbuj demo')) ?> <span aria-hidden="true">↗</span></a><a class="secondary-button" href="<?= $e($localUrl('/platform')) ?>"><?= $e($tr('Explore the features','Poznaj możliwości')) ?> <span aria-hidden="true">→</span></a></div>
      <a class="chatbot-hero-link" href="<?= $e($localUrl('/platform/ai')) ?>#private-ai-chatbot"><span aria-hidden="true">✦</span> <?= $e($tr('Your Private, Personalized AI Chatbot', 'Twój prywatny, spersonalizowany AI Chatbot')) ?> <span aria-hidden="true">→</span></a>
    </div>
    <aside class="hero-editorial"><span class="section-index"><?= $e($tr('01 / YOUR DIGITAL WORKSPACE','01 / TWOJA CYFROWA PRZESTRZEŃ')) ?></span><p><?= $e($themeSettings['home_detail']) ?></p><div class="hero-notes"><span><?= $e($tr('Your hosting choice','Twój wybór hostingu')) ?></span><span><?= $e($tr('Your content','Twoje treści')) ?></span><span><?= $e($tr('Your rules','Twoje zasady')) ?></span></div></aside>
  </section>
  <div class="container workspace-stage">
    <figure class="workspace-figure">
      <?php $editorImage = '/theme-assets/page-builder-open-day-' . $tr('en','pl') . '.webp'; ?>
      <div class="publication-preview">
        <div class="preview-editor">
          <span class="preview-step"><?= $e($tr('01 / Editorial workspace','01 / Panel redaktora')) ?></span>
          <strong>Page Builder</strong>
          <a class="editor-image-link" href="<?= $e($editorImage) ?>" data-gallery-title="<?= $e($tr('Editing a school announcement','Edycja ogłoszenia szkolnego')) ?>">
            <img src="<?= $e($editorImage) ?>" width="632" height="354" fetchpriority="high" alt="<?= $e($tr('Page Builder fields for the School news section and the School open day headline','Pola Page Buildera z nadtytułem „Aktualności szkoły” i nagłówkiem „Dzień otwarty w naszej szkole”')) ?>">
            <span><?= $e($tr('Enlarge the editor view','Powiększ widok edytora')) ?> <span aria-hidden="true">↗</span></span>
          </a>
        </div>
        <div class="preview-result">
          <span class="preview-step"><?= $e($tr('02 / School website','02 / Strona szkoły')) ?></span>
          <div class="example-date"><b>18</b><span><?= $e($tr('MAY','MAJ')) ?></span></div>
          <strong><?= $e($tr('School open day','Dzień otwarty w naszej szkole')) ?></strong>
          <p><?= $e($tr('10 am · Central School','Godz. 10.00 · Szkoła Centrum')) ?></p>
          <a href="<?= $e($localUrl('/platform/schools')) ?>#industry-example"><?= $e($tr('Read the announcement','Przeczytaj ogłoszenie')) ?> <span aria-hidden="true">→</span></a>
        </div>
      </div>
      <figcaption><?= $e($tr('From the Page Builder draft to the announcement families see. Example content.','Od szkicu w Page Builderze do ogłoszenia dla rodziców. Przykładowa treść.')) ?></figcaption>
    </figure>
  </div>
</div>
<section class="product-principles container" aria-label="<?= $e($tr('Platform principles','Założenia platformy')) ?>"><div><span>01</span><strong><?= $e($tr('Content without clutter','Treści bez chaosu')) ?></strong><small><?= $e($tr('Pages, media & publishing','Strony, media i publikacje')) ?></small></div><div><span>02</span><strong><?= $e($tr('Connected teams','Współpracujące zespoły')) ?></strong><small><?= $e($tr('Roles & facility-scoped access','Role i dostęp według placówek')) ?></small></div><div><span>03</span><strong><?= $e($tr('Freedom to build','Swoboda tworzenia')) ?></strong><small><?= $e($tr('Independent, replaceable themes','Niezależne, wymienne motywy')) ?></small></div><div><span>04</span><strong><?= $e($tr('Ownership by design','Kontrola nad własnym systemem')) ?></strong><small><?= $e($tr('Your infrastructure or our servers','Twoja infrastruktura lub nasze serwery')) ?></small></div></section>
<div class="container chatbot-home"><?php require __DIR__ . '/chatbot.php'; ?></div>
<div class="container"><?php require __DIR__ . '/solutions.php'; ?></div>
<div class="container"><?php require __DIR__ . '/apps.php'; ?></div>
<section class="section container product-features">
  <div class="section-heading"><div><span class="eyebrow"><?= $e($tr('LESS FRICTION. MORE POSSIBILITY.','MNIEJ PRZESZKÓD. WIĘCEJ MOŻLIWOŚCI.')) ?></span><h2><?= $e($themeSettings['features_title']) ?></h2></div><p><?= $e($themeSettings['features_intro']) ?></p></div>
  <div class="home-sections"><?php require __DIR__ . '/blocks.php'; ?></div>
</section>
<section class="ecosystem-section">
  <div class="container ecosystem-layout"><div class="ecosystem-copy"><span class="eyebrow"><?= $e($tr('AN OPEN-ENDED FOUNDATION','PODSTAWA DO ROZWOJU')) ?></span><h2><?= $e($themeSettings['ecosystem_title']) ?></h2><p><?= $e($themeSettings['ecosystem_text']) ?></p><a class="button inverse" href="<?= $e($localUrl('/extensions')) ?>"><?= $e($tr('Meet the ecosystem','Poznaj ekosystem')) ?> <span aria-hidden="true">↗</span></a></div>
    <div class="ecosystem-map" aria-label="<?= $e($tr('Sense CMS Core and four independent package types','Rdzeń Sense CMS i cztery niezależne rodzaje pakietów')) ?>">
      <div class="ecosystem-core"><img src="/theme-assets/logo.svg" width="40" height="40" alt=""><div><span><?= $e($tr('THE SHARED FOUNDATION','WSPÓLNA PODSTAWA')) ?></span><strong>Sense CMS Core</strong></div></div>
      <div class="ecosystem-packages"><a href="<?= $e($localUrl('/extensions/themes')) ?>"><span><?= $e($tr('01 / PRESENTATION','01 / WYGLĄD')) ?></span><strong><?= $e($tr('Themes ↗','Motywy ↗')) ?></strong><p><?= $e($tr('Your visual identity.','Twoja identyfikacja wizualna.')) ?></p></a><a href="<?= $e($localUrl('/extensions/modules')) ?>"><span><?= $e($tr('02 / CAPABILITIES','02 / MOŻLIWOŚCI')) ?></span><strong><?= $e($tr('Modules ↗','Moduły ↗')) ?></strong><p><?= $e($tr("Your application's features.",'Funkcje Twojej aplikacji.')) ?></p></a><a href="<?= $e($localUrl('/extensions/plugins')) ?>"><span><?= $e($tr('03 / CONNECTIONS','03 / POŁĄCZENIA')) ?></span><strong><?= $e($tr('Plugins ↗','Wtyczki ↗')) ?></strong><p><?= $e($tr('Your service integrations.','Integracje z usługami.')) ?></p></a><a href="<?= $e($localUrl('/extensions/addons')) ?>"><span><?= $e($tr('04 / WORKSPACE','04 / PRZESTRZEŃ PRACY')) ?></span><strong><?= $e($tr('Addons ↗','Dodatki ↗')) ?></strong><p><?= $e($tr('Your additional tools.','Twoje dodatkowe narzędzia.')) ?></p></a></div>
      <p class="ecosystem-disclosure"><?= $e($tr('Package availability and compatibility are listed separately.','Dostępność i zgodność pakietów podajemy osobno.')) ?> <a href="<?= $e($localUrl('/download')) ?>"><?= $e($tr('Check release status →','Sprawdź status wydania →')) ?></a></p>
    </div>
  </div>
</section>
<section class="section container ownership-section"><div><span class="eyebrow"><?= $e($tr('YOUR PLATFORM, ON YOUR TERMS','TWOJA PLATFORMA, TWOJE ZASADY')) ?></span><h2><?= $e($themeSettings['ownership_title']) ?></h2><p><?= $e($themeSettings['ownership_text']) ?></p><a class="text-link" href="<?= $e($localUrl('/docs/themes')) ?>"><?= $e($tr('Explore theme development','Poznaj tworzenie motywów')) ?> <span aria-hidden="true">→</span></a></div><ol class="ownership-list"><li><span>01</span><div><strong><?= $e($tr('Keep your content','Zachowaj swoje treści')) ?></strong><p><?= $e($tr('Pages and their addresses belong to the CMS, not the theme.','Strony i ich adresy należą do systemu CMS, a nie do motywu.')) ?></p></div></li><li><span>02</span><div><strong><?= $e($tr('Shape the experience','Kształtuj wygląd strony')) ?></strong><p><?= $e($tr('Change your presentation without replacing the administration panel.','Zmieniaj wygląd bez wymiany panelu administracyjnego.')) ?></p></div></li><li><span>03</span><div><strong><?= $e($tr('Choose your infrastructure','Wybierz infrastrukturę')) ?></strong><p><?= $e($tr('Deploy on your own infrastructure or let us host Sense CMS on our servers.','Uruchom Sense CMS na własnej infrastrukturze lub skorzystaj z hostingu na naszych serwerach.')) ?></p></div></li></ol></section>
<section class="container product-closing"><div><span class="eyebrow"><?= $e($tr('LET’S BUILD WHAT’S NEXT','ZAPLANUJMY KOLEJNY KROK')) ?></span><h2><?= $e($themeSettings['closing_title']) ?></h2><p><?= $e($themeSettings['closing_text']) ?></p></div><div class="closing-actions"><a class="button" href="<?= $e($localUrl('/contact')) ?>"><?= $e($tr('Talk about your project','Porozmawiajmy o Twoim projekcie')) ?> <span aria-hidden="true">↗</span></a><a class="text-link" href="<?= $e($localUrl('/download')) ?>"><?= $e($tr('Development & release status →','Rozwój i status wydania →')) ?></a></div></section>
