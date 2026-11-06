<?php

declare(strict_types=1);

require dirname(__DIR__) . '/.cms/source/bootstrap.php';

use App\Core\ConsoleLocale;
use App\Core\Translator;

foreach ([['en', 'en'], ['pl', 'pl'], ['fr', 'en'], ['', 'en'], [null, 'en'], [['pl'], 'en'], ['../pl', 'en']] as [$input, $expected]) {
    if (ConsoleLocale::resolve($input) !== $expected) {
        throw new RuntimeException('Invalid interface language fallback.');
    }
}

$path = dirname(__DIR__) . '/.cms/source/lang';
$english = json_decode((string) file_get_contents($path . '/cms/en.json'), true, 32, JSON_THROW_ON_ERROR);
$polish = json_decode((string) file_get_contents($path . '/cms/pl.json'), true, 32, JSON_THROW_ON_ERROR);
if (array_diff_key($english, $polish) || array_diff_key($polish, $english)) {
    throw new RuntimeException('Interface locale catalogs have different keys.');
}
$translator = new Translator($path);
if ($translator->get('pages', ConsoleLocale::resolve('pl'), true) !== 'Strony'
    || $translator->get('pages', ConsoleLocale::resolve('unknown'), true) !== 'Pages') {
    throw new RuntimeException('Interface translation or fallback failed.');
}

// Render the same record while the editor is working in the other content language.
$renderDashboard = static function (string $language): string {
    $_COOKIE[ConsoleLocale::COOKIE] = $language;
    $interfaceLanguage = ConsoleLocale::resolve($language);
    $uiText = ConsoleLocale::messages();
    $user = ['name' => 'QA'];
    $stats = ['recent_content' => [['id' => 1, 'title' => 'Pages <QA>', 'entity_type' => 'page']]];
    $escape = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $can = static fn(string $permission): bool => true;
    ob_start();
    require dirname(__DIR__) . '/.cms/source/app/Views/console-dashboard.php';
    return ob_get_clean();
};
$_GET['lang'] = 'en';
$polishDashboard = $renderDashboard('pl');
if (!str_contains($polishDashboard, 'Szybkie działania') || !str_contains($polishDashboard, 'Pages &lt;QA&gt;')) {
    throw new RuntimeException('Polish dashboard copy or escaped editorial title is incorrect.');
}
$_GET['lang'] = 'pl';
if (!str_contains($renderDashboard('en'), 'Quick actions')) {
    throw new RuntimeException('The content language unexpectedly changed the interface language.');
}
// A translated provider screen must preserve a connected account's own display name.
$providerView = dirname(__DIR__).'/.plugins/youtube-publisher/views/youtube.php';
$calendarRoot = dirname(__DIR__).'/.addons/calendar';
require_once $calendarRoot.'/src/CalendarI18n.php';
foreach (['pl'=>'Kanały YouTube','en'=>'YouTube channels'] as $locale=>$heading) {
    $_COOKIE[ConsoleLocale::COOKIE] = $locale;
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';
    $uiText = ConsoleLocale::messages();
    $youtubeProvider = ['connections'=>[['id'=>1,'display_name'=>'Pages <QA>','external_account_id'=>'fixture','last_verified_at'=>null]]];
    $csrf = 'fixture';
    ob_start(); require $providerView; $html = ob_get_clean();
    if (!str_contains($html, $heading) || !str_contains($html, 'Pages &lt;QA&gt;')) {
        throw new RuntimeException('Provider heading or escaped account name is incorrect for '.$locale);
    }
    foreach ([1, 2] as $count) {
        $youtubeProvider['connections'] = array_fill(0, $count, $youtubeProvider['connections'][0]);
        ob_start(); require $providerView; $providerHtml = ob_get_clean();
        $countLabel = $locale === 'pl' ? 'Połączone: '.$count : $count.' connected';
        $kindLabel = $locale === 'pl' ? ($count === 1 ? 'Kanał YouTube' : 'Kanały YouTube') : ($count === 1 ? 'YouTube channel' : 'YouTube channels');
        if (!str_contains($providerHtml, '<strong>'.$countLabel.'</strong><small>'.$kindLabel.'</small>')) {
            throw new RuntimeException('Connected-account summary has the wrong language or number.');
        }
    }
    $redirectRules = [['aliases'=>['/old'], 'destination'=>'/Pages/', 'status'=>301]];
    ob_start(); require dirname(__DIR__).'/.plugins/legacy-redirects/views/settings.php'; $redirectHtml = ob_get_clean();
    if (!str_contains($redirectHtml, $locale === 'pl' ? 'Grupa przekierowań 1' : 'Redirect group 1')
        || !str_contains($redirectHtml, 'value="/Pages/"')) {
        throw new RuntimeException('Redirect editor translated a destination or missed a label.');
    }
    $calendar = new \SenseCMS\Calendar\CalendarI18n($calendarRoot,null);
    if ($calendar->locale!==$locale || $calendar->message('Choose a valid category.')!==($locale==='pl'?'Wybierz prawidłową kategorię.':'Choose a valid category.')
        || (new \SenseCMS\Calendar\CalendarI18n($calendarRoot,'de'))->locale!=='de') {
        throw new RuntimeException('Calendar picker, panel default or API error language is incorrect.');
    }
}
unset($_COOKIE[ConsoleLocale::COOKIE], $_GET['lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE']);
echo "PASS EN/PL dashboard, escaped content and provider identity, Calendar preference, explicit override and errors.\n";
