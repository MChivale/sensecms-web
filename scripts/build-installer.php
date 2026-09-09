<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/.cms/source/bootstrap.php';

use App\Core\Packages\Manifest;

/** Development distribution only; inventory checks are not publisher authentication. */
final class InstallerBuilder
{
    private const ENTRIES = ['bootstrap.php', '.htaccess', 'README.md', 'app', 'config', 'database', 'lang', 'public', 'scripts'];
    private const REQUIRED = ['bootstrap.php', '.htaccess', 'README.md', 'public/.htaccess', 'public/index.php', 'app/workspace.php', 'app/Installer/Installer.php', 'app/Installer/WorkspaceMigration.php', 'config/product.php', 'config/workspace.php', 'config/page-builder-layouts.json', 'database/001_core.sql', 'lang/cms/en.json', 'scripts/prepare.php', 'scripts/migrate-workspace.php'];

    public static function build(string $root, string $target): array
    {
        if (is_link($root) || !($root = realpath($root)) || !is_dir($root)) throw new RuntimeException('Invalid installer source.');
        $parent = realpath(dirname($target));
        if (!$parent || is_link($target) || file_exists($target)) throw new RuntimeException('Output directory must exist and installer archive must not already exist.');
        $target = $parent . '/' . basename($target);
        $prefix = strtolower(str_replace('\\', '/', $root) . '/');
        if (str_starts_with(strtolower(str_replace('\\', '/', $target)), $prefix)) throw new RuntimeException('Installer output must be outside its source.');
        $files = []; $seen = []; $size = 0;
        foreach (self::ENTRIES as $entry) {
            $path = $root . '/' . $entry;
            if (is_link($path) || !file_exists($path)) throw new RuntimeException('Missing or linked installer source: ' . $entry);
            $items = is_dir($path) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) : [new SplFileInfo($path)];
            foreach ($items as $file) {
                if ($file->isLink() || !($resolved = $file->getRealPath()) || !str_starts_with(strtolower(str_replace('\\', '/', $resolved)), $prefix)) throw new RuntimeException('Installer source link or path escape.');
                $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (!in_array($name, ['.htaccess', 'public/.htaccess'], true)) Manifest::path($name);
                if ($file->isDir()) continue;
                if (!$file->isFile()) throw new RuntimeException('Installer source must contain regular files.');
                self::filePath($name); Manifest::uniquePath($name, $seen);
                $size += $file->getSize();
                if ($file->getSize() > 10485760 || $size > 104857600 || count($files) >= 2000) throw new RuntimeException('Installer source exceeds distribution limits.');
                $files[$name] = $resolved;
            }
        }
        foreach (self::REQUIRED as $name) if (!isset($files[$name])) throw new RuntimeException('Required installer file is missing: ' . $name);
        ksort($files, SORT_STRING);
        $temp = $parent . '/.sense-installer-' . bin2hex(random_bytes(12));
        $zip = new ZipArchive(); $opened = false;
        try {
            if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create installer archive.');
            $opened = true;
            $inventory = ['format'=>1, 'product'=>'Sense CMS', 'channel'=>'development', 'workspace_enabled_by_default'=>true, 'files'=>[]];
            foreach ($files as $name => $path) {
                $contents = file_get_contents($path);
                if (!is_string($contents) || strlen($contents) > 10485760) throw new RuntimeException('Cannot read installer source.');
                if (pathinfo($name, PATHINFO_EXTENSION) === 'json') json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
                if (preg_match('/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----|\bsk-proj-[A-Za-z0-9_-]{20,}/', $contents)) throw new RuntimeException('Credential material detected in installer source.');
                $inventory['files'][$name] = hash('sha256', $contents);
                self::add($zip, $name, $contents);
            }
            self::add($zip, 'installer-manifest.json', json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) throw new RuntimeException('Cannot finalize installer archive.');
            $opened = false;
            self::verify($temp);
            // Same mechanism as signed package builds: atomic publication, never replacement.
            if (!@link($temp, $target)) throw new RuntimeException('Cannot publish immutable installer; target may already exist.');
            return ['files'=>count($files), 'bytes'=>filesize($target), 'sha256'=>hash_file('sha256', $target)];
        } finally {
            if ($opened) $zip->close();
            if (is_file($temp)) unlink($temp);
        }
    }

    public static function verify(string $path): array
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 104857600) throw new RuntimeException('Invalid installer archive.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('Invalid installer ZIP.');
        try {
            if ($zip->numFiles < count(self::REQUIRED) + 1 || $zip->numFiles > 2001) throw new RuntimeException('Invalid installer file count.');
            $seen = []; $hashes = []; $size = 0; $inventory = null;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (!$entry) throw new RuntimeException('Cannot inspect installer entry.');
                $name = $entry['name']; self::filePath($name, true); Manifest::uniquePath($name, $seen);
                $size += $entry['size'];
                if ($entry['size'] > 10485760 || $size > 104857600 || ($entry['encryption_method'] ?? 0) !== 0) throw new RuntimeException('Invalid installer entry size or encryption.');
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes) || ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) !== 0100000)) throw new RuntimeException('Non-regular installer entry.');
                $contents = $zip->getFromIndex($index);
                if (!is_string($contents)) throw new RuntimeException('Cannot read installer entry.');
                if ($name === 'installer-manifest.json') $inventory = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
                else $hashes[$name] = hash('sha256', $contents);
            }
            if (!is_array($inventory) || ($inventory['format'] ?? null) !== 1 || ($inventory['product'] ?? '') !== 'Sense CMS' || ($inventory['channel'] ?? '') !== 'development' || ($inventory['workspace_enabled_by_default'] ?? null) !== true || !is_array($inventory['files'] ?? null)) throw new RuntimeException('Invalid development installer inventory.');
            ksort($hashes); ksort($inventory['files']);
            if ($hashes !== $inventory['files']) throw new RuntimeException('Installer file inventory mismatch.');
            foreach (self::REQUIRED as $name) if (!isset($hashes[$name])) throw new RuntimeException('Required installer file is missing: ' . $name);
            return $inventory;
        } finally { $zip->close(); }
    }

    private static function filePath(string $name, bool $metadata = false): void
    {
        if (in_array($name, ['.htaccess', 'public/.htaccess'], true)) return;
        Manifest::path($name);
        if (in_array($name, ['bootstrap.php', 'README.md'], true) || $metadata && $name === 'installer-manifest.json') return;
        $top = explode('/', $name)[0];
        $allowed = match ($top) {
            'app', 'scripts' => ['php'], 'config' => ['php', 'json'], 'database' => ['sql'], 'lang' => ['json'],
            'public' => ['css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'ico', 'webp', 'mp3', 'woff', 'woff2'], default => [],
        };
        if ($name === 'public/index.php') return;
        if (!in_array(pathinfo($name, PATHINFO_EXTENSION), $allowed, true)) throw new RuntimeException('Unexpected installer file: ' . $name);
    }

    private static function add(ZipArchive $zip, string $name, string $contents): void
    {
        if (!$zip->addFromString($name, $contents) || !$zip->setMtimeName($name, 946684800) || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) throw new RuntimeException('Cannot add installer entry.');
    }

    public static function web(string $root, string $directory): array
    {
        if (is_link($directory) || !is_dir($directory) || count(scandir($directory)) !== 2) throw new RuntimeException('Web installer output must be an existing empty directory.');
        $result = self::build($root, $directory . '/install.zip');
        $inventory = self::verify($directory . '/install.zip')['files'];
        $zip = new ZipArchive();
        if ($zip->open($directory . '/install.zip', ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot open generated installer.');
        $inventory['installer-manifest.json'] = hash('sha256', $zip->getFromName('installer-manifest.json'));
        $zip->close();
        $template = file_get_contents(__DIR__ . '/web-installer.php');
        $bootstrap = str_replace(['__SENSE_ARCHIVE_HASH__', '__SENSE_INVENTORY__', '__SENSE_LOGO__'],
            [$result['sha256'], var_export($inventory, true), base64_encode((string) file_get_contents($root . '/public/assets/logo.svg'))], $template);
        $handle = fopen($directory . '/index.php', 'xb');
        if (!$handle) throw new RuntimeException('Cannot publish web bootstrap.');
        try { if (fwrite($handle, $bootstrap) !== strlen($bootstrap)) throw new RuntimeException('Cannot write web bootstrap.'); }
        finally { fclose($handle); }
        return $result;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    try {
        if ($argc > 2) throw new RuntimeException('Usage: php scripts/build-installer.php [new-development-output.zip]');
        if (($argv[1] ?? '') === '--web') {
            $directory = dirname(__DIR__) . '/.install/web';
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('Cannot create web installer output.');
            $result = InstallerBuilder::web(dirname(__DIR__) . '/.cms/source', $directory);
            echo 'Web bootstrap and development install.zip created; SHA-256 ' . $result['sha256'] . PHP_EOL;
            exit;
        }
        $target = $argv[1] ?? dirname(__DIR__) . '/.cms/releases/sensecms-install-0.1.0-workspace-dev.zip';
        if ($argc === 1 && !is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) throw new RuntimeException('Cannot create development release directory.');
        $result = InstallerBuilder::build(dirname(__DIR__) . '/.cms/source', $target);
        echo $result['files'] . ' inventoried installer files; unsigned development build with fresh Workspace installation.' . PHP_EOL;
        echo basename($target) . ' SHA-256 ' . $result['sha256'] . PHP_EOL;
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); }
}
