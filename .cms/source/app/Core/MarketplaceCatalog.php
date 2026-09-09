<?php

declare(strict_types=1);

namespace App\Core;

final class MarketplaceCatalog
{
    public static function build(
        string $engineVersion,
        array $themes,
        array $plugins,
        array $addons,
        array $packages,
        array $installedThemes,
        array $installedPlugins,
        array $input = [],
        array $remote = []
    ): array {
        $packageMap = [];
        foreach ($packages as $package) $packageMap[(string)$package['type'].':'.(string)$package['slug']] = $package;
        $items = [];
        foreach ($themes as $slug => $manifest) {
            $installed = $installedThemes[$slug] ?? [];
            $items[] = self::item('theme', (string)$slug, $manifest, $packageMap['theme:'.$slug] ?? [], [
                'installed'=>isset($installedThemes[$slug]),
                'active'=>(bool)($installed['active']??false),
                'group'=>(string)($manifest['category']??'Presentation themes'),
                'icon'=>'panels-top-left',
                'config_url'=>'/appearance/themes',
                'preview_url'=>(string)($manifest['preview_url']??'/en/home'),
            ], $engineVersion);
        }
        foreach ($plugins as $slug => $manifest) {
            $installed = $installedPlugins[$slug] ?? [];
            $items[] = self::item('plugin', (string)$slug, $manifest, $packageMap['plugin:'.$slug] ?? [], [
                'installed'=>isset($installedPlugins[$slug]),
                'active'=>(bool)($installed['active']??false),
                'group'=>(string)($manifest['group']??'Integrations'),
                'icon'=>(string)($manifest['icon']??($slug==='ai-chat'?'bot-message-square':'plug')),
                'config_url'=>(string)($manifest['config_url']??($slug==='ai-chat'?'/conversations/configuration':'/system/extensions')),
            ], $engineVersion);
        }
        foreach ($addons as $addon) {
            $slug=(string)($addon['slug']??'');if($slug==='')continue;
            $items[] = self::item('addon', $slug, $addon, $packageMap['addon:'.$slug] ?? [], [
                'installed'=>true,
                'active'=>(string)($addon['status']??'')==='active',
                'group'=>(string)($addon['group']??'Extensions'),
                'icon'=>(string)($addon['icon']??'blocks'),
                'config_url'=>(string)($addon['config_url']??'/system/extensions'),
            ], $engineVersion);
        }
        $known=array_fill_keys(array_column($items,'identity'),true);
        foreach ($packageMap as $identity => $package) {
            if (isset($known[$identity])) continue;
            $manifest=(array)($package['manifest']??[]); $isTheme = $package['type'] === 'theme';
            $items[] = self::item((string)$package['type'],(string)$package['slug'],$manifest,$package,[
                'installed'=>true,'active'=>(bool)$package['active'],'group'=>(string)($manifest['group']??($isTheme ? 'Presentation themes' : 'Extensions')),
                'icon'=>(string)($manifest['icon']??($isTheme ? 'panels-top-left' : 'package')),'config_url'=>$isTheme ? '/appearance/themes' : (string)($manifest['config_url']??'/system/extensions'),
            ],$engineVersion);
        }
        foreach ($remote as $entry) {
            $slug = (string) ($entry['slug'] ?? ''); $type = (string) ($entry['type'] ?? '');
            if ($slug === '' || !in_array($type, ['theme', 'plugin', 'addon'], true)) continue;
            $identity = $type . ':' . $slug; $manifest = (array) ($entry['manifest'] ?? []);
            $manifest = array_replace($manifest, ['slug' => $slug, 'version' => (string) $entry['version'], 'release_channel' => (string) $entry['release_channel']]);
            $officialId = preg_match('/^[a-f0-9]{32}$/D', (string) ($entry['official_id'] ?? '')) ? (string) $entry['official_id'] : null;
            $catalogId = $officialId === null ? max(0, (int) ($entry['id'] ?? 0)) : null;
            $matched = false;
            foreach ($items as &$existing) if ($existing['identity'] === $identity) {
                $matched = true;
                if ($existing['installed']) {
                    if (($entry['release_channel'] ?? 'stable') !== $existing['release_channel'] || version_compare((string) $entry['version'], $existing['installed_version'], '<=')) break;
                    if ($existing['available_version'] !== null && (version_compare((string) $entry['version'], $existing['available_version'], '<') || ((string) $entry['version'] === $existing['available_version'] && ($existing['official_id'] || $existing['catalog_entry_id'])))) break;
                }
                $existing['official_id'] = $officialId;
                $existing['catalog_entry_id'] = $catalogId ?: null;
                $existing['price_model'] = in_array($manifest['price_model'] ?? '', ['free', 'paid'], true) ? $manifest['price_model'] : $existing['price_model'];
                $existing['price_label'] = trim((string) ($manifest['price_label'] ?? '')) ?: $existing['price_label'];
                if (trim((string) ($manifest['description'] ?? '')) !== '') $existing['description'] = (string) $manifest['description'];
                if ($existing['installed'] && version_compare((string) $entry['version'], (string) $existing['installed_version'], '>')) { $existing['available_version'] = (string) $entry['version']; $existing['update_available'] = true; }
                break;
            }
            unset($existing);
            if ($matched) continue;
            $remoteItem = self::item($type, $slug, $manifest, ['source' => 'package', 'publisher' => $entry['publisher'] ?? 'QUANT Software House', 'publisher_url' => $entry['publisher_url'] ?? '', 'version' => $entry['version'], 'signature_status' => 'catalog-verified'], ['installed' => false, 'active' => false, 'group' => (string) ($manifest['category'] ?? $manifest['group'] ?? 'Marketplace'), 'icon' => (string) ($manifest['icon'] ?? 'package'), 'config_url' => '/marketplace', 'preview_url' => $manifest['preview_url'] ?? ''], $engineVersion);
            $remoteItem['official_id'] = $officialId; $remoteItem['catalog_entry_id'] = $catalogId;
            $items[] = $remoteItem;
        }

        usort($items, static fn(array$a,array$b):int=>[$a['featured']?0:1,$a['name']]<=>[$b['featured']?0:1,$b['name']]);
        $all=$items;$q=mb_strtolower(mb_substr(trim((string)($input['q']??'')),0,120));$type=(string)($input['type']??'all');$status=(string)($input['status']??'all');$price=(string)($input['price']??'all');$group=mb_substr((string)($input['group']??'all'),0,120);
        $items=array_values(array_filter($items,static function(array$item)use($q,$type,$status,$price,$group):bool{
            if($q!==''&&!str_contains(mb_strtolower(implode(' ',[$item['name'],$item['description'],$item['publisher'],$item['group'],implode(' ',$item['tags'])])),$q))return false;
            if($type!=='all'&&$item['type']!==$type)return false;
            if($price!=='all'&&$item['price_model']!==$price)return false;
            if($group!=='all'&&$item['group']!==$group)return false;
            return match($status){'active'=>$item['active'],'installed'=>$item['installed'],'updates'=>$item['update_available'],'verified'=>$item['trust']==='verified',default=>true};
        }));
        $sort=(string)($input['sort']??'featured');
        usort($items,static fn(array$a,array$b):int=>match($sort){'name'=>strnatcasecmp($a['name'],$b['name']),'newest'=>version_compare($b['version'],$a['version']),'publisher'=>strnatcasecmp($a['publisher'],$b['publisher']),default=>[$a['featured']?0:1,$a['active']?0:1,$a['name']]<=>[$b['featured']?0:1,$b['active']?0:1,$b['name']]});
        $page=max(1,(int)($input['page']??1));$perPage=max(6,min(24,(int)($input['per_page']??12)));$total=count($items);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        return [
            'items'=>array_slice($items,($page-1)*$perPage,$perPage),
            'summary'=>['total'=>count($all),'themes'=>count(array_filter($all,fn($i)=>$i['type']==='theme')),'installed'=>count(array_filter($all,fn($i)=>$i['installed'])),'active'=>count(array_filter($all,fn($i)=>$i['active'])),'updates'=>count(array_filter($all,fn($i)=>$i['update_available'])),'verified'=>count(array_filter($all,fn($i)=>$i['trust']==='verified'))],
            'facets'=>['groups'=>array_values(array_unique(array_column($all,'group'))),'publishers'=>array_values(array_unique(array_column($all,'publisher')))],
            'pagination'=>['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'total'=>$total],
            'filters'=>compact('q','type','status','price','group','sort'),
        ];
    }

    private static function item(string$type,string$slug,array$manifest,array$package,array$state,string$engineVersion):array
    {
        $managed = !empty($package['managed_releases']);
        if (array_key_exists('active', $package)) { $state['installed'] = true; $state['active'] = $package['active']; }
        if ($managed) { $manifest = $package['manifest']; $state['installed'] = true; $state['active'] = $package['active']; }
        $installedVersion = (string) ($package['installed_version'] ?? $package['version'] ?? $manifest['version'] ?? '0.0.0');
        $constraint=(string)($manifest['compatible_engine_version']??$manifest['engine']??$package['manifest']['engine']??'^1.0');$source=(string)($package['source']??'bundled');$available=(string)($package['available_version']??'');
        $supported=array_values(array_filter(array_map('strval',(array)($manifest['supported_blocks']??[]))));$permissions=array_values(array_filter(array_map('strval',(array)($manifest['permissions']??[]))));
        $features=array_values(array_filter(array_map('strval',(array)($manifest['features']??[]))));if(!$features){if($type==='theme')$features=['Responsive presentation','Multilingual content','Page Builder compatible'];elseif($permissions)$features=array_slice(array_map(static fn(string$value):string=>ucwords(str_replace(['.','-'],' ',$value)),$permissions),0,4);else$features=['Managed CMS component'];}
        $configUrl=self::path((string)$state['config_url'],'/system/extensions');$priceModel=in_array($manifest['price_model']??'', ['free','paid'],true)?(string)$manifest['price_model']:'free';
        return [
            'identity'=>$type.':'.$slug,'type'=>$type,'slug'=>$slug,'name'=>(string)($manifest['name']??$package['name']??$slug),'description'=>(string)($manifest['description']??$package['description']??''),
            'publisher'=>(string)($package['publisher']??$manifest['author']??$manifest['publisher']??'QUANT Software House'),'publisher_url'=>(string)($package['publisher_url']??$manifest['publisher_url']??''),'version'=>(string)($package['version']??$manifest['version']??'1.0.0'),'source'=>$source,
            'installed_version'=>$installedVersion,'pending_version'=>$package['pending_version']??null,'managed_releases'=>$managed,
            'available_version'=>$available?:null,'update_available'=>$available!==''&&version_compare($available,$installedVersion,'>'),'installed'=>(bool)$state['installed'],'active'=>(bool)$state['active'],
            'trust'=>$source==='package'?(in_array($package['signature_status']??'', ['verified','catalog-verified'], true)?'verified':'unverified'):'distribution','signature_status'=>(string)($package['signature_status']??'distribution'),'compatible'=>(!$managed || ($package['signature_status']??'')==='verified')&&self::compatible($constraint,$engineVersion),'engine'=>$constraint,
            'group'=>(string)$state['group'],'icon'=>(string)$state['icon'],'config_url'=>$configUrl,'configurable'=>(bool)$state['installed']&&($type==='theme'||!in_array($configUrl,['','/system/extensions','/marketplace'],true)),'uninstallable'=>(bool)$state['installed']&&$source==='package'&&!$managed,'price_model'=>$priceModel,'price_label'=>trim((string)($manifest['price_label']??''))?:($priceModel==='free'?'Free':'Paid'),'preview_url'=>self::path((string)($state['preview_url']??'')),'screenshot'=>self::path((string)($manifest['screenshot_url']??$manifest['screenshot']??'')),
            'permissions'=>$permissions,'dependencies'=>array_values((array)($manifest['dependencies']??[])),'routes'=>array_values(array_filter(array_map('strval',(array)($manifest['routes']??[])))),'supported_blocks'=>$supported,
            'features'=>$features,'tags'=>array_values(array_unique(array_filter(array_merge([(string)$state['group'],$type],array_map('strval',(array)($manifest['tags']??[])),$features)))),'featured'=>(bool)($manifest['featured']??$type==='theme'),
            'installed_at'=>$package['installed_at']??null,'last_error'=>$package['last_error']??null,'rollback_count'=>(int)($package['rollback_count']??0),
            'catalog_entry_id'=>null,'official_id'=>null,
            'contract_version'=>$type==='theme'?(int)($manifest['contract_version']??0):null,'parent'=>$type==='theme'?((string)($manifest['parent']??'')?:null):null,'release_channel'=>(string)($manifest['release_channel']??$package['manifest']['release_channel']??'stable'),'license'=>is_array($manifest['license']??null)?$manifest['license']:(is_array($package['manifest']['license']??null)?$package['manifest']['license']:[]),'changelog'=>array_values(array_filter((array)($manifest['changelog']??$package['manifest']['changelog']??[]),'is_array')),'regions'=>$type==='theme'?array_values((array)($manifest['regions']??[])):[],'configuration_count'=>$type==='theme'?count((array)($manifest['configuration']??[])):0,
        ];
    }

    private static function compatible(string$constraint,string$version):bool
    {
        if (preg_match('/^>=(\d+\.\d+\.\d+) <(\d+\.\d+\.\d+)$/D', $constraint, $range)) return version_compare($version, $range[1], '>=') && version_compare($version, $range[2], '<');
        if($constraint===''||$constraint==='*')return true;$version=self::version($version);if(preg_match('/^\^(\d+)\.(\d+)/',$constraint,$match))return(int)explode('.',$version)[0]===(int)$match[1]&&version_compare($version,$match[1].'.'.$match[2].'.0','>=');
        if(preg_match('/^>=(\d+(?:\.\d+){1,2})$/',$constraint,$match))return version_compare($version,self::version($match[1]),'>=');return version_compare($version,self::version($constraint),'==');
    }

    private static function version(string$value):string
    {
        $parts=explode('.',preg_replace('/[^0-9.].*$/','',$value)?:'0');return implode('.',array_pad(array_slice($parts,0,3),3,'0'));
    }

    private static function path(string$value,string$fallback=''):string
    {
        return str_starts_with($value,'/')&&!str_starts_with($value,'//')&&preg_match('#^/[A-Za-z0-9_./?=&%+-]*$#',$value)?$value:$fallback;
    }
}
