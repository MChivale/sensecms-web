<?php

declare(strict_types=1);

use App\Core\ExtensionContext;
use SenseCMS\Social\SocialIntegrationManager;

return static function(string$method,string$path,ExtensionContext$context):bool{
    $disconnect=preg_match('#^/social-publishing/telegram-channels/connections/(\d+)/disconnect$#D',$path,$matches)===1;if(!in_array($path,['/social-publishing/telegram-channels','/social-publishing/telegram-channels/connect'],true)&&!$disconnect)return false;
    if(!$context->auth->check()){header('Location: /login',true,302);exit;}$context->access->assert('social.settings.manage');$foundation=$context->root.'/addons/social-publishing/src/SocialIntegrationManager.php';if(!is_file($foundation))throw new RuntimeException('The Social Publishing add-on is required.');require_once$foundation;$manager=new SocialIntegrationManager($context->db,$context->root,(string)($context->config['secrets_key']??''));
    if($method==='GET'&&$path==='/social-publishing/telegram-channels'){$provider=null;foreach($manager->catalog()as$item)if($item['slug']==='telegram-channels-publisher'){$provider=$item;break;}$context->dashboard->extensionPage('Telegram Channels Publisher',__DIR__.'/views/telegram-channels.php',['telegramChannelsProvider'=>$provider,'csrf'=>$context->auth->csrf(),'extensionActive'=>'/social-publishing','extensionStyles'=>['/extension-assets/addon/social-publishing/social.css?v=0.2.2','/extension-assets/plugin/telegram-channels-publisher/telegram-channels.css?v=0.1.0']]);}
    if($method==='POST'&&$path==='/social-publishing/telegram-channels/connect'){try{if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);$channel=trim((string)($_POST['channel']??''));$manager->save('telegram-channels-publisher',$channel,$channel,['channel_reference'=>$channel]);$_SESSION['flash']='Telegram channel connected successfully.';$_SESSION['flash_type']='success';}catch(Throwable$error){error_log('Telegram channel connection failed: '.get_class($error));$_SESSION['flash']=$error->getCode()===419?$error->getMessage():'The Telegram channel could not be connected. Add @SenseCMSBot as an administrator allowed to post, then try again.';$_SESSION['flash_type']='error';}header('Location: /social-publishing/telegram-channels',true,302);exit;}
    if($method==='POST'&&$disconnect){if(!$context->auth->verifyCsrf($_POST['csrf']??null))throw new RuntimeException('Your session token is invalid. Refresh and try again.',419);$manager->disconnect('telegram-channels-publisher',(int)$matches[1]);$_SESSION['flash']='Telegram channel disconnected. Existing delivery history was preserved.';$_SESSION['flash_type']='success';header('Location: /social-publishing/telegram-channels',true,302);exit;}
    http_response_code(405);header('Allow: GET, POST');exit;
};
