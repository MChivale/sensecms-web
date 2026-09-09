<?php
declare(strict_types=1);

namespace App\Core\Packages;

use App\Core\LicenseClient;
use App\Core\Runtime;
use RuntimeException;

/** Optional distribution host. Inventory is private operator configuration, never CMS copy. */
final class Distribution
{
    private readonly array $config;
    public function __construct(private readonly Runtime $runtime)
    {
        $this->config=$runtime->read('distribution');
        if (($this->config['enabled']??false)!==true) throw new RuntimeException('Package distribution is not available.',404);
    }

    public function offers(): array
    {
        $offers=[];
        foreach ($this->config['products']??[] as $id=>$entry) {
            if (($entry['enabled']??false)!==true) continue;
            $entry=$this->entry($id);
            $offers[$id]=array_intersect_key($entry,array_flip(['type','slug','version','pricing','sha256','bytes','channel']));
        }
        return $offers;
    }

    public function entry(string $id): array
    {
        $entry=$this->config['products'][$id]??null;
        if (!is_array($entry) || ($entry['enabled']??false)!==true) throw new RuntimeException('This package is not available to download.',404);
        if (!preg_match('~^(theme|addon|plugin|module):[a-z0-9-]+$~D',$id)
            || $id!==($entry['type']??'').':'.($entry['slug']??'')
            || !preg_match('/^\d+\.\d+\.\d+$/D',$entry['version']??'')
            || !preg_match('/^[a-f0-9]{64}$/D',$entry['sha256']??'')
            || !is_int($entry['bytes']??null) || $entry['bytes']<1 || $entry['bytes']>26214400
            || !in_array($entry['channel']??'', ['development','stable'],true)
            || !preg_match('/^[a-z0-9][a-z0-9.-]*\.zip$/D',$entry['file']??'')) throw new RuntimeException('Invalid distribution inventory.',503);
        Entitlement::licenseConfig($entry,(require $this->runtime->root.'/config/product.php')['license']);
        return $entry;
    }

    public function archive(string $id): array
    {
        $entry=$this->entry($id);
        $base=$this->runtime->root.'/storage/distribution/releases';
        $path=$base.'/'.$entry['file'];
        if (is_link($base) || is_link($path) || !is_file($path) || filesize($path)!==$entry['bytes']) throw new RuntimeException('Package archive is unavailable.',503);
        $keys=array_map(static fn($key)=>base64_decode($key,true),$this->config['publishers']??[]);
        $manifest=Archive::verify($path,$keys);
        if (Manifest::identity($manifest)!==$id || $manifest['version']!==$entry['version']) throw new RuntimeException('Package identity mismatch.',503);
        $bytes=file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes)!==$entry['bytes'] || !hash_equals($entry['sha256'],hash('sha256',$bytes))) throw new RuntimeException('Package integrity check failed.',503);
        return [$entry,$bytes];
    }

    public function rate(string $ip): void
    {
        // Fixed-size buckets: no attacker-controlled filenames or unbounded records.
        $path=$this->runtime->root.'/storage/distribution/rate.json';
        if (is_link($path)) throw new RuntimeException('Distribution limiter unavailable.',503);
        $handle=fopen($path,'c+b');
        if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Distribution limiter unavailable.',503);
        try {
            $raw=stream_get_contents($handle,65537);
            if (strlen($raw)>65536) throw new RuntimeException('Distribution limiter unavailable.',503);
            $state=$raw===''?[]:json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            $now=time(); $slot=substr(hash('sha256',$ip),0,2);
            foreach (['global'=>120,$slot=>10] as $key=>$limit) {
                $record=$state[$key]??['at'=>$now,'count'=>0];
                if ($record['at']>$now || $record['at']+900<=$now) $record=['at'=>$now,'count'=>0];
                if ($record['count']>=$limit) throw new RuntimeException('Too many attempts. Please try again in 15 minutes.',429);
                $record['count']++; $state[$key]=$record;
            }
            $json=json_encode($state,JSON_THROW_ON_ERROR); rewind($handle);
            if (!ftruncate($handle,0) || fwrite($handle,$json)!==strlen($json) || !fflush($handle)) throw new RuntimeException('Distribution limiter unavailable.',503);
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }

    public function download(string $id, #[\SensitiveParameter] string $key, string $domain): array
    {
        $entry=$this->entry($id);
        $cms=(require $this->runtime->root.'/config/product.php')['license'];
        $config=Entitlement::licenseConfig($entry,$cms);
        LicenseClient::assertKey($key); $domain=LicenseClient::domain($domain);
        [$entry,$bytes]=$this->archive($id);
        // Always live, exact-product validation; keys are never cached or logged here.
        (new LicenseClient($config))->validate($key,$domain);
        return [$entry,$bytes];
    }
}
