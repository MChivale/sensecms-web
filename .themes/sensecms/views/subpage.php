<?php
declare(strict_types=1);
$publicPath = (string) ($page['public_path'] ?? $path);
$marketplace = (bool) preg_match('~^/extensions/catalog(?:/(?:theme|plugin|addon|module))?$~D', $publicPath);
$section = match (true) {
    $publicPath === '/contact' => 'contact',
    in_array($publicPath, ['/download','/update'], true) => 'release',
    $publicPath === '/docs' => 'resources',
    str_starts_with($publicPath, '/docs/') => 'guide',
    str_starts_with($publicPath, '/extensions') => 'extensions',
    default => 'page',
};
$labels = ['contact'=>$tr('Let’s build something meaningful','Stwórzmy coś wartościowego'), 'release'=>$tr('Built carefully. Released responsibly.','Tworzymy starannie. Wydajemy odpowiedzialnie.'), 'resources'=>$tr('For people who build','Dla twórców'), 'guide'=>$tr('Sense CMS documentation','Dokumentacja Sense CMS'), 'extensions'=>$tr('A system shaped around you','System dopasowany do Ciebie'), 'page'=>$tr('Discover Sense CMS','Poznaj Sense CMS')];
$docs = ['/docs/installation'=>$tr('Installation','Instalacja'),'/docs/licensing'=>$tr('Licensing','Licencjonowanie'),'/docs/packages'=>$tr('Package contract','Specyfikacja pakietów'),'/docs/themes'=>$tr('Theme development','Tworzenie motywów'),'/docs/server'=>$tr('Server configuration','Konfiguracja serwera'),'/update'=>$tr('Updates','Aktualizacje')];
$categories = ['/extensions/modules'=>$tr('Modules','Moduły'),'/extensions/plugins'=>$tr('Plugins','Wtyczki'),'/extensions/addons'=>$tr('Addons','Dodatki'),'/extensions/themes'=>$tr('Themes','Motywy')];
$categoryHome = str_starts_with($publicPath, '/extensions/catalog') ? '/extensions/catalog' : '/extensions';
if ($categoryHome === '/extensions/catalog') $categories = ['/extensions/catalog/module'=>$tr('Modules','Moduły'),'/extensions/catalog/plugin'=>$tr('Plugins','Wtyczki'),'/extensions/catalog/addon'=>$tr('Addons','Dodatki'),'/extensions/catalog/theme'=>$tr('Themes','Motywy')];
$blockIcons = [
    '/extensions/modules'=>'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
    '/extensions/plugins'=>'M8 3v5m8-5v5M6 8h12v3a6 6 0 0 1-6 6v4m-6-13v3a6 6 0 0 0 6 6',
    '/extensions/addons'=>'M12 3v18M3 12h18',
    '/extensions/themes'=>'M3 4h18v16H3zM3 9h18M9 9v11',
    '/docs/installation'=>'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5',
    '/docs/licensing'=>'M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6',
    '/docs/packages'=>'m12 3 9 5v9l-9 5-9-5V8zM3 8l9 5 9-5m-9 5v9M8 5l9 5',
    '/docs/themes'=>'m8 5-7 7 7 7m8-14 7 7-7 7m-3-16-2 18',
    '/docs/server'=>'M3 3h18v7H3zM3 14h18v7H3zM7 6h1m-1 11h1M12 6h5m-5 11h5',
    '/update'=>'M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-2l2 7M4 12l2 7a7 7 0 0 0 12-2',
    '/platform/ai'=>'m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z',
];
$contactBlocks = $blocks;
$contactSplit = $section === 'contact' && !empty($formCsrf) && count(array_filter($blocks, static fn(array $block): bool => $block['type'] === 'contact-form')) > 0;
if ($contactSplit) $blocks = array_values(array_filter($contactBlocks, static fn(array $block): bool => $block['type'] === 'contact-form'));
$featuredResource = $section === 'resources';
?>
<section class="subpage-hero subpage-<?= $section ?><?= $marketplace ? ' marketplace-hero' : '' ?>">
    <div class="container">
        <?php $renderBreadcrumb(); ?>
        <div class="subpage-intro"><div><span class="eyebrow"><?= $e($labels[$section]) ?></span><h1><?= $e($page['title']) ?></h1><?php if ($page['description'] !== ''): ?><p><?= $e($page['description']) ?></p><?php endif; ?></div>
        <?php if ($section==='contact' && $contactSplit): ?><a class="button contact-jump" href="#contact-message" data-contact-jump><?= $e($tr('Write a message','Napisz wiadomość')) ?> ↓</a><?php endif; ?>
        <?php if ($section !== 'page'): require __DIR__ . '/hero-visual.php'; endif; ?></div>
        <?php if ($section === 'extensions'): ?><nav class="category-nav" aria-label="<?= $e($tr('Extension categories','Kategorie rozszerzeń')) ?>"><a href="<?= $e($localUrl($categoryHome)) ?>"<?= $publicPath === $categoryHome ? ' aria-current="page"' : '' ?>><?= $e($categoryHome === '/extensions/catalog' ? $tr('All packages','Wszystkie pakiety') : $tr('Overview','Przegląd')) ?></a><?php foreach ($categories as $url=>$label): ?><a href="<?= $e($localUrl($url)) ?>"<?= ($publicPath === $url || str_starts_with($publicPath, $url . '/')) ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a><?php endforeach; ?></nav><?php endif; ?>
    </div>
</section>
<?php if ($publicPath === '/update'): require __DIR__ . '/updates.php'; endif; ?>
<?php if ($marketplace): require __DIR__ . '/marketplace.php'; else: ?>
<div class="container subpage-body subpage-body-<?= $section ?><?= $contactSplit ? ' contact-composed' : '' ?>">
<?php if ($section === 'guide'): ?>
    <aside class="guide-sidebar"><span class="eyebrow"><?= $e($tr('IN THIS COLLECTION','W TEJ KOLEKCJI')) ?></span><nav aria-label="<?= $e($tr('Documentation','Dokumentacja')) ?>"><?php foreach ($docs as $url=>$label): ?><a href="<?= $e($localUrl($url)) ?>"<?= $publicPath === $url ? ' aria-current="page"' : '' ?>><?= $e($label) ?><span aria-hidden="true">↗</span></a><?php endforeach; ?></nav><div class="guide-help"><strong><?= $e($tr('Build with confidence.','Twórz z pewnością.')) ?></strong><p><?= $e($tr('Questions about your implementation?','Masz pytania dotyczące wdrożenia?')) ?></p><a href="<?= $e($localUrl('/contact')) ?>" class="text-link"><?= $e($tr('Talk to Sense CMS →','Skontaktuj się z Sense CMS →')) ?></a></div></aside>
<?php endif; ?>
<div<?= $contactSplit?' id="contact-message"':'' ?> class="subpage-content <?= in_array($section, ['extensions','resources','release'], true) ? 'subpage-cards' : '' ?>">
<?php if ($publicPath==='/docs'): ?>
<section id="first-page" class="docs-quickstart" aria-labelledby="first-page-title"><h2 id="first-page-title"><?= $e($tr('Create your first page','Utwórz pierwszą stronę')) ?></h2>
<ol><li><?= $e($tr('Open Content → Pages and choose New page. Enter a title and save the page as a draft.','Otwórz Treść → Strony i wybierz nową stronę. Wpisz tytuł i zapisz stronę jako szkic.')) ?></li><li><?= $e($tr('Open the page in Page Builder. Add a section, enter your text and check the mobile preview.','Otwórz stronę w Page Builderze. Dodaj sekcję, wpisz tekst i sprawdź podgląd mobilny.')) ?></li><li><?= $e($tr('Save the page content. When it is ready, open the page details to set its publication status and visibility.','Zapisz treść. Kiedy strona będzie gotowa, w jej szczegółach ustaw status publikacji i widoczność.')) ?></li></ol></section>
<section class="docs-quickstart" id="add-extension" aria-labelledby="extension-title"><h2 id="extension-title"><?= $e($tr('Add an extension','Dodaj rozszerzenie')) ?></h2>
<ol><li><?= $e($tr('Open Marketplace in the administration panel and select the package you need.','Otwórz Marketplace w panelu administracyjnym i wybierz potrzebny pakiet.')) ?></li><li><?= $e($tr('Check compatibility, availability and licence requirements in its details. If installation is available for your account, follow the steps shown there.','W szczegółach sprawdź zgodność, dostępność i wymagania licencyjne. Jeśli Twoje konto ma możliwość instalacji, wykonaj kroki pokazane przy pakiecie.')) ?></li></ol>
<a class="text-link" href="<?= $e($localUrl('/docs/packages')) ?>"><?= $e($tr('Read the package specification','Przeczytaj specyfikację pakietów')) ?> →</a></section>
<?php endif; ?>
<?php require __DIR__ . '/blocks.php'; ?>
<?php if ($section === 'guide'): $routes=array_keys($docs); $position=array_search($publicPath,$routes,true); ?><nav class="guide-pagination" aria-label="<?= $e($tr('More documentation','Więcej dokumentacji')) ?>"><?php if ($position !== false && $position > 0): ?><a href="<?= $e($localUrl($routes[$position-1])) ?>"><small><?= $e($tr('PREVIOUS GUIDE','POPRZEDNI PORADNIK')) ?></small><strong>← <?= $e($docs[$routes[$position-1]]) ?></strong></a><?php endif; ?><?php if ($position !== false && isset($routes[$position+1])): ?><a href="<?= $e($localUrl($routes[$position+1])) ?>"><small><?= $e($tr('NEXT GUIDE','NASTĘPNY PORADNIK')) ?></small><strong><?= $e($docs[$routes[$position+1]]) ?> →</strong></a><?php endif; ?></nav><?php endif; ?>
</div>
<?php if ($section === 'contact'): ?><aside class="contact-aside"><span class="eyebrow"><?= $e($tr('A DIRECT CONVERSATION','BEZPOŚREDNI KONTAKT')) ?></span><h2><?= $e($tr('Your next step.','Twój kolejny krok.')) ?><br><?= $e($tr('Our full attention.','Nasza pełna uwaga.')) ?></h2><p><?= $e($tr('Tell us what you are building, what you need and where we can help.','Opowiedz nam o swoim projekcie i potrzebach. Sprawdźmy, jak możemy pomóc.')) ?></p>
<?php if ($contactSplit): ?><div class="contact-details"><?php $blocks=array_values(array_filter($contactBlocks, static fn(array $block): bool => $block['type'] !== 'contact-form')); require __DIR__ . '/blocks.php'; ?></div><?php else: ?><a href="mailto:info@SenseCMS.com" class="contact-address">info@SenseCMS.com <span aria-hidden="true">↗</span></a><?php endif; ?>
<div class="contact-signature"><img src="/theme-assets/logo.svg" width="32" height="36" alt=""><div><strong>Sense CMS</strong><span><?= $e($tr('A clear foundation. A direct conversation.','Solidna podstawa. Bezpośredni kontakt.')) ?></span></div></div></aside><?php endif; ?>
</div>
<?php endif; ?>
<?php $blocks=$contactBlocks; unset($blockIcons, $featuredResource); ?>
<?php if (in_array($publicPath, ['/docs', '/extensions/catalog/system/sensecms'], true)): ?><div class="container"><?php require __DIR__ . '/chatbot.php'; ?></div><?php endif; ?>
<?php if (!in_array($section,['contact','guide'],true)): ?><section class="container subpage-next"><div><span class="eyebrow"><?= $e($tr('LET’S MAKE THE NEXT STEP CLEAR','ZAPLANUJMY KOLEJNY KROK')) ?></span><h2><?= $e($tr('Have a project in mind?','Masz pomysł na projekt?')) ?></h2></div><a class="button" href="<?= $e($localUrl('/contact')) ?>"><?= $e($tr('Start a conversation','Porozmawiajmy')) ?> <span aria-hidden="true">↗</span></a></section><?php endif; ?>
