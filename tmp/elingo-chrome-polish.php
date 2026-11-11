<?php

declare(strict_types=1);

if ($argc !== 2) throw new RuntimeException('Usage: elingo-chrome-polish.php <bootstrap>');

require $argv[1];

$runtime = new App\Core\Runtime(dirname($argv[1]));
$db = App\Core\Runtime::connect($runtime->read('installed')['database']);
$cms = new App\Core\CmsRepository($db, new App\Core\EventBus(), $runtime);
$settings = (array) $cms->setting('site_chrome', App\Core\SiteChrome::defaults());
$cms->saveSetting('site_chrome_backup_elingo_20261111', $settings);

$settings = App\Core\SiteChrome::saveLocale($settings, 'pl', 'header', [
    'utility_text' => 'Learn. Communicate. Grow.',
    'utility_contact_label' => 'Kontakt',
    'utility_contact_url' => '/kontakt',
    'header_cta_label' => 'Zacznij naukę',
    'header_cta_url' => '/kontakt',
]);
$settings = App\Core\SiteChrome::saveLocale($settings, 'pl', 'footer', [
    'footer_description' => 'Your Future Starts with Language.',
    'footer_directions_label' => 'Kontakt',
    'footer_directions_url' => '/kontakt',
    'footer_explore_title' => 'Odkrywaj',
    'footer_connect_title' => 'Kontakt',
    'copyright_line_1' => '© {{year}} Elingo School.',
    'copyright_line_2' => 'Wszelkie prawa zastrzeżone.',
]);
$cms->saveSetting('site_chrome', $settings);

echo "Polish site chrome saved.\n";
