<?php
declare(strict_types=1);

// Visual QA fixture, not a production entry point. HTTP auth is tested separately.
$project = dirname(__DIR__);
if (PHP_SAPI !== 'cli-server' || !preg_match('#^/root/sense-workspace-test\.[A-Za-z0-9]{8}$#D', $project)
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(404); exit; }
$root = $project . '/.cms/source'; require_once $root . '/bootstrap.php';
$runtime = new App\Core\Runtime($root); $installed = $runtime->read('installed');
session_name('sensecms_session'); session_save_path($root . '/storage/sessions'); session_start();
$auth = new App\Core\Auth(App\Core\Runtime::connect($installed['database']));
if (!$auth->check()) {
    $owner = json_decode((string) file_get_contents($project . '/preview-private.json'), true, 16, JSON_THROW_ON_ERROR);
    if (!$auth->attempt($owner['email'], $owner['password'], '127.0.0.1')) throw new RuntimeException('QA account authentication failed.');
    unset($owner);
}
session_write_close();
return require $root . '/public/index.php';
