<?php

declare(strict_types=1);

use App\Core\ExtensionContext;
use SenseCMS\Social\SocialController;
use SenseCMS\Social\SocialIntegrationManager;
use SenseCMS\Social\SocialRepository;

require_once __DIR__ . '/src/SocialIntegrationManager.php';
require_once __DIR__ . '/src/SocialRepository.php';
require_once __DIR__ . '/src/SocialDispatcher.php';
require_once __DIR__ . '/src/SocialController.php';

return static function (string $method,string $path,ExtensionContext $context):bool {
    if ($context->events && $method==='POST' && $path==='/content/posts' && $context->auth->check() && $context->access->allows('social.publish')) {
        $context->events->listen('post.updated',static function(array $event)use($context):void {
            if (!array_key_exists('social_targets_present',$_POST)) return;
            try {
                $manager=new SocialIntegrationManager($context->db,$context->root,(string)($context->config['secrets_key']??''));
                $repository=new SocialRepository($context->db,$manager,(string)($context->config['base_url']??''));
                $repository->syncPost((int)($event['post_id']??0),is_array($_POST['social_targets']??null)?$_POST['social_targets']:[],!empty($_POST['social_republish']),$context->auth->id()??0);
            } catch (Throwable $error) { error_log('Social publishing queue failed: '.get_class($error)); }
        });
        return false;
    }
    if ($method==='GET' && preg_match('#^/content/posts(?:/\d+/edit|/new)?$#D',$path) && $context->auth->check() && $context->access->allows('social.publish')) {
        ob_start(static function(string $html):string {
            $head='<link rel="stylesheet" href="/extension-assets/addon/social-publishing/social.css?v=0.2.0">';
            $body='<script src="/extension-assets/addon/social-publishing/editor.js?v=0.2.0" defer></script>';
            $html=str_replace('</head>',$head.'</head>',$html);
            return str_replace('</body>',$body.'</body>',$html);
        });
        return false;
    }
    if ($path!=='/social-publishing' && !str_starts_with($path,'/api/social-publishing/')) return false;
    if (!$context->auth->check()) {
        if ($path==='/social-publishing'){header('Location: /login',true,302);exit;}
        http_response_code(401);header('Content-Type: application/json');echo json_encode(['ok'=>false,'message'=>'Sign in to access Social Publishing.']);exit;
    }
    try {
        $controller=new SocialController($context,__DIR__);
        if ($method==='GET' && $path==='/social-publishing') $controller->page();
        if ($method==='GET' && $path==='/api/social-publishing/editor') $controller->editor();
        if ($method==='GET' && $path==='/api/social-publishing/deliveries') $controller->deliveries();
        if ($method==='POST' && preg_match('#^/api/social-publishing/deliveries/(\d+)/retry$#D',$path,$matches)) $controller->retry((int)$matches[1]);
        http_response_code(404);header('Content-Type: application/json');echo json_encode(['ok'=>false,'message'=>'Social Publishing endpoint not found.']);exit;
    } catch (Throwable $error) {
        $forbidden=$error instanceof RuntimeException && $error->getCode()===403;
        http_response_code($forbidden?403:500);header('Cache-Control: no-store');header('Content-Type: application/json');
        if(!$forbidden)error_log('Social Publishing request failed: '.get_class($error));
        echo json_encode(['ok'=>false,'message'=>$forbidden?'You do not have permission to access Social Publishing.':'Social Publishing could not complete the request.']);exit;
    }
};
