<?php

declare(strict_types=1);

require dirname(__DIR__) . '/.cms/source/bootstrap.php';

$root = dirname(__DIR__) . '/.cms/source';
$target = dirname(__DIR__) . '/.cms/releases/sensecms-install-0.1.0-dev.zip';
$zip = new ZipArchive();
$temp = null;
try {
    if (file_exists($target)) throw new RuntimeException('Installer archive already exists; do not overwrite a published build.');
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) throw new RuntimeException('Cannot create release directory.');
    $temp = $target . '.' . bin2hex(random_bytes(8));
    if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create installer archive.');
    $files = [];
    foreach (['bootstrap.php', '.htaccess', 'README.md', 'app', 'config', 'database', 'public', 'scripts'] as $entry) {
        $path = $root . '/' . $entry;
        if (is_link($path) || !file_exists($path)) throw new RuntimeException('Invalid installer source.');
        if (is_file($path)) { $files[$entry] = $path; continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isLink() || !$file->isFile() || preg_match('#(^|/)(?:\.[^/]+|storage)(/|$)#', $relative) && basename($relative) !== '.htaccess'
                || !in_array($file->getExtension(), ['php', 'sql', 'css', 'js', 'svg', 'htaccess'], true)) throw new RuntimeException('Unexpected file in installer source: ' . $relative);
            $files[$relative] = $file->getPathname();
        }
    }
    ksort($files);
    foreach ($files as $relative => $path) if (!$zip->addFile($path, $relative)) throw new RuntimeException('Cannot add installer file.');
    if (!$zip->close()) throw new RuntimeException('Cannot finalize installer archive.');
    if (!rename($temp, $target)) throw new RuntimeException('Cannot publish installer archive.');
    $temp = null;
    echo count($files) . ' clean installer files, development build only.' . PHP_EOL;
    echo basename($target) . ' SHA-256 ' . hash_file('sha256', $target) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($temp !== null && is_file($temp)) unlink($temp);
}
