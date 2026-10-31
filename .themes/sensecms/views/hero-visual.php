<?php
declare(strict_types=1);
$heroTopics = match (true) {
    $canonicalPath === '/platform/ai' => [['Your materials','Twoje materiały'],['Your knowledge base','Twoja Baza Wiedzy'],['Your AI assistant','Twój asystent AI']],
    str_starts_with($canonicalPath, '/docs') => [['Prepare your setup','Przygotuj środowisko'],['Build your website','Zbuduj swoją stronę'],['Keep it growing','Rozwijaj możliwości']],
    str_starts_with($canonicalPath, '/extensions') => [['Shape the website','Zaprojektuj witrynę'],['Add everyday tools','Dodaj narzędzia pracy'],['Connect your services','Połącz swoje usługi']],
    $canonicalPath === '/contact' => [['Your organisation','Twoja organizacja'],['Your requirements','Twoje potrzeby'],['Our next conversation','Nasza rozmowa']],
    in_array($canonicalPath, ['/download','/update'], true) => [['Review availability','Sprawdź dostępność'],['Prepare your environment','Przygotuj środowisko'],['Verify your deployment','Zweryfikuj wdrożenie']],
    default => [['Your content','Twoje treści'],['Your team','Twój zespół'],['Your digital presence','Twoja obecność w sieci']],
};
$heroIcons = ['M5 3h10l4 4v14H5zM14 3v5h5M8 12h8M8 16h6','M9 4h6v6H9zM3 16h6v5H3zM15 16h6v5h-6zM12 10v3M6 16v-3h12v3','M3 4h18v16H3zM3 9h18M7 6.5h.01M10 6.5h.01M7 13h4M7 16h10'];
?>
<div class="hero-visual" aria-label="<?= $e($tr('A connected Sense CMS experience', 'Połączone możliwości Sense CMS')) ?>">
    <div class="hero-visual-brand"><img src="/theme-assets/logo-light.svg" alt="" width="32" height="36"><span>Sense CMS<small><?= $e($tr('YOUR DIGITAL WORKSPACE', 'TWOJA CYFROWA PRZESTRZEŃ')) ?></small></span></div>
    <div class="hero-visual-path">
    <?php foreach ($heroTopics as $index => [$en,$pl]): ?>
        <div class="hero-visual-step"><span class="hero-visual-icon"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $heroIcons[$index] ?>"/></svg></span><strong><?= $e($tr($en,$pl)) ?></strong><span class="hero-visual-index" aria-hidden="true">0<?= $index + 1 ?></span></div>
    <?php endforeach; ?>
    </div>
    <div class="hero-visual-caption"><span aria-hidden="true">↗</span> <?= $e($tr('One foundation. Your possibilities.', 'Jedna podstawa. Twoje możliwości.')) ?></div>
</div>
