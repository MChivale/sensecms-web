<?php

declare(strict_types=1);

require dirname(__DIR__) . '/.cms/source/bootstrap.php';

$theme = dirname(__DIR__) . '/.themes/school';
$public = new App\Core\PublicTheme($theme, 'https://school.example');
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
};

[$status, , $html] = $public->response('/', 'GET');
$assert($status === 200, 'Language School starter route renders');
foreach (['elingo-logo-header.svg', 'elingo-logo-footer.svg', 'elingo-favicon.ico', 'elingo-online-school.jpg', 'elingo-native-speaker.jpg', 'school-hero', 'school-admissions'] as $expected) {
    $assert(str_contains($html, $expected), 'Language School output includes ' . $expected);
}
$assert(!str_contains(strtolower($html), 'shoudu'), 'Starter output contains no Shoudu branding');
$assert(!str_contains($html, 'School Theme'), 'Starter output contains no previous theme name');
$assert(!str_contains($html, 'school-popup'), 'Language School does not show the school admissions popup');
$assert(str_contains($html, '<html lang="pl">') && str_contains($html, 'Otwórz się na świat dzięki językom!'), 'Language School renders Polish starter copy');
[$status, $headers, $body] = $public->response('/theme-assets/elingo-favicon.ico', 'HEAD');
$assert($status === 200 && ($headers['Content-Type'] ?? '') === 'image/x-icon' && $body === '', 'Bundled Elingo favicon is served safely');
foreach (['elingo-logo-header.svg', 'elingo-logo-footer.svg'] as $asset) {
    [$status, $headers] = $public->response('/theme-assets/images/' . $asset, 'HEAD');
    $assert($status === 200 && ($headers['Content-Type'] ?? '') === 'image/svg+xml', 'Bundled Elingo logo is served: ' . $asset);
}
echo "$checks Language School checks passed.\n";
