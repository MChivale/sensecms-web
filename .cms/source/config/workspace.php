<?php

declare(strict_types=1);

// Installation-local settings only: never inherit another CMS installation's env.
$workspace = $runtime->read('workspace');
if (!is_string($workspace['secret'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $workspace['secret'])) throw new RuntimeException('Workspace encryption identity is not provisioned.');
return [
    'engine_version' => (require __DIR__ . '/product.php')['core_version'], 'environment' => 'production', 'base_url' => $baseUrl,
    'default_locale' => 'en', 'database' => $installed['database'],
    'secrets_key' => $workspace['secret'], 'upload_path' => $root . '/storage/uploads',
    'mail' => ['host'=>'','port'=>587,'security'=>'tls','username'=>'','password'=>'','from_address'=>'','from_name'=>'Sense CMS','recipient'=>''],
    'marketplace' => ['base_url' => 'https://www.sensecms.com/api/marketplace/v1'],
    'integrations' => ['whatsapp_onboarding_url'=>'', 'telegram_broker_url'=>'https://www.sensecms.com/api/telegram/v1', 'meta_social_broker_url'=>'https://www.sensecms.com/api/social/meta/v1', 'x_social_broker_url'=>'https://www.sensecms.com/api/social/x/v1', 'linkedin_social_broker_url'=>'https://www.sensecms.com/api/social/linkedin/v1', 'bluesky_social_broker_url'=>'https://www.sensecms.com/api/social/bluesky/v1', 'pinterest_social_broker_url'=>'https://www.sensecms.com/api/social/pinterest/v1', 'tiktok_social_broker_url'=>'https://www.sensecms.com/api/social/tiktok/v1', 'youtube_social_broker_url'=>'https://www.sensecms.com/api/social/youtube/v1'],
    'web_push' => ['subject'=>'mailto:info@sensecms.com','key_file'=>$root.'/storage/private/web-push-vapid.json','allowed_hosts'=>['fcm.googleapis.com','updates.push.services.mozilla.com','push.services.mozilla.com','web.push.apple.com','notify.windows.com']],
    'demo' => ['enabled'=>false,'email'=>'','password'=>''],
];
