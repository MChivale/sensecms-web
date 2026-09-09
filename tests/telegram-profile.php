<?php
declare(strict_types=1);
require dirname(__DIR__).'/.src/TelegramBotProfile.php';
$dir=sys_get_temp_dir().'/sense-profile-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$calls=[];$accepted=true;
try {
    file_put_contents($dir.'/config.json',json_encode(['bot_username'=>'SenseCMSBot','bot_token'=>'123456:'.str_repeat('x',35)]));chmod($dir.'/config.json',0600);
    $http=static function(string $method,array $fields)use(&$calls,&$accepted){$calls[]=$method;if($method==='getMe')return ['is_bot'=>true,'username'=>'SenseCMSBot'];if($method==='setMyProfilePhoto'){if(json_decode($fields['photo'],true)!==['type'=>'static','photo'=>'attach://image']||!$fields['image'] instanceof CURLFile)throw new RuntimeException('Invalid multipart');return $accepted;}throw new RuntimeException('Unexpected method');};
    $profile=new SenseCMS\Website\TelegramBotProfile($dir,dirname(__DIR__).'/.src/images/logo.png',$http);
    if($profile->profilePhoto()!==null)throw new RuntimeException('Unexpected photo');
    $profile->removeProfilePhoto();$before=$profile->profilePhoto();
    if(!$before||$before['mime']!=='image/jpeg'||getimagesizefromstring($before['body'])[0]!==640)throw new RuntimeException('Default logo invalid');
    $accepted=false;
    try{$profile->removeProfilePhoto();throw new LogicException('False success');}catch(RuntimeException $error){if(!str_contains($error->getMessage(),'did not confirm'))throw $error;}
    if($profile->profilePhoto()!==$before)throw new RuntimeException('Failure replaced existing photo');
    if($calls!==['getMe','setMyProfilePhoto','getMe','setMyProfilePhoto'])throw new RuntimeException('Unexpected transport');
    echo "PASS default logo conversion, multipart contract, identity verification and failed-update preservation; no live Telegram calls.\n";
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
