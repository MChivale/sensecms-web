<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class ThemeContract
{
    public const VERSION = 1;
    private const TYPES = ['color','select','text','asset','number','toggle'];
    private const CHANNELS = ['stable','beta','preview','development'];

    public static function validateAll(array $themes): array
    {
        foreach ($themes as $slug => $theme) self::validate((string)$slug, $theme);
        $resolved=[];
        foreach ($themes as $slug => $_theme) $resolved[$slug]=self::resolve((string)$slug, $themes);
        return $resolved;
    }

    public static function validateRuntime(array $theme):void
    {
        self::validate((string)($theme['slug']??''),$theme);
    }

    public static function resolve(string $slug, array $themes, array $trail = []): array
    {
        if (!isset($themes[$slug])) throw new RuntimeException("Unknown theme: {$slug}");
        if (isset($trail[$slug])) throw new RuntimeException('Theme inheritance contains a circular parent chain.');
        $theme=$themes[$slug];$parent=(string)($theme['parent']??'');
        if ($parent==='') return self::resolvedPaths($theme);
        if (!isset($themes[$parent])) throw new RuntimeException("Theme {$slug} requires missing parent {$parent}.");
        $base=self::resolve($parent,$themes,$trail+[$slug=>true]);
        foreach (['configuration','regions'] as$key) $theme[$key]=self::mergeNamed((array)($base[$key]??[]),(array)($theme[$key]??[]),'key');
        $theme['design_tokens']=array_replace((array)($base['design_tokens']??[]),(array)($theme['design_tokens']??[]));
        foreach (['supported_blocks','features','tags','navigation_locations'] as$key) $theme[$key]=array_values(array_unique(array_merge((array)($base[$key]??[]),(array)($theme[$key]??[]))));
        $theme['_parent_path']=$base['_path'];$theme['_view_path']=is_file($theme['_path'].'/views/page.php')?$theme['_path'].'/views/page.php':$base['_view_path'];
        return $theme;
    }

    public static function defaults(array $theme): array
    {
        $result=[];foreach((array)($theme['configuration']??[])as$field)$result[(string)$field['key']]=$field['default']??match($field['type']){'toggle'=>false,'number'=>0,default=>''};return$result;
    }

    public static function sanitize(array $theme,array $input,array $base=[]):array
    {
        $result=array_replace(self::defaults($theme),$base);foreach((array)($theme['configuration']??[])as$field){$key=(string)$field['key'];if(!array_key_exists($key,$input))continue;$value=$input[$key];if(!is_scalar($value)&&$value!==null)throw new RuntimeException($field['label'].' must contain a scalar value.');$result[$key]=match($field['type']){
            'color'=>preg_match('/^#[0-9a-fA-F]{6}$/',(string)$value)?strtolower((string)$value):throw new RuntimeException($field['label'].' must be a six-digit hex colour.'),
            'select'=>in_array((string)$value,array_map('strval',array_keys((array)$field['options'])),true)?(string)$value:throw new RuntimeException('Choose a valid '.$field['label'].'.'),
            'text'=>mb_substr(trim((string)$value),0,max(1,min(500,(int)($field['max_length']??160)))),
            'asset'=>self::asset((string)$value,$field['label']),
            'number'=>max((float)($field['min']??0),min((float)($field['max']??100),(float)$value)),
            'toggle'=>filter_var($value,FILTER_VALIDATE_BOOL),
            default=>throw new RuntimeException('Unsupported theme setting type.'),
        };}return$result;
    }

    public static function cssTokens(array $theme,array $settings):array
    {
        $fields=[];foreach((array)($theme['configuration']??[])as$field)$fields[(string)$field['key']]=$field;$tokens=[];foreach((array)($theme['design_tokens']??[])as$token=>$setting){if(!preg_match('/^--[a-z][a-z0-9-]{1,80}$/',(string)$token)||!array_key_exists((string)$setting,$settings))continue;$value=(string)$settings[(string)$setting];if(($fields[(string)$setting]['type']??'')==='number')$value.=(string)($fields[(string)$setting]['unit']??'');if(preg_match('/[;{}<>]/',$value))continue;$tokens[(string)$token]=$value;}return$tokens;
    }

    public static function overrides(array $theme,array $settings,array $inherited,array $inherit=[]):array
    {
        $allowed=array_fill_keys(array_map(static fn(array$field):string=>(string)$field['key'],(array)($theme['configuration']??[])),true);$inherit=array_fill_keys(array_map('strval',$inherit),true);$result=[];
        foreach($settings as$key=>$value)if(isset($allowed[$key])&&!isset($inherit[$key])&&(!array_key_exists($key,$inherited)||$inherited[$key]!==$value))$result[$key]=$value;
        return$result;
    }

    private static function validate(string$slug,array$theme):void
    {
        if((int)($theme['contract_version']??0)!==self::VERSION)throw new RuntimeException("Theme {$slug} uses an unsupported contract version.");
        if(($theme['slug']??'')!==$slug||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug))throw new RuntimeException('Theme slug does not match its directory identity.');
        if(!preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/',(string)($theme['version']??'')))throw new RuntimeException("Theme {$slug} must use semantic versioning.");
        if(!in_array((string)($theme['release_channel']??'stable'),self::CHANNELS,true))throw new RuntimeException("Theme {$slug} has an unsupported release channel.");
        $parent=(string)($theme['parent']??'');if($parent!==''&&(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$parent)||$parent===$slug))throw new RuntimeException("Theme {$slug} has an invalid parent.");
        $keys=[];foreach((array)($theme['configuration']??[])as$field){$key=(string)($field['key']??'');$type=(string)($field['type']??'');if(!preg_match('/^[a-z][a-z0-9_]{1,79}$/',$key)||isset($keys[$key])||!in_array($type,self::TYPES,true)||trim((string)($field['label']??''))==='')throw new RuntimeException("Theme {$slug} contains an invalid configuration field.");if($type==='select'&&!is_array($field['options']??null))throw new RuntimeException("Theme {$slug} select field {$key} needs options.");if(isset($field['unit'])&&!in_array((string)$field['unit'],['px','rem','em','%','vh','vw'],true))throw new RuntimeException("Theme {$slug} field {$key} uses an invalid unit.");$keys[$key]=true;}
        foreach((array)($theme['design_tokens']??[])as$token=>$setting)if(!preg_match('/^--[a-z][a-z0-9-]{1,80}$/',(string)$token)||!isset($keys[(string)$setting]))throw new RuntimeException("Theme {$slug} contains an invalid design token mapping.");
        $regions=[];foreach((array)($theme['regions']??[])as$region){$key=(string)($region['key']??'');if(!preg_match('/^[a-z][a-z0-9-]{1,60}$/',$key)||isset($regions[$key])||trim((string)($region['label']??''))==='')throw new RuntimeException("Theme {$slug} contains an invalid region.");$regions[$key]=true;}
        if(!is_file($theme['_path'].'/views/page.php')&&$parent==='')throw new RuntimeException("Theme {$slug} does not provide a public page view.");
    }

    private static function resolvedPaths(array$theme):array{$theme['_view_path']=$theme['_path'].'/views/page.php';return$theme;}
    private static function mergeNamed(array$base,array$child,string$key):array{$map=[];foreach(array_merge($base,$child)as$item)if(is_array($item)&&isset($item[$key]))$map[(string)$item[$key]]=$item;return array_values($map);}
    private static function asset(string$value,string$label):string{$value=trim($value);if($value===''||(str_starts_with($value,'/')&&!str_starts_with($value,'//')&&preg_match('#^/[A-Za-z0-9_./%+-]+$#',$value)))return$value;throw new RuntimeException($label.' must use a safe internal asset path.');}
}
