<section class="container update-center" data-update-center aria-label="<?= $e($tr('Sense CMS updates','Aktualizacje Sense CMS')) ?>">
    <div class="update-release-heading"><div><span class="eyebrow"><?= $e($tr('THE STABLE CHANNEL','KANAŁ STABILNY')) ?></span><h2><?= $e($tr('Latest Stable releases.','Najnowsze stabilne wydania.')) ?></h2></div><a class="text-link" href="<?= $e($localUrl('/docs/installation')) ?>"><?= $e($tr('Deployment guidance →','Wskazówki dotyczące wdrożenia →')) ?></a></div>
    <div data-stable-releases aria-live="polite"><p><?= $e($tr('Loading verified release information…','Wczytywanie informacji o wydaniach…')) ?></p></div>
    <noscript><p><?= $e($tr('Enable JavaScript to load the current release catalogue. You can also check directly in your CMS under System → Update.','Włącz JavaScript, aby wczytać katalog wydań. Możesz też sprawdzić go w CMS w sekcji System → Aktualizacja.')) ?></p></noscript>
</section>
<script src="/theme-assets/updates.js?v=20260909-2" defer></script>
