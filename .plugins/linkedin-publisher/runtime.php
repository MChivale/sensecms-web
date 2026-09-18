<?php

declare(strict_types=1);

use App\Core\ExtensionContext;
use App\Core\Runtime;
use SenseCMS\LinkedIn\LinkedInOnboardingClient;
use SenseCMS\Social\SocialIntegrationManager;

require_once __DIR__.'/src/LinkedInOnboardingClient.php';

return static function(string$method,string$path,ExtensionContext$context):bool{
    $disconnect=preg_match('#^/social-publishing/linkedin/connections/(\d+)/disconnect$#D',$path,$matches)===1;
    if(!in_array($path,['/social-publishing/linkedin','/social-publishing/linkedin/status','/social-publishing/linkedin/connect','/social-publishing/linkedin/callback'],true)&&!$disconnect)return false;
    if(!$context->auth->check()){header('Location: /login',true,302);exit;}
    $context->access->assert('social.settings.manage');
    $foundation=$context->root.'/addons/social-publishing/src/SocialIntegrationManager.php';if(!is_file($foundation))throw new RuntimeException('The Social Publishing add-on is required.');
    require_once$foundation;$manager=new SocialIntegrationManager($context->db,$context->root,(string)($context->config['secrets_key']??''));
    if($method==='GET'&&$path==='/social-publishing/linkedin'){
        $provider=null;foreach($manager->catalog()as$item)if($item['slug']==='linkedin-publisher'){$provider=$item;break;}
        $context->dashboard->extensionPage('LinkedIn Publisher',__DIR__.'/views/linkedin.php',['linkedinProvider'=>$provider,'linkedinOAuthRevision'=>(int)($_SESSION['linkedin_oauth_revision']??0),'linkedinOAuthOutcome'=>(string)($_SESSION['flash_type']??''),'csrf'=>$context->auth->csrf(),'extensionActive'=>'/social-publishing','extensionStyles'=>['/extension-assets/addon/social-publishing/social.css?v=0.2.1','/extension-assets/plugin/linkedin-publisher/linkedin.css?v=0.1.1'],'extensionScripts'=>['/extension-assets/plugin/linkedin-publisher/linkedin.js?v=0.1.1']]);
    }
    if($method==='GET'&&$path==='/social-publishing/linkedin/status'){
        $connections=[];foreach($manager->catalog()as$item)if($item['slug']==='linkedin-publisher'){$connections=(array)($item['connections']??[]);break;}
        header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'data'=>['connected'=>$connections!==[],'connected_count'=>count($connections),'connection_ids'=>array_values(array_map(static fn(array$item):int=>(int)$item['id'],$connections)),'revision'=>(int)($_SESSION['linkedin_oauth_revision']??0)]],JSON_THROW_ON_ERROR);exit;
    }
    if($method==='POST'&&$path==='/social-publishing/linkedin/connect'){
        if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
        $base=(string)($context->config['integrations']['linkedin_social_broker_url']??'');$client=new LinkedInOnboardingClient((new Runtime($context->root))->license(),$base,(string)$context->config['base_url']);$url=$client->start();
        if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'authorize_url'=>$url],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
        header('Location: '.$url,true,302);exit;
    }
    if($method==='GET'&&$path==='/social-publishing/linkedin/callback'){
        try{
            if(!isset($_GET['claim']))throw new RuntimeException('LinkedIn authorization was cancelled.');
            $base=(string)($context->config['integrations']['linkedin_social_broker_url']??'');$client=new LinkedInOnboardingClient((new Runtime($context->root))->license(),$base,(string)$context->config['base_url']);$data=$client->claim((string)$_GET['claim']);
            $manager->save('linkedin-publisher',(string)$data['member_id'],(string)$data['name'],['member_id'=>(string)$data['member_id'],'name'=>(string)$data['name'],'access_token'=>(string)$data['access_token'],'expires_at'=>(int)$data['expires_at']]);
            $_SESSION['linkedin_oauth_revision']=(int)($_SESSION['linkedin_oauth_revision']??0)+1;$_SESSION['flash']='LinkedIn profile connected successfully.';$_SESSION['flash_type']='success';
        }catch(Throwable$error){error_log('LinkedIn connection failed: '.get_class($error));$_SESSION['flash']='LinkedIn could not be connected. Verify the account authorization and product access.';$_SESSION['flash_type']='error';}
        header('Location: /social-publishing/linkedin',true,302);exit;
    }
    if($method==='POST'&&$disconnect){
        if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);
        $manager->disconnect('linkedin-publisher',(int)$matches[1]);$_SESSION['flash']='LinkedIn profile disconnected. Existing delivery history was preserved.';$_SESSION['flash_type']='success';header('Location: /social-publishing/linkedin',true,302);exit;
    }
    http_response_code(405);header('Allow: GET, POST');exit;
};
