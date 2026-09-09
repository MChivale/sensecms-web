<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SystemUpdate
{
    private string $dir;
    public function __construct(private readonly PDO $db,private readonly string $root,private readonly array $config)
    {
        $this->dir=$root.'/storage/system-updates';
        if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true)) throw new RuntimeException('The update storage is not writable.');
    }
    public function version(): string { return (string)($this->read($this->root.'/app/release.json')['version']??'1.0.0'); }
    public function status(): array
    {
        $state=$this->read($this->dir.'/state.json');$job=$this->read($this->dir.'/job.json');$catalog=[];
        try{if(is_file($this->dir.'/catalog.json'))$catalog=OfficialCatalog::verify((string)file_get_contents($this->dir.'/catalog.json'));}catch(Throwable){}
        $latest=$catalog['core']??[];$available=isset($latest['version'])&&version_compare($latest['version'],$this->version(),'>');
        return ['version'=>$this->version(),'latest'=>$latest,'available'=>$available,'checked_at'=>$state['checked_at']??null,'error'=>$state['error']??null,'job'=>$job,'products'=>$catalog['products']??[],'worker_at'=>$state['worker_at']??null];
    }
    public function requestCheck(): void { $this->write($this->dir.'/check.json',['requested_at'=>time()]); }
    public function requestInstall(string $version,int $userId): void
    {
        $this->locked(function()use($version,$userId):void{
            $state=$this->status();
            if(in_array($state['job']['status']??'', ['queued','running'],true))throw new RuntimeException('An update is already in progress.');
            if(!$state['available']||($state['latest']['version']??'')!==$version)throw new RuntimeException('Check for updates again before installing.');
            if(($state['worker_at']??0)<time()-300)throw new RuntimeException('The update worker is not running. Ask the server administrator to check the scheduled task.');
            $this->write($this->dir.'/job.json',['id'=>bin2hex(random_bytes(12)),'status'=>'queued','version'=>$version,'user_id'=>$userId,'requested_at'=>time(),'message'=>'Update queued. The site will briefly enter maintenance mode.']);
        });
    }
    public function run(): array
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Updates run only through the server worker.');
        return $this->locked(function():array{
            $state=$this->read($this->dir.'/state.json');$state['worker_at']=time();
            if(is_file($this->dir.'/check.json')||($state['attempted_at']??0)<time()-21600){
                $state['attempted_at']=time();
                try{$raw=OfficialCatalog::download(OfficialCatalog::BASE.'/official/',5242880);OfficialCatalog::verify($raw);$this->writeRaw($this->dir.'/catalog.json',$raw);$state['checked_at']=time();$state['error']=null;}
                catch(Throwable $error){$state['error']=$error instanceof RuntimeException?$error->getMessage():'The catalog could not be verified.';}
                if(is_file($this->dir.'/check.json'))unlink($this->dir.'/check.json');
            }
            $this->write($this->dir.'/state.json',$state);
            $status=$this->status();$job=$status['job'];
            if(($job['status']??'')==='queued'){
                try{
                    $this->authorizeActor((int)$job['user_id']);
                    if(!$status['available']||($status['latest']['version']??'')!==$job['version'])throw new RuntimeException('The approved release is no longer available. Check for updates again.');
                    $job['status']='running';$job['message']='Verifying package and creating recovery snapshots.';$this->write($this->dir.'/job.json',$job);
                    $license=(new Runtime($this->root))->license();$license->enforce($this->config['base_url']);
                    $release=$status['latest'];$raw=OfficialCatalog::download(OfficialCatalog::BASE.'/core-package/',33554432,$license->marketplaceHeaders($this->config['base_url']));
                    if(!hash_equals((string)$release['checksum'],hash('sha256',$raw)))throw new RuntimeException('The core package checksum does not match its signed release.');
                    $archive=$this->dir.'/download-'.$job['id'].'.zip';$this->writeRaw($archive,$raw);unset($raw);
                    $recovery=$this->install($archive,$release,$job);
                    $job['status']='completed';$job['message']='SenseCMS updated successfully. Your content and configuration were preserved.';$job['recovery']=$recovery;
                    unlink($archive);
                }catch(Throwable $error){$job['status']='failed';$job['message']=$error instanceof RuntimeException&&!$error instanceof \PDOException?$error->getMessage():'Update failed. Review the private recovery log before retrying.';error_log('SenseCMS core update: '.get_class($error).' '.$error->getMessage());}
                $job['finished_at']=time();$this->write($this->dir.'/job.json',$job);
            }
            return ['ok'=>true,'version'=>$this->version(),'job'=>$job['status']??'idle','catalog_error'=>$state['error']??null];
        });
    }
    public static function allowed(string $path): bool
    {
        if($path==='public/.htaccess')return true;
        if(!preg_match('#^[A-Za-z0-9_/-]+\.[A-Za-z0-9]+$#D',$path)||str_contains($path,'..'))return false;
        return str_starts_with($path,'app/')||str_starts_with($path,'public/theme/')||in_array($path,['config/app.php','public/index.php','public/service-worker.js'],true)||preg_match('#^scripts/(system-update-worker|notification-worker|web-push-keygen)\.php$#D',$path)===1||preg_match('#^database/migrations/0(?:23|24|25|26)_[a-z_]+\.sql$#D',$path)===1;
    }
    private function authorizeActor(int $id): void
    {
        // The 1.0 baseline predates is_demo; bootstrap still checks the real active owner's permissions.
        $columns=$this->db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $user=$this->db->prepare('SELECT id FROM users WHERE id=? AND active=1'.(in_array('is_demo',$columns,true)?' AND is_demo=0':''));$user->execute([$id]);
        if(!$user->fetchColumn())throw new RuntimeException('The requesting administrator is no longer active.');
        $query=$this->db->prepare('SELECT DISTINCT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN roles r ON r.id=rp.role_id AND r.active=1 JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=?');$query->execute([$id]);$permissions=$query->fetchAll(PDO::FETCH_COLUMN);
        if(!in_array('system.owner',$permissions,true)&&(!in_array('system.manage',$permissions,true)||!in_array('extensions.manage',$permissions,true)))throw new RuntimeException('The requesting administrator no longer has update permissions.');
    }
    public function verifyArchive(string $archive,array $release): array
    {
        $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Invalid core archive.');
        try{
            $raw=$zip->getFromName('sensecms-core.json');$signature=base64_decode((string)$zip->getFromName('signature.ed25519'),true);
            if(!is_string($raw)||strlen($raw)>2097152||!is_string($signature)||strlen($signature)!==64||!sodium_crypto_sign_verify_detached($signature,$raw,OfficialCatalog::key()))throw new RuntimeException('Core package signature verification failed.');
            $manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(($manifest['schema']??0)!==1||($manifest['type']??'')!=='core'||($manifest['version']??'')!==($release['version']??'')||($manifest['engine']??'')!==$this->config['engine_version']||($manifest['channel']??'')!==($release['channel']??'')||!in_array($manifest['channel']??'', ['beta','stable'],true)||!version_compare(PHP_VERSION,(string)($manifest['php']??'99'),'>='))throw new RuntimeException('This release is not compatible with this installation.');
            $files=$manifest['files']??[];if(!$files||count($files)>3000||$zip->numFiles!==count($files)+2)throw new RuntimeException('Invalid core inventory.');
            foreach(['app/release.json','public/index.php','app/Core/SystemUpdate.php'] as $required)if(!isset($files[$required]))throw new RuntimeException('Incomplete core inventory.');
            $seen=[];$total=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i);$name=$stat['name'];if(isset($seen[$name]))throw new RuntimeException('Duplicate ZIP entry.');$seen[$name]=true;$total+=(int)$stat['size'];
                $zip->getExternalAttributesIndex($i,$opsys,$attributes);if(($attributes>>16&0170000)===0120000)throw new RuntimeException('Symbolic links are not allowed.');
                if($total>134217728||$stat['size']>16777216)throw new RuntimeException('Core archive exceeds the safety limit.');
                if(in_array($name,['sensecms-core.json','signature.ed25519'],true))continue;
                $path=substr($name,8);if(!str_starts_with($name,'payload/')||!self::allowed($path)||!isset($files[$path])||!preg_match('/^[a-f0-9]{64}$/D',$files[$path]))throw new RuntimeException('Unsafe core archive path.');
                $contents=$zip->getFromIndex($i);if(!is_string($contents)||!hash_equals($files[$path],hash('sha256',$contents)))throw new RuntimeException('Core payload integrity verification failed.');
            }
            $record=json_decode((string)$zip->getFromName('payload/app/release.json'),true);if(($record['version']??'')!==$manifest['version'])throw new RuntimeException('Core version metadata does not match.');
            return $manifest;
        }finally{$zip->close();}
    }
    private function install(string $archive,array $release,array $job): string
    {
        $manifest=$this->verifyArchive($archive,$release);
        $check=$this->db->prepare('SELECT 1 FROM migrations WHERE name=?');$check->execute(['022_comprehensive_seo.sql']);if(!$check->fetchColumn())throw new RuntimeException('Upgrade to the supported 1.0 migration baseline first.');
        foreach(['curl','openssl','sodium','zip','pdo_mysql','mbstring','zlib'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Missing PHP extension: '.$extension);
        $paths=array_keys($manifest['files']);sort($paths);$paths=array_values(array_diff($paths,['public/index.php']));$paths[]='public/index.php';
        foreach($paths as $path)$this->target($path);
        if(disk_free_space($this->dir)<268435456+filesize($archive)*6)throw new RuntimeException('At least 256 MB of recovery space is required.');
        $recovery=$this->dir.'/recovery-'.$job['id'];if(!mkdir($recovery,0700))throw new RuntimeException('Cannot create recovery directory.');
        $zip=new ZipArchive();$zip->open($archive);$backup=new ZipArchive();if($backup->open($recovery.'/files.zip',ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create recovery archive.');
        $inventory=[];foreach($paths as $path){$target=$this->target($path);$inventory[$path]=is_file($target)?hash_file('sha256',$target):null;if(is_file($target)&&!$backup->addFile($target,$path))throw new RuntimeException('Cannot back up '.$path);}
        if(!$backup->close())throw new RuntimeException('Recovery archive could not be finalized.');chmod($recovery.'/files.zip',0600);$this->write($recovery.'/inventory.json',$inventory);
        $this->write($this->dir.'/maintenance.json',['job'=>$job['id'],'recovery'=>basename($recovery),'started_at'=>time()]);
        try{
            $this->snapshotDatabase($recovery.'/database.sql.gz');
            foreach($paths as $path){$target=$this->target($path);if(!is_dir(dirname($target))&&!mkdir(dirname($target),0755,true))throw new RuntimeException('Cannot create core directory.');$this->writeRaw($target,(string)$zip->getFromName('payload/'.$path),0644);if(function_exists('opcache_invalidate'))opcache_invalidate($target,true);}
            foreach(['023_demo_user_read_only.sql','024_system_notifications.sql','025_web_push.sql','026_email_system.sql'] as $name){$check->execute([$name]);if($check->fetchColumn())continue;$sql=(string)file_get_contents($this->root.'/database/migrations/'.$name);foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)$this->db->exec($statement);$this->db->prepare('INSERT INTO migrations(name) VALUES(?)')->execute([$name]);}
            $this->db->query('SELECT is_demo FROM users LIMIT 1');$this->db->query('SELECT 1 FROM notification_events LIMIT 1');$this->db->query('SELECT 1 FROM web_push_subscriptions LIMIT 1');
            $this->db->prepare('INSERT INTO activity_log(user_id,event,subject_type,context,created_at) VALUES(?,"system.updated","system",?,NOW())')->execute([$job['user_id'],json_encode(['version'=>$manifest['version'],'recovery'=>basename($recovery)])]);
            unlink($this->dir.'/maintenance.json');
        }catch(Throwable $error){
            // These migrations are additive and backward compatible. Never undo user data automatically.
            try{$old=new ZipArchive();if($old->open($recovery.'/files.zip')!==true)throw new RuntimeException('Recovery archive cannot be opened.');foreach($inventory as $path=>$hash){$target=$this->target($path);if($hash===null){if(is_file($target))unlink($target);}else{$bytes=$old->getFromName($path);if(!is_string($bytes)||!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('Recovery integrity failure.');$this->writeRaw($target,$bytes,0644);}if(function_exists('opcache_invalidate'))opcache_invalidate($target,true);}$old->close();unlink($this->dir.'/maintenance.json');}
            catch(Throwable){throw new RuntimeException('Automatic file recovery requires administrator attention. Maintenance mode remains enabled. Recovery: '.basename($recovery),0,$error);}
            throw new RuntimeException('Update failed; previous core files were restored. Recovery: '.basename($recovery),0,$error);
        }finally{$zip->close();}
        return basename($recovery);
    }
    private function snapshotDatabase(string $path): void
    {
        $stream=gzopen($path,'wb6');if(!$stream)throw new RuntimeException('Cannot create database recovery snapshot.');chmod($path,0600);
        $write=static function(string $line)use($stream):void{if(gzwrite($stream,$line)!==strlen($line))throw new RuntimeException('Database snapshot write failed.');};
        try{
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();$write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            $tables=$this->db->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
            foreach($tables as $table){$id='`'.str_replace('`','``',$table).'`';$definition=$this->db->query('SHOW CREATE TABLE '.$id)->fetch(PDO::FETCH_NUM)[1];$write($definition.";\n");$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$rows=$this->db->query('SELECT * FROM '.$id);while($row=$rows->fetch(PDO::FETCH_ASSOC)){$columns=implode(',',array_map(static fn($key)=>'`'.str_replace('`','``',$key).'`',array_keys($row)));$values=implode(',',array_map(fn($value)=>$value===null?'NULL':$this->db->quote((string)$value),array_values($row)));$write('INSERT INTO '.$id.' ('.$columns.') VALUES ('.$values.");\n");}$rows->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
            $write("SET FOREIGN_KEY_CHECKS=1;\n");$this->db->commit();
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}finally{$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);gzclose($stream);}
        $this->write($path.'.sha256.json',['sha256'=>hash_file('sha256',$path),'restore'=>'Import into an empty recovery database; preserve new production writes before switching.']);
    }
    private function target(string $path): string
    {
        if(!self::allowed($path))throw new RuntimeException('Unsafe update target.');$cursor=$this->root;
        foreach(explode('/',$path) as $part){$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('Update target contains a symbolic link.');}
        return $cursor;
    }
    private function read(string $path): array { $data=is_file($path)?json_decode((string)file_get_contents($path),true):[];return is_array($data)?$data:[]; }
    private function write(string $path,array $data): void { $this->writeRaw($path,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
    private function writeRaw(string $path,string $data,int $mode=0600): void { $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($temp,$data,LOCK_EX)!==strlen($data)||!chmod($temp,$mode)||!rename($temp,$path))throw new RuntimeException('Cannot safely write update data.'); }
    private function locked(callable $operation): mixed { $handle=fopen($this->dir.'/worker.lock','c');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('The update worker is busy. Try again shortly.');try{return $operation();}finally{flock($handle,LOCK_UN);fclose($handle);} }
}
