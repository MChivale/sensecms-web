<?php

declare(strict_types=1);

use App\Core\ExtensionContext;
use App\Core\Runtime;
use SenseCMS\Facebook\MetaOnboardingClient;
use SenseCMS\Social\SocialIntegrationManager;

require_once __DIR__.'/src/MetaOnboardingClient.php';

return static function(string$method,string$path,ExtensionContext$context):bool{
    $disconnect=preg_match('#^/social-publishing/facebook/connections/(\d+)/disconnect$#D',$path,$matches)===1;
    if(!in_array($path,['/social-publishing/facebook','/social-publishing/facebook/status','/social-publishing/facebook/connect','/social-publishing/facebook/callback'],true)&&!$disconnect)return false;
    if(!$context->auth->check()){header('Location: /login',true,302);exit;}
    $context->access->assert('social.settings.manage');
    $foundation=$context->root.'/addons/social-publishing/src/SocialIntegrationManager.php';
    if(!is_file($foundation))throw new RuntimeException('The Social Publishing add-on is required.');
    require_once$foundation;$manager=new SocialIntegrationManager($context->db,$context->root,(string)($context->config['secrets_key']??''));
    if($method==='GET'&&$path==='/social-publishing/facebook'){
        $provider=null;foreach($manager->catalog()as$item)if($item['slug']==='facebook-publisher'){$provider=$item;break;}
        $context->dashboard->extensionPage('Facebook Publisher',__DIR__.'/views/facebook.php',['facebookProvider'=>$provider,'facebookOAuthRevision'=>(int)($_SESSION['facebook_oauth_revision']??0),'facebookOAuthOutcome'=>(string)($_SESSION['flash_type']??''),'csrf'=>$context->auth->csrf(),'extensionActive'=>'/social-publishing','extensionStyles'=>['/extension-assets/addon/social-publishing/social.css?v=0.2.0','/extension-assets/plugin/facebook-publisher/facebook.css?v=0.2.0'],'extensionScripts'=>['/extension-assets/plugin/facebook-publisher/facebook.js?v=0.2.0']]);
    }
    if($method==='GET'&&$path==='/social-publishing/facebook/status'){
        $connections=[];foreach($manager->catalog()as$item)if($item['slug']==='facebook-publisher'){$connections=(array)($item['connections']??[]);break;}
        header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>['connected'=>$connections!==[],'connected_count'=>count($connections),'connection_ids'=>array_values(array_map(static fn(array$item):int=>(int)$item['id'],$connections)),'revision'=>(int)($_SESSION['facebook_oauth_revision']??0)]],JSON_THROW_ON_ERROR);exit;
    }
    if($method==='POST'&&$path==='/social-publishing/facebook/connect'){
        if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
        $base=(string)($context->config['integrations']['meta_social_broker_url']??'');$client=new MetaOnboardingClient((new Runtime($context->root))->license(),$base,(string)$context->config['base_url']);$url=$client->start();
        if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'authorize_url'=>$url],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
        header('Location: '.$url,true,302);exit;
    }
    if($method==='GET'&&$path==='/social-publishing/facebook/callback'){
        try{$base=(string)($context->config['integrations']['meta_social_broker_url']??'');$client=new MetaOnboardingClient((new Runtime($context->root))->license(),$base,(string)$context->config['base_url']);$data=$client->claim((string)($_GET['claim']??''));$manager->save('facebook-publisher',(string)$data['page_id'],(string)$data['page_name'],['page_id'=>(string)$data['page_id'],'access_token'=>(string)$data['access_token'],'api_version'=>(string)$data['api_version']]);$_SESSION['facebook_oauth_revision']=(int)($_SESSION['facebook_oauth_revision']??0)+1;$_SESSION['flash']='Facebook Page connected successfully.';$_SESSION['flash_type']='success';}
        catch(Throwable$error){error_log('Facebook connection failed: '.get_class($error));$_SESSION['flash']='Facebook could not be connected. Verify the selected Page and permissions.';$_SESSION['flash_type']='error';}
        header('Location: /social-publishing/facebook',true,302);exit;
    }
    if($method==='POST'&&$disconnect){
        if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
        $manager->disconnect('facebook-publisher',(int)$matches[1]);$_SESSION['flash']='Facebook Page disconnected. Existing delivery history was preserved.';$_SESSION['flash_type']='success';header('Location: /social-publishing/facebook',true,302);exit;
    }
    http_response_code(405);header('Allow: GET, POST');exit;
};
