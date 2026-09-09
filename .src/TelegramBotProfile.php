<?php
declare(strict_types=1);
namespace SenseCMS\Website;

use Closure;
use RuntimeException;

/** Operator-site only: customer installations never receive the shared bot token. */
final class TelegramBotProfile
{
    private array $config;
    public function __construct(private readonly string $private, private readonly string $logo, private readonly ?Closure $http=null)
    {
        $file=$private.'/config.json';
        if(is_link($private)||is_link($file)||!is_file($file)||filesize($file)>8192||(PHP_OS_FAMILY!=='Windows'&&(fileperms($file)&0077))) throw new RuntimeException('Bot profile management is unavailable.');
        $this->config=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
        if(($this->config['bot_username']??'')!=='SenseCMSBot'||!preg_match('/^[0-9]{6,15}:[A-Za-z0-9_-]{30,100}$/D',(string)($this->config['bot_token']??''))) throw new RuntimeException('Unexpected bot identity.');
    }
    public function profilePhoto(array $settings=[]): ?array
    {
        $file=$this->private.'/profile.jpg';
        return is_file($file)&&!is_link($file)?['mime'=>'image/jpeg','body'=>file_get_contents($file)]:null;
    }
    public function setProfilePhoto(array $settings,string $file): void
    {
        $size=@getimagesize($file);
        if(is_link($file)||!is_file($file)||filesize($file)>2000000||!$size||$size[2]!==IMAGETYPE_JPEG||$size[0]>4096||$size[1]>4096) throw new RuntimeException('Use a valid JPEG profile photo.');
        $lock=fopen($this->private.'/profile.lock','c+');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another profile update is in progress.');
        chmod($this->private.'/profile.lock',0600);
        $staged=$this->private.'/profile-'.bin2hex(random_bytes(8)).'.jpg';
        try {
            if(!copy($file,$staged)||!chmod($staged,0600))throw new RuntimeException('Cannot stage the bot image.');
            $bot=$this->call('getMe');
            if(($bot['username']??'')!=='SenseCMSBot'||empty($bot['is_bot']))throw new RuntimeException('Unexpected bot identity.');
            if($this->call('setMyProfilePhoto',['photo'=>json_encode(['type'=>'static','photo'=>'attach://image'],JSON_THROW_ON_ERROR),'image'=>new \CURLFile($staged,'image/jpeg','profile.jpg')])!==true)throw new RuntimeException('Telegram did not confirm the photo update.');
            $current=$this->private.'/profile.jpg';
            if(is_file($current)&&!copy($current,$this->private.'/profile.previous.jpg'))throw new RuntimeException('Telegram updated, but the previous preview could not be retained.');
            if(is_file($this->private.'/profile.previous.jpg'))chmod($this->private.'/profile.previous.jpg',0600);
            if(!rename($staged,$current))throw new RuntimeException('Telegram updated, but its preview could not be saved.');
        } finally {if(is_file($staged))unlink($staged);flock($lock,LOCK_UN);fclose($lock);}
    }
    public function removeProfilePhoto(array $settings=[]): void
    {
        if(!is_file($this->logo)||is_link($this->logo))throw new RuntimeException('Default logo unavailable.');
        $image=imagecreatefrompng($this->logo);if(!$image)throw new RuntimeException('Default logo invalid.');
        $canvas=imagecreatetruecolor(640,640);imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));
        imagecopyresampled($canvas,$image,0,0,0,0,640,640,imagesx($image),imagesy($image));
        $file=tempnam($this->private,'logo-');if(!$file)throw new RuntimeException('Cannot prepare default logo.');
        try{if(!imagejpeg($canvas,$file,92))throw new RuntimeException('Cannot render default logo.');chmod($file,0600);$this->setProfilePhoto([],$file);}finally{unlink($file);imagedestroy($canvas);imagedestroy($image);}
    }
    private function call(string $method,array $fields=[]): mixed
    {
        if($this->http)return ($this->http)($method,$fields);
        $curl=curl_init('https://api.telegram.org/bot'.$this->config['bot_token'].'/'.$method);$body='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields?:'{}',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_WRITEFUNCTION=>static function($c,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);}finally{curl_close($curl);}
        $data=json_decode($body,true);
        if($ok===false||$status!==200||empty($data['ok']))throw new RuntimeException('Telegram did not confirm this operation. Refresh and check the bot before retrying.');
        return $data['result']??null;
    }
}
