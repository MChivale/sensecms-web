<?php

declare(strict_types=1);

// Build template. Generated index.php pins the archive AND every extracted file.
const ARCHIVE_HASH = '__SENSE_ARCHIVE_HASH__';
const INVENTORY = __SENSE_INVENTORY__;
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$nonce = base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; img-src data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$error = null;
$reply = static function (array $data, int $code = 200): never {
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR); exit;
};
try {
    $public = __DIR__; $root = dirname($public); $work = $root . '/.sense-bootstrap';
    $local = getenv('SENSE_LOCAL_HTTP') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && preg_match('/^(?:localhost|127\.0\.0\.1)(?::[0-9]+)?$/D', $_SERVER['HTTP_HOST'] ?? '');
    if (PHP_VERSION_ID < 80500 || !extension_loaded('zip')) throw new RuntimeException('PHP 8.5+ with the ZIP extension is required.');
    if (!$local && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) throw new RuntimeException('Open this installer over HTTPS.');
    if (basename($public) !== 'public' || is_link($public) || realpath($_SERVER['DOCUMENT_ROOT'] ?? '') !== realpath($public)) throw new RuntimeException('Upload index.php and install.zip to an empty public directory and set the domain document root to that directory.');
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if ($local) $host = 'localhost';
    if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) || strlen($host) > 253) throw new RuntimeException('Use the canonical domain without a port.');
    if (is_file($root . '/storage/installed.json')) throw new RuntimeException('An installed CMS already exists. This bootstrap cannot update it.');
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD', 'POST'], true)) throw new RuntimeException('Unsupported request method.');
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_name('sense_bootstrap');
    session_set_cookie_params(['secure' => !$local, 'httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    if ($post) {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $reply(['message' => 'Session expired. Reload this page.'], 419);
        if (($_POST['action'] ?? '') !== 'extract') throw new RuntimeException('Unsupported installer action.');
        if (is_link($work) || is_link($public . '/install.zip')) throw new RuntimeException('Linked installer paths are not allowed.');
        if (!is_dir($work)) {
            foreach (new DirectoryIterator($root) as $file) if (!$file->isDot() && $file->getFilename() !== 'public') throw new RuntimeException('The installation directory is not empty. Existing files will not be overwritten.');
            foreach (new DirectoryIterator($public) as $file) if (!$file->isDot() && !in_array($file->getFilename(), ['index.php', 'install.zip'], true)) throw new RuntimeException('The public directory must contain only index.php and install.zip.');
            if (!mkdir($work, 0700)) throw new RuntimeException('Cannot create private extraction workspace. Check parent directory permissions.');
        }
        if (is_link($work . '/lock') || is_link($work . '/state.json')) throw new RuntimeException('Invalid extraction workspace.');
        $lock = fopen($work . '/lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another extraction request is running. Try again.');
        $statePath = $work . '/state.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 16, JSON_THROW_ON_ERROR) : null;
        $owner = hash('sha256', session_id());
        $save = static function (array $data) use ($statePath): void {
            $raw = json_encode($data, JSON_THROW_ON_ERROR);
            if (file_put_contents($statePath . '.tmp', $raw, LOCK_EX) !== strlen($raw) || !chmod($statePath . '.tmp', 0600) || !rename($statePath . '.tmp', $statePath)) throw new RuntimeException('Cannot persist extraction progress.');
        };
        if (!$state) {
            $state = ['owner' => $owner, 'host' => $host, 'hash' => ARCHIVE_HASH, 'next' => 0, 'phase' => 'extract'];
            $save($state);
        }
        if ($state['owner'] !== $owner || $state['host'] !== $host || $state['hash'] !== ARCHIVE_HASH) throw new RuntimeException('This extraction belongs to another browser session or domain. Resume from the original browser.');
        if ($state['phase'] === 'complete') $reply(['progress' => 100, 'redirect' => '/install']);
        $archive = $public . '/install.zip';
        if (!is_file($archive) || !hash_equals(ARCHIVE_HASH, (string) hash_file('sha256', $archive))) throw new RuntimeException('Archive checksum mismatch. Upload the matching original install.zip.');
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true || $zip->numFiles !== count(INVENTORY)) throw new RuntimeException('Invalid installer archive.');
        $stage = $work . '/payload';
        if (!is_dir($stage) && !mkdir($stage, 0700)) throw new RuntimeException('Cannot create extraction workspace.');
        $names = array_keys(INVENTORY);
        $end = min(count($names), $state['next'] + 40);
        for ($i = $state['next']; $i < $end; $i++) {
            $name = $names[$i]; $dest = $stage . '/' . $name;
            $contents = $zip->getFromName($name);
            if (!is_string($contents) || !hash_equals(INVENTORY[$name], hash('sha256', $contents))) throw new RuntimeException('An extracted file failed integrity verification.');
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) throw new RuntimeException('Cannot create a package directory.');
            // Retry an interrupted batch only if its existing files still match.
            if (is_link($dest) || file_exists($dest) && !hash_equals(INVENTORY[$name], (string) hash_file('sha256', $dest))) throw new RuntimeException('An extraction destination was modified.');
            if (!file_exists($dest)) {
                $out = fopen($dest, 'xb');
                if (!$out) throw new RuntimeException('Cannot create an extracted file.');
                try { if (fwrite($out, $contents) !== strlen($contents)) throw new RuntimeException('Insufficient space while extracting.'); }
                finally { fclose($out); }
                chmod($dest, 0644);
            }
        }
        $zip->close(); $state['next'] = $end; $save($state);
        if ($end < count($names)) $reply(['progress' => (int) floor(90 * $end / count($names)), 'files' => $end, 'total' => count($names)]);
        // Verify all staged/published files before final activation, including resumed publication.
        foreach (INVENTORY as $name => $hash) {
            $path = is_file($stage . '/' . $name) ? $stage . '/' . $name : $root . '/' . $name;
            if (is_link($path) || !is_file($path) || !hash_equals($hash, (string) hash_file('sha256', $path))) throw new RuntimeException('Final file verification failed.');
        }
        if ($state['phase'] === 'extract') {
            foreach (new DirectoryIterator($root) as $file) if (!$file->isDot() && !in_array($file->getFilename(), ['public', '.sense-bootstrap'], true)) throw new RuntimeException('The destination changed during extraction. Nothing will be overwritten.');
            $state['phase'] = 'publish'; $save($state);
        }
        // Rename complete top-level entries; the running bootstrap is replaced LAST.
        foreach (new DirectoryIterator($stage) as $file) {
            if ($file->isDot() || $file->getFilename() === 'public') continue;
            $dest = $root . '/' . $file->getFilename();
            if (file_exists($dest) || is_link($dest) || !rename($file->getPathname(), $dest)) throw new RuntimeException('Cannot publish Core without overwriting existing files.');
        }
        foreach (new DirectoryIterator($stage . '/public') as $file) {
            if ($file->isDot() || $file->getFilename() === 'index.php') continue;
            $dest = $public . '/' . $file->getFilename();
            if (file_exists($dest) || is_link($dest) || !rename($file->getPathname(), $dest)) throw new RuntimeException('Cannot publish public assets safely.');
        }
        require_once $root . '/bootstrap.php';
        $runtime = new App\Core\Runtime($root);
        if ($runtime->read('installed') || $runtime->read('installing')) throw new RuntimeException('An installation has already started.');
        $runtime->write('setup', ['base_url' => App\Core\LicenseClient::domain('https://' . $host)]);
        if (!rename($stage . '/public/index.php', $public . '/index.php')) throw new RuntimeException('Cannot activate the Core installer.');
        if (function_exists('opcache_invalidate')) opcache_invalidate($public . '/index.php', true);
        $state['phase'] = 'complete'; $save($state);
        // No runtime secrets in the ZIP, but remove the unnecessary public download.
        $removed = unlink($archive);
        $reply(['progress' => 100, 'redirect' => '/install', 'archive_removed' => $removed]);
    }
} catch (Throwable $exception) {
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Preparation failed. Check server permissions, available disk space and PHP configuration.';
    if ($post) $reply(['message' => $error], 422);
    http_response_code(422);
}
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Sense CMS</title>
<style nonce="<?= $e($nonce) ?>">
:root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#17243b;background:#f5f7fb;--primary:#2563eb;--line:#d9e0ea;--muted:#64748b}*{box-sizing:border-box}body{margin:0}.shell{min-height:100vh;display:grid;grid-template-columns:43% 57%}.brand{background:linear-gradient(135deg,#f0f5ff,#e9f0ff);color:#17243b;padding:clamp(32px,6vw,96px);display:flex;flex-direction:column;justify-content:space-between;gap:48px}.logo{display:flex;align-items:center;gap:14px;font-weight:750;font-size:25px}.logo img{width:48px;height:48px;border-radius:12px}.eyebrow{text-transform:uppercase;letter-spacing:.15em;font-size:12px;font-weight:750;color:#64748b}h1{font-size:clamp(36px,4vw,62px);line-height:1.1;letter-spacing:-.045em;margin:22px 0}p{line-height:1.7}.brand p{color:#64748b;max-width:440px}.main{background:white;padding:clamp(24px,6vw,96px);display:flex;align-items:center}.card{width:100%;max-width:550px;margin:auto}h2{font-size:32px;letter-spacing:-.035em;margin:22px 0 12px}.muted,small{color:var(--muted)}.tag{display:inline-block;color:#1e40af;background:#dbeafe;border-radius:30px;padding:7px 12px;font-size:12px;font-weight:700}.steps{display:flex;gap:10px;list-style:none;padding:0;margin:28px 0;font-size:12px;flex-wrap:wrap}.steps li{padding:8px 12px;border:1px solid var(--line);border-radius:8px}.steps li:first-child{border-color:var(--primary);color:var(--primary);background:white}button,.continue{display:inline-block;background:var(--primary);color:white;border:0;border-radius:8px;padding:14px 22px;font:inherit;font-weight:650;cursor:pointer;text-decoration:none}button:disabled{opacity:.6;cursor:wait}button:focus-visible,a:focus-visible{outline:3px solid #93c5fd;outline-offset:4px}progress{display:block;width:100%;height:14px;accent-color:var(--primary);margin:26px 0 12px}.status{min-height:48px;margin:0 0 20px;font-size:14px}.error{color:#b91c1c}.note{margin-top:28px;padding-top:22px;border-top:1px solid var(--line);font-size:13px}.brand footer{font-size:13px;color:#a8cbff}@media(max-width:760px){.shell{grid-template-columns:1fr}.brand{gap:24px;padding:28px}.brand h1{font-size:34px}.brand footer{display:none}.main{padding:32px 24px}}
[hidden]{display:none!important}
</style></head><body><main class="shell"><aside class="brand"><div class="logo"><img src="data:image/svg+xml;base64,__SENSE_LOGO__" alt="">Sense CMS</div><div><span class="eyebrow">YOUR WORKSPACE, YOUR WAY</span><h1>A clear starting point.<br>A system of your own.</h1><p>One secure foundation for your website, content and independently installed extensions.</p></div><footer>Sense CMS · Secure installation</footer></aside><section class="main"><div class="card"><span class="tag">Development installer · 0.1.0</span><h2>Prepare your workspace.</h2><p class="muted">We’ll verify and unpack the Core files. The next screen asks for your CMS license key before any database or administrator account is created.</p><ol class="steps" aria-label="Installation steps"><li>1 · Unpack Core</li><li>2 · Verify license</li><li>3 · Set up your CMS</li></ol><progress id="progress" max="100" value="0" aria-label="Verified extraction progress"></progress><p id="status" class="status<?= $error ? ' error' : '' ?>" role="status" aria-live="polite"><?= $e($error ?? 'Ready to verify install.zip.') ?></p><button id="start" <?= $error ? 'disabled' : '' ?>>Prepare installation →</button><a id="continue" class="continue" href="/install" hidden>Continue to license →</a><p class="note muted">Keep this installation restricted to you until setup is complete. Only an empty directory is accepted. Optional themes and extensions are installed separately.</p><noscript><p>JavaScript is required to display extraction progress.</p></noscript></div></section></main>
<script nonce="<?= $e($nonce) ?>">
const start=document.getElementById('start'),status=document.getElementById('status'),progress=document.getElementById('progress');
start.addEventListener('click',async()=>{start.disabled=true;status.classList.remove('error');try{for(;;){status.textContent='Verifying and unpacking Core files… '+progress.value+'%';const response=await fetch('/index.php',{method:'POST',headers:{'Accept':'application/json'},body:new URLSearchParams({action:'extract',csrf:<?= json_encode($_SESSION['csrf'] ?? '') ?>})});const data=await response.json();if(!response.ok)throw new Error(data.message||'Extraction failed.');progress.value=data.progress;if(data.redirect){status.textContent='Core verified. Opening license activation…';start.hidden=true;document.getElementById('continue').hidden=false;location.assign('/install');break;}}}catch(error){status.textContent=error.message;status.classList.add('error');start.disabled=false;start.textContent='Retry preparation';}});
if(!start.disabled)start.click();
</script></body></html>
