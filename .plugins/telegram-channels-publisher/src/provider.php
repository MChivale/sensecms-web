<?php

declare(strict_types=1);

namespace SenseCMS\TelegramChannels;

use App\Core\Runtime;
use RuntimeException;

require_once __DIR__.'/TelegramChannelsClient.php';

return new class {
    private string$root='';
    public function initialize(string$root):void{$this->root=$root;}
    public function verify(array$credentials):array{$channel=(string)($credentials['channel_reference']??$credentials['channel_id']??'');$data=$this->client()->verify($channel);return['external_id'=>$data['channel_id'],'display_name'=>$data['title'].($data['username']!==''?' (@'.$data['username'].')':''),'credentials'=>$data];}
    public function publish(array$credentials,array$payload):array{return$this->client()->publish($credentials,$payload);}
    private function client():TelegramChannelsClient{if($this->root===''||!is_dir($this->root))throw new RuntimeException('The Telegram Channels provider runtime is unavailable.');$runtime=new Runtime($this->root);$config=require$this->root.'/config/workspace.php';return new TelegramChannelsClient($runtime->license(),(string)($config['integrations']['telegram_broker_url']??''),(string)($config['base_url']??''));}
};
