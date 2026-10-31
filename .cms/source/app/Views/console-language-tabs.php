<?php
$localeTabsId = preg_replace('/[^a-z0-9-]/i', '-', (string) ($localeTabsId ?? 'content-locales'));
$availableLocales = array_values(array_filter((array) ($languages ?? []), static fn(array $language): bool => !empty($language['locale'])));
$localeTabsActive = \App\Core\WorkspaceContext::locale($availableLocales, $localeTabsActive ?? ($_GET['lang'] ?? null));
if (!in_array($localeTabsActive, array_column($availableLocales, 'locale'), true)) $localeTabsActive = (string) ($availableLocales[0]['locale'] ?? 'en');
$localeFlags = ['en' => 'gb', 'de' => 'de', 'zh' => 'cn', 'pl' => 'pl', 'km' => 'kh'];
?>
<nav class="sensecms-language-tabs" data-language-tabs="<?= $escape($localeTabsId) ?>" role="tablist" aria-label="Translation language">
<?php foreach($availableLocales as$language):$locale=(string)$language['locale']; ?>
<button type="button" role="tab" tabindex="<?= $locale===$localeTabsActive?'0':'-1' ?>" class="<?= $locale===$localeTabsActive?'is-active':'' ?>" data-language-tab="<?= $escape($locale) ?>" aria-selected="<?= $locale===$localeTabsActive?'true':'false' ?>">
<img src="<?= $escape($language['flag'] ?: '/sensecms/images/flags/'.($localeFlags[$locale] ?? 'gb').'.svg') ?>" alt=""><strong><?= $escape($language['native_name']?:$language['name']) ?></strong><span><?= $escape(mb_strtoupper($locale)) ?></span>
</button>
<?php endforeach; ?>
</nav>
<p class="sensecms-language-hint">Choose the language of translated fields. Other settings apply to every language.</p>
