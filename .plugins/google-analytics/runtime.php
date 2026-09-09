<?php
declare(strict_types=1);

use App\Core\ExtensionContext;
use App\Core\CmsRepository;
use App\Core\EventBus;
use SenseCMS\GoogleAnalytics\Analytics;

require_once __DIR__.'/src/Analytics.php';
return static function (string $method, string $path, ExtensionContext $context): bool {
    $settings = static function () use ($context): array {
        $query=$context->db->prepare('SELECT settings FROM installed_plugins WHERE slug=? AND active=1 LIMIT 1');
        $query->execute(['google-analytics']);
        $data=json_decode((string)($query->fetchColumn() ?: '{}'),true);
        return is_array($data) ? $data : [];
    };
    if ($path === '/system/extensions/google-analytics') {
        if (!$context->auth->check()) { header('Location: /login',true,302); exit; }
        $context->access->assert('extensions.manage');
        if ($method === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                if (!$context->auth->verifyCsrf($_POST['csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.',419);
                if (!is_string($_POST['measurement_id']??null)) throw new RuntimeException('Enter a measurement ID.',422);
                $id=Analytics::measurementId($_POST['measurement_id']);
                $context->db->beginTransaction();
                $context->db->prepare('UPDATE installed_plugins SET settings=? WHERE slug=? AND active=1')->execute([json_encode(['measurement_id'=>$id],JSON_THROW_ON_ERROR),'google-analytics']);
                $context->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,"plugin.settings.updated","plugin",0,?,NOW())')->execute([$context->auth->id(),json_encode(['slug'=>'google-analytics'],JSON_THROW_ON_ERROR)]);
                $context->db->commit();
                echo json_encode(['ok'=>true,'message'=>$id===''?'Analytics disabled.':'Configuration saved. Analytics waits for visitor consent.','measurement_id'=>$id]);
            } catch (Throwable $error) {
                if ($context->db->inTransaction()) $context->db->rollBack();
                $status=in_array($error->getCode(),[419,422],true)?$error->getCode():503;
                http_response_code($status);echo json_encode(['ok'=>false,'message'=>$status===503?'Unable to save configuration.':$error->getMessage()]);
            }
            exit;
        }
        if ($method !== 'GET') { header('Allow: GET, POST');http_response_code(405);exit; }
        $context->dashboard->extensionPage('Google Analytics',__DIR__.'/views/settings.php',['analyticsId'=>(string)($settings()['measurement_id']??''),'analyticsCsrf'=>$context->auth->csrf()]);
    }
    // Only published CMS presentation: never login, workspace, preview or download responses.
    if ($method !== 'GET' || ($_SERVER['REQUEST_METHOD']??'GET') !== 'GET' || $context->auth->check() || isset($_GET['sensecms_theme_preview'])) return false;
    if (preg_match('#^/(?:system|api|content|appearance|dashboard|login|logout|license|packages|install|captcha|extension-assets)(?:/|$)#D',$path)) return false;
    $cms=new CmsRepository($context->db,new EventBus());
    if (!preg_match('#^/[a-z]{2}(?:-[a-z]{2})?(?:/|$)#D',$path) && !$cms->pageAtPath($path)) return false;
    try { $id=Analytics::measurementId((string)($settings()['measurement_id']??'')); }
    catch (RuntimeException) { return false; }
    if ($id === '') return false;
    foreach (headers_list() as $header) if (str_starts_with(strtolower($header),'content-security-policy:')) header('Content-Security-Policy: '.Analytics::policy(trim(substr($header,24))));
    ob_start(static fn(string $html):string=>http_response_code()===200?Analytics::inject($html,$id):$html);
    return false;
};
