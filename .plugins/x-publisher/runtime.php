<?php

declare(strict_types=1);

use App\Core\ExtensionContext;
use App\Core\Runtime;
use SenseCMS\Social\SocialIntegrationManager;
use SenseCMS\X\XOnboardingClient;

require_once __DIR__.'/src/XOnboardingClient.php';

return static function(string$method,string$path,ExtensionContext$context):bool{
    $disconnect=preg_match('#^/social-publishing/x/connections/(\d+)/disconnect$#D',$path,$matches)===1;
    if(!in_array($path,['/social-publishing/x','/social-publishing/x/status','/social-publishing/x/connect','/social-publishing/x/callback'],true)&&!$disconnect)return false;
    if(!$context->auth->check()){header('Location: /login',true,302);exit;}
    $context->access->assert('social.settings.manage');
    $foundation=$context->root.'/addons/social-publishing/src/SocialIntegrationManager.php';if(!is_file($foundation))throw new RuntimeException('The Social Publishing add-on is required.');
    require_once$foundation;$manager=new SocialIntegrationManager($context->db,$context->root,(string)($context->config['secrets_key']??''));
    if($method==='GET'&&$path==='/social-publishing/x'){
        $provider=null;foreach($manager->catalog()as$item)if($item['slug']==='x-publisher'){$provider=$item;break;}
        $context->dashboard->extensionPage('X Publisher',__DIR__.'/views/x.php',['xProvider'=>$provider,'xOAuthRevision'=>(int)($_SESSION['x_oauth_revision']??0),'xOAuthOutcome'=>(string)($_SESSION['flash_type']??''),'csrf'=>$context->auth->csrf(),'extensionActive'=>'/social-publishing','extensionStyles'=>['/extension-assets/addon/social-publishing/social.css?v=0.2.1','/extension-assets/plugin/x-publisher/x.css?v=0.1.1'],'extensionScripts'=>['/extension-assets/plugin/x-publisher/x.js?v=0.1.1']]);
    }
    if($method==='GET'&&$path==='/social-publishing/x/status'){
        $connections=[];foreach($manager->catalog()as$item)if($item['slug']==='x-publisher'){$connections=(array)($item['connections']??[]);break;}
        header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>['connected'=>$connections!==[],'connected_count'=>count($connections),'connection_ids'=>array_values(array_map(static fn(array$item):int=>(int)$item['id'],$connections)),'revision'=>(int)($_SESSION['x_oauth_revision']??0)]],JSON_THROW_ON_ERROR);exit;
    }
    if($method==='POST'&&$path==='/social-publishing/x/connect'){
        if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
        $base=(string)($context->config['integrations']['x_social_broker_url']??'');$client=new XOnboardingClient((new Runtime($context->root))->license(),$base,(string)$context->config['base_url']);$url=$client->start();
        if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'authorize_url'=>$url],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
        header('Location: '.$url,true,302);exit;
    }
    if($method==='GET'&&$path==='/social-publishing/x/callback'){
        try{
            if(!isset($_GET['claim']))throw new RuntimeException('X authorization was cancelled.');
            $base=(string)($context->config['integrations']['x_social_broker_url']??'');$client=new XOnboardingClient((new Runtime($context->root))->license(),$base,(string)$context->config['base_url']);$data=$client->claim((string)$_GET['claim']);
            $manager->save('x-publisher',(string)$data['user_id'],'@'.(string)$data['username'],['user_id'=>(string)$data['user_id'],'username'=>(string)$data['username'],'name'=>(string)$data['name'],'access_token'=>(string)$data['access_token'],'refresh_token'=>(string)$data['refresh_token'],'expires_at'=>(int)$data['expires_at']]);
            $_SESSION['x_oauth_revision']=(int)($_SESSION['x_oauth_revision']??0)+1;$_SESSION['flash']='X account connected successfully.';$_SESSION['flash_type']='success';
        }catch(Throwable$error){error_log('X connection failed: '.get_class($error));$_SESSION['flash']='X could not be connected. Verify the account authorization and API access.';$_SESSION['flash_type']='error';}
        header('Location: /social-publishing/x',true,302);exit;
    }
    if($method==='POST'&&$disconnect){
        if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
        $manager->disconnect('x-publisher',(int)$matches[1]);$_SESSION['flash']='X account disconnected. Existing delivery history was preserved.';$_SESSION['flash_type']='success';header('Location: /social-publishing/x',true,302);exit;
    }
    http_response_code(405);header('Allow: GET, POST');exit;
};
