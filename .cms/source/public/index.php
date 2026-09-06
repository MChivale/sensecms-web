<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\LicenseException;
use App\Core\Runtime;
use App\Installer\Installer;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && is_string($path) && preg_match('#^/assets/[a-z0-9-]+\.(css|js|svg)$#D', $path) && is_file(__DIR__ . $path)) return false;
$json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$reply = static function (array $data, int $status = 200) use ($json): never {
    http_response_code($status);
    if ($json) { header('Content-Type: application/json'); echo json_encode($data, JSON_THROW_ON_ERROR); }
    elseif (isset($data['redirect'])) header('Location: ' . $data['redirect'], true, 303);
    else echo htmlspecialchars($data['message'] ?? 'Request completed.', ENT_QUOTES, 'UTF-8');
    exit;
};
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
try {
    if (!is_string($path) || preg_match('#^/(?:storage|config|app|database|scripts)(?:/|$)|(?:^|/)\.#', rawurldecode($path))) $reply(['message' => 'Not found.'], 404);
    $runtime = new Runtime($root); $baseUrl = $runtime->baseUrl();
    $local = getenv('SENSE_LOCAL_HTTP') === '1' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(?::[0-9]+)?$/D', $_SERVER['HTTP_HOST'] ?? '');
    if (!$local && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) $reply(['message' => 'HTTPS is required.'], 400);
    if (!$local && strtolower($_SERVER['HTTP_HOST'] ?? '') !== parse_url($baseUrl, PHP_URL_HOST)) $reply(['message' => 'Unexpected installation host.'], 421);
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST', 'HEAD'], true)) { header('Allow: GET, HEAD, POST'); $reply(['message' => 'Method not allowed.'], 405); }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) $reply(['message' => 'Request too large.'], 413);
    $installed = $runtime->read('installed');
    // Public presentation is optional and does not instantiate administration or a database connection.
    if ($installed && !preg_match('#^/(?:install|login|logout|dashboard|settings|license)(?:/|$)#D', $path)) {
        $themeRoot = (new App\Core\Packages\ThemeManager($runtime))->activePath();
        if ($themeRoot !== null) {
            try { $runtime->license()->enforce($baseUrl); }
            catch (LicenseException) { $reply(['message' => 'Website temporarily unavailable.'], 503); }
            [$status, $headers, $body] = (new App\Core\PublicTheme($themeRoot, $baseUrl))->response($path, $_SERVER['REQUEST_METHOD'] ?? 'GET');
            http_response_code($status);
            foreach ($headers as $name => $value) header($name . ': ' . $value);
            echo $body; exit;
        }
    }
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    if (!is_dir($root . '/storage/sessions') && !mkdir($root . '/storage/sessions', 0700)) throw new RuntimeException('Cannot create private sessions.');
    session_save_path($root . '/storage/sessions'); session_name('sensecms_session');
    session_set_cookie_params(['httponly' => true, 'secure' => !$local, 'samesite' => 'Strict', 'path' => '/']); session_start();
    $csrf = Auth::csrf(); $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($post && !Auth::verifyCsrf($_POST['csrf'] ?? null)) $reply(['message' => 'Your session expired. Refresh the page and try again.'], 419);
    if (!$installed) {
        if (!in_array($path, ['/install', '/install/license', '/install/requirements', '/install/complete'], true)) $reply(['redirect' => '/install']);
        $installer = new Installer($runtime);
        $stage = (int) ($_SESSION['install_stage'] ?? 0);
        if (($stage > 0) && ($_SESSION['install_until'] ?? 0) < time()) { unset($_SESSION['install_stage']); $stage = 0; }
        if ($post && $path === '/install/license') {
            // One rate limit shared by all sessions, with no submitted keys in logs.
            $limit = fopen($root . '/storage/license-attempts.lock', 'c+b');
            if (!$limit || !flock($limit, LOCK_EX)) throw new RuntimeException('Cannot check installation request limit.');
            try {
                $attempts = json_decode(stream_get_contents($limit), true) ?: ['count' => 0, 'until' => time() + 300];
                if ($attempts['until'] <= time()) $attempts = ['count' => 0, 'until' => time() + 300];
                if ($attempts['count'] >= 15) $reply(['message' => 'Too many license attempts. Try again in five minutes.'], 429);
                $attempts['count']++; rewind($limit); ftruncate($limit, 0); fwrite($limit, json_encode($attempts, JSON_THROW_ON_ERROR));
            } finally { flock($limit, LOCK_UN); fclose($limit); }
            $runtime->license()->install(trim((string) ($_POST['license_key'] ?? '')), $baseUrl);
            session_regenerate_id(true);
            $_SESSION['install_stage'] = 1; $_SESSION['install_until'] = time() + 1800;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $reply(['redirect' => '/install']);
        }
        if ($stage > 0) $runtime->license()->enforce($baseUrl);
        if ($post && $path === '/install/requirements' && $stage === 1) {
            if (in_array(false, $installer->requirements(), true)) $reply(['message' => 'Resolve the failed server checks first.'], 422);
            $_SESSION['install_stage'] = 2; $reply(['redirect' => '/install']);
        }
        if ($post && $path === '/install/complete' && $stage === 2) {
            $installer->install($_POST); $_SESSION = []; session_regenerate_id(true); $reply(['redirect' => '/login']);
        }
        if ($post) $reply(['message' => 'This installation step is not available.'], 409);
        $checks = $stage === 1 ? $installer->requirements() : []; $screen = 'install';
    } else {
        if (str_starts_with((string) $path, '/install')) $reply(['message' => 'Installation is closed.'], 404);
        $db = Runtime::connect($installed['database']); $auth = new Auth($db); $user = $auth->user();
        if ($path === '/login') {
            if ($post) {
                if (!$auth->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '')) $reply(['message' => 'Sign-in failed or temporarily limited. Check your details and try again later.'], 401);
                $reply(['redirect' => '/dashboard']);
            }
            if ($user) $reply(['redirect' => '/dashboard']);
            $screen = 'login';
        } else {
            if (!$user) $reply(['redirect' => '/login'], 401);
            if ($post && $path === '/logout') { $_SESSION = []; session_regenerate_id(true); $reply(['redirect' => '/login']); }
            if (!$user['owner']) $reply(['message' => 'Owner access is required.'], 403);
            if ($path === '/') $reply(['redirect' => '/dashboard']);
            if (!in_array($path, ['/dashboard', '/settings', '/license'], true)) $reply(['message' => 'Page not found.'], 404);
            $screen = substr($path, 1); $license = null;
            if ($path !== '/license') {
                try { $license = $runtime->license()->enforce($baseUrl); }
                catch (LicenseException) { $reply(['redirect' => '/license']); }
            }
            if ($post && $path === '/settings') {
                $name = trim((string) ($_POST['site_name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 120) $reply(['message' => 'Enter a site name up to 120 characters.'], 422);
                $db->prepare("UPDATE settings SET value=? WHERE `key`='site_name'")->execute([$name]); $auth->audit((int) $user['id'], 'settings.updated');
                $reply(['message' => 'Site settings saved.']);
            }
            if ($post && $path === '/license') {
                $runtime->license()->install(trim((string) ($_POST['license_key'] ?? '')), $baseUrl); $auth->audit((int) $user['id'], 'license.updated'); $reply(['redirect' => '/dashboard']);
            }
            if ($post) $reply(['message' => 'Method not allowed.'], 405);
            if ($path === '/license') { try { $license = $runtime->license()->enforce($baseUrl); } catch (LicenseException) {} }
            $siteName = $db->query("SELECT value FROM settings WHERE `key`='site_name'")->fetchColumn();
            $activity = $screen === 'dashboard' ? $db->query('SELECT event,created_at FROM activity_log ORDER BY id DESC LIMIT 10')->fetchAll() : [];
        }
    }
    require $root . '/app/Views/workspace.php';
} catch (LicenseException $error) {
    $reply(['message' => $error->getMessage()], 422);
} catch (PDOException) {
    $reply(['message' => 'Database operation failed. Check the connection, permissions and database state.'], 503);
} catch (Throwable $error) {
    $id = bin2hex(random_bytes(6)); error_log('Sense CMS ' . $id . ': ' . get_class($error));
    $reply(['message' => $error instanceof RuntimeException ? $error->getMessage() : 'Request failed. Reference: ' . $id], 422);
}
