<?php

declare(strict_types=1);

use App\Core\Packages\Archive;
use App\Core\Packages\ThemeManager;
use App\Core\PublicTheme;
use App\Core\Runtime;

require dirname(__DIR__) . '/.cms/source/bootstrap.php';
$source = dirname(__DIR__) . '/.themes/sensecms';
$theme = new PublicTheme($source, 'https://www.sensecms.com');
$pages = require $source . '/pages.php';
$count = 0;
$assert = static function (bool $ok, string $name) use (&$count): void { if (!$ok) throw new RuntimeException('FAIL ' . $name); $count++; echo 'PASS ' . $name . PHP_EOL; };
$reject = static function (callable $call, string $name) use ($assert): void { try { $call(); } catch (RuntimeException $e) { $assert(true, $name); return; } $assert(false, $name); };
foreach ($pages as $path => $page) {
    [$status, $headers, $body] = $theme->response($path);
    $assert($status === 200 && str_contains($body, htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8')), 'page and title ' . $path);
    $assert(str_contains($body, 'rel="canonical" href="https://www.sensecms.com' . $path . '"'), 'canonical ' . $path);
    $assert($theme->response($path, 'HEAD')[2] === '', 'HEAD ' . $path);
    preg_match_all('/(?:href|src)="(\/[^"#]*)(?:#[^"]*)?"/', $body, $links);
    foreach (array_unique($links[1]) as $link) $assert($theme->response($link, 'HEAD')[0] === 200, 'internal link ' . $path . ' -> ' . $link);
}
foreach (['/missing', '/theme-assets/../pages.php', '/theme-assets/pages.php', '/theme-assets/../../.cfg/SSH.txt'] as $path) $assert($theme->response($path)[0] === 404, 'unknown/private path ' . $path);
$assert($theme->response('/', 'POST')[0] === 405, 'read-only public routes');
$assert(!str_contains($theme->response('/download')[2], '.zip'), 'no fake release download');
$assert(str_contains($theme->response('/sitemap.xml')[2], '<loc>https://www.sensecms.com/docs/packages</loc>'), 'sitemap contains docs');
$assert($theme->response('/robots.txt', 'HEAD')[2] === '', 'robots HEAD');
$assert(str_contains($theme->response('/theme-assets/site.css')[1]['Content-Type'], 'text/css'), 'asset MIME');
$assert(str_contains($theme->response('/')[1]['Content-Security-Policy'], "object-src 'none'"), 'public CSP');

$temp = sys_get_temp_dir() . '/sense-theme-test-' . bin2hex(random_bytes(12));
mkdir($temp, 0700); mkdir($temp . '/source', 0700); mkdir($temp . '/config', 0700);
try {
    copy(dirname(__DIR__) . '/.cms/source/config/product.php', $temp . '/config/product.php');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $temp . '/source/' . substr($file->getPathname(), strlen($source) + 1);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        copy($file->getPathname(), $path);
    }
    $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $keys = ['sensecms-release' => sodium_crypto_sign_publickey_from_secretkey($secret)];
    Archive::build($temp . '/source', $temp . '/v1.zip', $secret);
    $runtime = new Runtime($temp); $manager = new ThemeManager($runtime);
    $reject(fn() => $manager->install($temp . '/v1.zip', []), 'unsigned publisher cannot activate');
    $assert($manager->activePath() === null, 'rejected theme leaves no active state');
    $first = $manager->install($temp . '/v1.zip', $keys);
    $assert($first['version'] === '0.1.0', 'signed theme activates');
    $assert((new PublicTheme($manager->activePath(), 'https://www.sensecms.com'))->response('/')[0] === 200, 'installed payload renders homepage');
    $reject(fn() => $manager->install($temp . '/v1.zip', $keys), 'same version install rejected');
    $reject(fn() => $manager->rollback($keys), 'rollback without history rejected');
    $manifest = json_decode((string) file_get_contents($temp . '/source/sense-package.json'), true, 16, JSON_THROW_ON_ERROR);
    $manifest['version'] = '0.2.0'; file_put_contents($temp . '/source/sense-package.json', json_encode($manifest));
    Archive::build($temp . '/source', $temp . '/v2.zip', $secret);
    $second = $manager->install($temp . '/v2.zip', $keys);
    $assert($second['version'] === '0.2.0' && $runtime->read('theme')['previous'] === $first, 'upgrade preserves previous release');
    $reject(fn() => $manager->rollback([]), 'rollback reverifies publisher trust');
    $assert($manager->rollback($keys) === $first, 'rollback restores previous pointer');
    $assert($manager->rollback($keys) === $second, 'rollback can restore newer release');
    $oldFile = $temp . '/storage/themes/' . $first['directory'] . '/payload/pages.php';
    file_put_contents($oldFile, '<?php return [];');
    $reject(fn() => $manager->rollback($keys), 'tampered rollback payload rejected');
    $assert($runtime->read('theme')['active'] === $second, 'failed rollback preserves current website');
    $assert(glob($temp . '/storage/themes/stage-*') === [], 'unpublished staging directories cleaned');
    $runtime->write('theme', ['active' => ['directory' => '../source']]);
    $reject(fn() => $manager->activePath(), 'state path traversal rejected');
    sodium_memzero($secret);
    echo "\n$count theme and website checks passed.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temp);
}
