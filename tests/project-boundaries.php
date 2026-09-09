<?php

declare(strict_types=1);

// Offline repository checks. No credentials, database or other checkout required.
$root = dirname(__DIR__); $count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $count++; echo 'PASS ' . $label . PHP_EOL;
};
$product = require $root . '/.cms/source/config/product.php';
$check($product['name'] === 'Sense CMS', 'Canonical product is Sense CMS');
$check($product['license']['product_name'] === 'Sense CMS' && $product['license']['product_model'] === 'Sense CMS System' && $product['license']['product_version'] === '1.0', 'Licence identity is preserved');
$check(!file_exists($root . '/scripts/import-workspace.php'), 'Historical external importer is not active tooling');
$check(is_file($root . '/AGENTS.md') && is_file($root . '/.info'), 'New project has self-contained engineering context');
$forbidden = ['f:/git/chivalegroup/eduvixo-cms', 'f:/git/mchivale/cambojumbo-web', 'f:/git/quant-software-house/shoudu-web'];
$files = 0;
foreach (['.cms/source/app', '.cms/source/config', '.cms/source/database', '.cms/source/lang', '.cms/source/public', '.cms/source/scripts', '.src', '.themes', '.plugins', '.addons', '.modules', 'web/public', 'scripts', 'tests', 'deploy'] as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path) || is_link($path)) throw new RuntimeException('Missing or linked source directory: ' . $directory);
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($entries as $file) {
        if ($file->isLink()) throw new RuntimeException('External source links are not allowed.');
        if (!$file->isFile() || $file->getRealPath() === realpath(__FILE__) || !in_array(strtolower($file->getExtension()), ['php', 'js', 'cjs', 'css', 'json', 'py', 'ps1', 'sql', 'conf'], true)) continue;
        $text = strtolower(str_replace('\\', '/', (string) file_get_contents($file->getPathname())));
        foreach ($forbidden as $external) if (str_contains($text, $external)) throw new RuntimeException('Cross-project source dependency: ' . $file->getFilename());
        $files++;
    }
}
$check($files > 100, 'Active source/tooling has no links or hardcoded sibling-repository paths');
$ignore = (string) file_get_contents($root . '/.gitignore');
foreach (['/.cfg/', '/.cms/source/storage/', '/web/storage/', '/.local/', '/.install/web/'] as $entry) $check(in_array($entry, preg_split('/\R/', $ignore), true), 'Private/generated path ignored: ' . $entry);
if (is_file($root . '/.install/web/install.zip')) {
    // Optional local-only secret scan: never output values or add them to fixtures.
    $secrets = [];
    foreach (['License.txt', 'Package-licenses.txt', 'Telegram.txt'] as $name) {
        if (!is_file($root . '/.cfg/' . $name)) continue;
        preg_match_all('/\b[A-Za-z0-9]{32}\b|\b[0-9]{5,}:[A-Za-z0-9_-]{20,}/', (string) file_get_contents($root . '/.cfg/' . $name), $matches);
        $secrets = array_merge($secrets, $matches[0]);
    }
    $zip = new ZipArchive();
    if ($zip->open($root . '/.install/web/install.zip', ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot inspect installer.');
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $body = $zip->getFromIndex($i);
            if (!is_string($body)) throw new RuntimeException('Cannot read installer entry.');
            foreach ($secrets as $secret) if (str_contains($body, $secret)) throw new RuntimeException('Private credential detected in generated installer.');
            if (preg_match('/eduvixo|cambo.?jumbo/i', $body)) throw new RuntimeException('Foreign product branding detected in portable Core.');
        }
    } finally { $zip->close(); unset($secrets, $matches, $secret); }
    $check(true, 'Generated installer contains no old product branding or locally available licence/bot secrets');
}
echo $count . ' standalone-project checks passed; ' . $files . ' source files inspected.' . PHP_EOL;
