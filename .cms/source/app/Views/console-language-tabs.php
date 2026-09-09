<?php
$localeTabsId = preg_replace('/[^a-z0-9-]/i', '-', (string) ($localeTabsId ?? 'content-locales'));
$availableLocales = array_values(array_filter((array) ($languages ?? []), static fn(array $language): bool => !empty($language['locale'])));
$localeTabsActive = (string) ($localeTabsActive ?? ($_GET['lang'] ?? ($availableLocales[0]['locale'] ?? 'en')));
if (!in_array($localeTabsActive, array_column($availableLocales, 'locale'), true)) $localeTabsActive = (string) ($availableLocales[0]['locale'] ?? 'en');
$localeFlags = ['en' => 'gb', 'de' => 'de', 'zh' => 'cn', 'pl' => 'pl', 'km' => 'kh'];
?>
<nav class="sensecms-language-tabs" data-language-tabs="<?= $escape($localeTabsId) ?>" aria-label="Content language">
<?php foreach($availableLocales as$language):$locale=(string)$language['locale']; ?>
<button type="button" class="<?= $locale===$localeTabsActive?'is-active':'' ?>" data-language-tab="<?= $escape($locale) ?>" aria-selected="<?= $locale===$localeTabsActive?'true':'false' ?>">
<img src="<?= $escape($language['flag'] ?: '/sensecms/images/flags/'.($localeFlags[$locale] ?? 'gb').'.svg') ?>" alt=""><strong><?= $escape($language['native_name']?:$language['name']) ?></strong><span><?= $escape(mb_strtoupper($locale)) ?></span>
</button>
<?php endforeach; ?>
</nav>
