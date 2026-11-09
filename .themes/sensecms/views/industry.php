<?php
declare(strict_types=1);
$industry = (require dirname(__DIR__) . '/industry-pages.php')[$canonicalPath];
$industryCopy = $industry[($locale ?? 'en') === 'pl' ? 'pl' : 'en'];
?>
<section class="industry-hero container">
    <?php $renderBreadcrumb(); ?>
    <div class="industry-intro"><div><span class="eyebrow"><?= $e($industryCopy['label']) ?></span><h1><?= $e($page['title']) ?></h1><p><?= $e($page['description']) ?></p><a class="button inverse" href="<?= $e($localUrl('/contact')) ?>"><?= $e($tr('Let’s plan your website','Zaplanujmy Twoją stronę')) ?> <span aria-hidden="true">→</span></a></div>
    <figure id="industry-example" class="industry-site industry-example">
      <div class="industry-site-bar"><span aria-hidden="true">● ● ●</span><span><?= $e($tr('Example website','Przykładowa strona')) ?></span></div>
      <div class="industry-site-content">
        <span class="industry-brand"><?= $e($industryCopy['brand']) ?></span>
        <div class="industry-illustration industry-landscape-<?= $e($industry['visual']) ?>" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $e($industry['icon']) ?>"/></svg>
        </div>
        <h2><?= $e($industryCopy['screen']) ?></h2>
        <ul><?php foreach ($industryCopy['items'] as $item): ?><li><?= $e($item) ?></li><?php endforeach; ?></ul>
      </div>
      <figcaption><?= $e($tr('Example page layout · content fitted to your organisation','Przykładowy układ strony · treści dopasowane do organizacji')) ?></figcaption>
    </figure></div>
</section>
<div class="container industry-body"><div class="industry-content"><?php require __DIR__ . '/blocks.php'; ?></div><aside class="industry-next"><span class="eyebrow"><?= $e($tr('EXPLORE THE PLATFORM','POZNAJ PLATFORMĘ')) ?></span><h2><?= $e($tr('See how it comes together.','Zobacz, jak to się łączy.')) ?></h2><p><?= $e($tr('Explore the workspace, personalised chatbot and the steps of an implementation. We can host Sense CMS on our servers or work with your infrastructure.','Poznaj panel pracy, spersonalizowanego chatbota i etapy wdrożenia. Możemy utrzymywać Sense CMS na naszych serwerach lub wykorzystać Twoją infrastrukturę.')) ?></p><a class="text-link" href="<?= $e($localUrl('/platform')) ?>#working-together"><?= $e($tr('How we work together →','Jak wygląda współpraca →')) ?></a><a class="text-link" href="<?= $e($localUrl('/platform')) ?>#workspace-tour"><?= $e($tr('Explore the actual CMS screens →','Zobacz prawdziwe ekrany CMS →')) ?></a><a class="text-link" href="<?= $e($localUrl('/platform/ai')) ?>#private-ai-chatbot"><?= $e($tr('Meet your AI chatbot →','Poznaj swojego AI Chatbota →')) ?></a></aside></div>
<section class="container industry-related" aria-label="<?= $e($tr('Other use cases','Inne zastosowania')) ?>"><?php foreach(require dirname(__DIR__) . '/industry-pages.php' as $route=>$related): if($route===$canonicalPath)continue; ?><a href="<?= $e($localUrl($route)) ?>"><?= $e($related[($locale ?? 'en') === 'pl' ? 'pl' : 'en']['label']) ?> <span aria-hidden="true">→</span></a><?php endforeach; ?></section>
