<?php
declare(strict_types=1);

use App\Core\ExtensionContext;
use SenseCMS\LegacyRedirects\RedirectRules;

require_once __DIR__.'/src/RedirectRules.php';

return static function (string $method, string $path, ExtensionContext $context): bool {
    $settings=static function () use ($context):array {
        $query=$context->db->prepare('SELECT settings FROM installed_plugins WHERE slug=? AND active=1 LIMIT 1');
        $query->execute(['legacy-redirects']);
        $stored=json_decode((string)($query->fetchColumn()?:'{}'),true);
        return is_array($stored)?$stored:[];
    };
    if ($path==='/system/extensions/legacy-redirects') {
        if (!$context->auth->check()) { header('Location: /login',true,302); exit; }
        $context->access->assert('extensions.manage');
        if ($method==='POST') {
            header('Content-Type: application/json; charset=utf-8');
            $uiText = class_exists(\App\Core\ConsoleLocale::class) ? \App\Core\ConsoleLocale::messages() : static fn(string $s):string=>$s;
            try {
                if (!$context->auth->verifyCsrf($_POST['csrf']??null)) throw new RuntimeException('Your session expired. Refresh and try again.',419);
                $rules=RedirectRules::fromInput($_POST['rules']??[]);
                $current=$settings(); $current['rules']=$rules;
                $context->db->beginTransaction();
                $context->db->prepare('UPDATE installed_plugins SET settings=? WHERE slug=? AND active=1')->execute([json_encode($current,JSON_THROW_ON_ERROR),'legacy-redirects']);
                $context->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,"plugin.settings.updated","plugin",0,?,NOW())')->execute([$context->auth->id(),json_encode(['slug'=>'legacy-redirects','groups'=>count($rules)],JSON_THROW_ON_ERROR)]);
                $context->db->commit();
                echo json_encode(['ok'=>true,'message'=>$uiText('Redirect rules saved.'),'count'=>count($rules)],JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                if ($context->db->inTransaction()) $context->db->rollBack();
                $status=in_array($error->getCode(),[419,422],true)?$error->getCode():503;
                http_response_code($status);
                echo json_encode(['ok'=>false,'message'=>$uiText($status===503?'Unable to save redirect rules.':$error->getMessage())],JSON_THROW_ON_ERROR);
            }
            exit;
        }
        if ($method!=='GET') { header('Allow: GET, POST'); http_response_code(405); exit; }
        $stored=$settings();
        try { $rules=isset($stored['rules'])?RedirectRules::fromInput($stored['rules']):RedirectRules::defaults(); }
        catch (Throwable) { $rules=[]; }
        $context->dashboard->extensionPage('Legacy Redirects',__DIR__.'/views/settings.php',['redirectRules'=>$rules,'redirectCsrf'=>$context->auth->csrf()]);
    }

    if (!in_array($method,['GET','HEAD'],true)) return false;
    $stored=$settings();
    try { $rules=isset($stored['rules'])?RedirectRules::fromInput($stored['rules']):RedirectRules::defaults(); }
    catch (Throwable) { return false; }
    $rule=RedirectRules::match($rules,$path);
    if (!$rule) return false;
    header('Location: '.$rule['destination'],true,(int)$rule['status']);
    return true;
};
