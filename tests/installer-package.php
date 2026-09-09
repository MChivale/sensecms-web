<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/build-installer.php';
set_error_handler(static function (int $level, string $message, string $file, int $line): void {
    if (error_reporting() & $level) throw new ErrorException($message, 0, $level, $file, $line);
});
$temp = sys_get_temp_dir() . '/sense-installer-test-' . bin2hex(random_bytes(12));
mkdir($temp, 0700); $count = 0; $server = null;
$assert = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $count++; echo 'PASS ' . $label . PHP_EOL;
};
$reject = static function (callable $action, string $label) use ($assert): void {
    try { $action(); } catch (RuntimeException | JsonException) { $assert(true, $label); return; }
    $assert(false, $label);
};
$run = static function (array $args): array {
    $process = proc_open($args, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated PHP process.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
try {
    $source = dirname(__DIR__) . '/.cms/source';
    $first = InstallerBuilder::build($source, $temp . '/first.zip');
    $inventory = InstallerBuilder::verify($temp . '/first.zip');
    $assert($first['files'] === count($inventory['files']), 'Built inventory covers every source file');
    foreach ($inventory['files'] as $name => $hash) if (!hash_equals($hash, hash_file('sha256', $source . '/' . $name))) throw new RuntimeException('Source hash mismatch.');
    $assert(true, 'Every packaged file matches the current source');
    foreach (['lang/en.json', 'lang/cms/en.json', 'config/page-builder-layouts.json', 'public/theme/sensecms-themes.js', 'public/theme/sensecms-themes.css', 'app/Installer/WorkspaceMigration.php'] as $name) $assert(isset($inventory['files'][$name]), 'Required Workspace resource included: ' . $name);
    $assert(!preg_grep('#^(storage|themes|plugins|addons|modules|\.cfg|tests)/#', array_keys($inventory['files'])), 'Runtime state and optional packages stay outside distribution');
    $again = InstallerBuilder::build($source, $temp . '/again.zip');
    $assert($first['sha256'] === $again['sha256'], 'Same source produces byte-identical ZIPs');
    $reject(fn() => InstallerBuilder::build($source, $temp . '/first.zip'), 'Existing release cannot be overwritten');
    $assert(hash_file('sha256', $temp . '/first.zip') === $first['sha256'], 'Rejected overwrite preserves existing release bytes');
    [$code, $out, $err] = $run([PHP_BINARY, dirname(__DIR__) . '/scripts/build-installer.php', $temp . '/first.zip']);
    $assert($code !== 0 && $out === '' && str_contains($err, 'must not already exist'), 'CLI reports a failed overwrite instead of a successful build');
    $zip = new ZipArchive(); $zip->open($temp . '/first.zip');
    mkdir($temp . '/source', 0700); $zip->extractTo($temp . '/source'); $zip->close();
    unlink($temp . '/source/installer-manifest.json');
    $fixture = $temp . '/source';
    mkdir($fixture . '/storage'); file_put_contents($fixture . '/storage/installed.json', '{"private":"NEVER-SHIP-RUNTIME"}');
    mkdir($fixture . '/.cfg'); file_put_contents($fixture . '/.cfg/License.txt', 'NEVER-SHIP-CREDENTIALS');
    InstallerBuilder::build($fixture, $temp . '/isolated.zip');
    $assert(InstallerBuilder::verify($temp . '/isolated.zip')['files'] === $inventory['files'], 'Root private data is never scanned into the archive');
    unlink($fixture . '/storage/installed.json');
    $reject(fn() => InstallerBuilder::build($fixture, $fixture . '/embedded.zip'), 'Build output inside source is rejected');
    foreach (['public/diagnostic.php'=>'<?php echo 1;', 'config/.env'=>'PRIVATE', 'config/local.pem'=>'PRIVATE', 'lang/broken.json'=>'{', 'app/key.php'=>"<?php // -----BEGIN PRIVATE KEY-----"] as $name => $contents) {
        file_put_contents($fixture . '/' . $name, $contents);
        try { $reject(fn() => InstallerBuilder::build($fixture, $temp . '/rejected.zip'), 'Unsafe or malformed source rejected: ' . $name); }
        finally { unlink($fixture . '/' . $name); }
        $assert(!file_exists($temp . '/rejected.zip'), 'Rejected source leaves no published artifact: ' . $name);
    }
    mkdir($fixture . '/public/.private'); file_put_contents($fixture . '/public/.private/.htaccess', 'Deny from all');
    $reject(fn() => InstallerBuilder::build($fixture, $temp . '/rejected.zip'), 'Nested hidden directory cannot bypass the htaccess exception');
    unlink($fixture . '/public/.private/.htaccess'); rmdir($fixture . '/public/.private');
    if (PHP_OS_FAMILY !== 'Windows') {
        symlink($fixture . '/lang', $fixture . '/app/linked');
        try { $reject(fn() => InstallerBuilder::build($fixture, $temp . '/rejected.zip'), 'Linked source directory is rejected before traversal'); }
        finally { unlink($fixture . '/app/linked'); }
    }
    $mutate = static function (callable $change) use ($temp): void {
        copy($temp . '/first.zip', $temp . '/mutated.zip'); $zip = new ZipArchive(); $zip->open($temp . '/mutated.zip');
        try { $change($zip); } finally { $zip->close(); }
        InstallerBuilder::verify($temp . '/mutated.zip');
    };
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->addFromString('bootstrap.php', '<?php // modified')), 'Modified payload is rejected');
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->deleteName('lang/cms/en.json')), 'Missing translation is rejected');
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->addFromString('storage/license.json', '{}')), 'Private archive entry is rejected');
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->addFromString('public/extra.js', '')), 'Unlisted archive file is rejected');
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->addFromString('public/../outside.js', '')), 'Archive traversal path is rejected');
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->addFromString('public/INDEX.php', '<?php')), 'Case-colliding public entry is rejected');
    $reject(fn() => $mutate(fn(ZipArchive $zip) => $zip->setExternalAttributesName('bootstrap.php', ZipArchive::OPSYS_UNIX, 0120777 << 16)), 'Archive symbolic link attributes are rejected');
    $reject(fn() => $mutate(static function (ZipArchive $zip): void {
        $manifest = json_decode($zip->getFromName('installer-manifest.json'), true);
        $manifest['channel'] = 'stable';
        $zip->addFromString('installer-manifest.json', json_encode($manifest));
    }), 'Development inventory cannot silently claim stable status');
    $reject(fn() => $mutate(static function (ZipArchive $zip): void {
        $manifest = json_decode($zip->getFromName('installer-manifest.json'), true);
        unset($manifest['files']['lang/cms/en.json']);
        $zip->deleteName('lang/cms/en.json');
        $zip->addFromString('installer-manifest.json', json_encode($manifest));
    }), 'Required translations cannot be removed even with a matching inventory');
    $assert(glob($temp . '/.sense-installer-*') === [], 'Temporary build artifacts are cleaned after failures');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') continue;
        [$code] = $run([PHP_BINARY, '-l', $file->getPathname()]);
        if ($code !== 0) throw new RuntimeException('Extracted PHP file failed lint.');
    }
    $assert(true, 'All extracted PHP files pass lint');
    [$code] = $run([PHP_BINARY, $fixture . '/scripts/prepare.php', 'https://www.sensecms.com']);
    $assert($code === 0 && is_file($fixture . '/storage/setup.json'), 'Extracted operator preparation creates private setup');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot allocate loopback port.');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = getenv(); $env['SENSE_LOCAL_HTTP'] = '1';
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $fixture . '/public', $fixture . '/public/index.php'], [0=>['pipe','r'], 1=>['file',$temp . '/http.log','a'], 2=>['file',$temp . '/http.log','a']], $pipes, $fixture, $env);
    if (!is_resource($server)) throw new RuntimeException('Cannot start isolated installer.');
    fclose($pipes[0]);
    $context = stream_context_create(['http'=>['timeout'=>2, 'ignore_errors'=>true, 'follow_location'=>0]]); $body = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $body = @file_get_contents('http://' . $address . '/install', false, $context);
        if ($body !== false) break;
        usleep(100000);
    }
    $assert(is_string($body) && str_contains($body, 'Activate your license') && str_contains($body, 'name="license_key"'), 'Extracted installer starts with real license form');
    $assert(!str_contains($body, 'name="db_host"') && !str_contains($body, 'name="admin_email"'), 'Database and owner remain hidden before licensing');
    $headers = http_get_last_response_headers() ?? [];
    $assert((bool) preg_grep('/no-store/', $headers), 'Extracted installer response is not cacheable');
    $assert(!file_exists($fixture . '/storage/installed.json') && !file_exists($fixture . '/storage/workspace.json'), 'Packaging and boot do not fabricate installed or enabled Workspace state');
    $assert(!preg_match('/PHP (?:Warning|Fatal|Parse|Notice)|Uncaught/', (string) file_get_contents($temp . '/http.log')), 'Extracted installer boot has no PHP warnings or fatal errors');
    echo $count . ' installer distribution checks passed.' . PHP_EOL;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    // Delete only the unique test-owned temporary directory, never the project source.
    if (realpath($temp) !== false && preg_match('/^sense-installer-test-[a-f0-9]{24}$/D', basename($temp))) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
        }
        rmdir($temp);
    }
}
