<?php

declare(strict_types=1);

namespace App\Core;

final class PageBuilder
{
    public static function catalog(array $theme, array $plugins = [], array $activePlugins = [], array $addons = [], array $activeAddons = []): array
    {
        $catalog = self::definitions((array) ($theme['builder']['blocks'] ?? []), 'theme:' . (string) ($theme['slug'] ?? 'theme'), 'Theme sections');
        $system = require dirname(__DIR__, 2) . '/config/page-builder.php';
        $catalog = array_replace($catalog, self::definitions(is_array($system) ? $system : [], 'core', 'SenseCMS modules'));
        if (isset($theme['supported_blocks'])) $catalog = array_intersect_key($catalog, array_fill_keys((array) $theme['supported_blocks'], true));
        foreach ($plugins as $slug => $plugin) {
            if (!in_array($slug, $activePlugins, true)) continue;
            $catalog = array_replace($catalog, self::definitions((array) ($plugin['builder_components'] ?? []), 'plugin:' . $slug, 'Installed plugins'));
        }
        foreach ($addons as $slug => $addon) {
            if (!in_array($slug, $activeAddons, true)) continue;
            $catalog = array_replace($catalog, self::definitions((array) ($addon['builder_components'] ?? []), 'addon:' . $slug, 'Installed add-ons'));
        }
        return $catalog;
    }

    public static function systemTypes(): array
    {
        $definitions = require dirname(__DIR__, 2) . '/config/page-builder.php';
        return array_keys(is_array($definitions) ? $definitions : []);
    }

    public static function sanitizeBlocks(array $input, array $catalog, array $locales): array
    {
        if (count($input) > 60) throw new \RuntimeException('A page can contain up to 60 sections.');
        $blocks = []; $uids = []; $singletons = [];
        foreach ($input as $block) {
            if (!is_array($block)) continue;
            $uid = strtolower(trim((string) ($block['uid'] ?? '')));
            $type = strtolower(trim((string) ($block['type'] ?? '')));
            if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uid) || isset($uids[$uid])) throw new \RuntimeException('A section identifier is invalid or duplicated.');
            if (!isset($catalog[$type])) throw new \RuntimeException('This section type is unavailable in the current SenseCMS installation.');
            if ($catalog[$type]['singleton'] && isset($singletons[$type])) throw new \RuntimeException($catalog[$type]['label'] . ' can only be added once.');
            $uids[$uid] = true; $singletons[$type] = true;
            $shared = self::sanitizeData(is_array($block['shared'] ?? null) ? $block['shared'] : [], $catalog[$type]['shared_fields']);
            $localized = [];
            foreach ($locales as $locale) {
                $data = is_array($block['localized'][$locale] ?? null) ? $block['localized'][$locale] : [];
                $localized[$locale] = self::sanitizeData($data, $catalog[$type]['fields']);
                self::validateComponent($type, $localized[$locale], $shared, (string) $locale);
            }
            $visibleFrom = self::dateTime($block['visible_from'] ?? null, 'The section start date is invalid.');
            $visibleUntil = self::dateTime($block['visible_until'] ?? null, 'The section end date is invalid.');
            if ($visibleFrom !== null && $visibleUntil !== null && $visibleFrom >= $visibleUntil) throw new \RuntimeException('The section end date must be later than its start date.');
            $blocks[] = [
                'uid'=>$uid,
                'type'=>$type,
                'source'=>$catalog[$type]['source'],
                'global_section_id'=>max(0, (int) ($block['global_section_id'] ?? 0)) ?: null,
                'global_version'=>max(0, (int) ($block['global_version'] ?? 0)),
                'visible'=>(bool)($block['visible']??false),
                'visible_from'=>$visibleFrom,
                'visible_until'=>$visibleUntil,
                'shared'=>$shared,
                'localized'=>$localized,
            ];
        }
        return $blocks;
    }

    private static function definitions(array $input, string $source, string $fallbackGroup): array
    {
        $catalog = [];
        foreach ($input as $type => $definition) {
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9-]{1,79}$/', $type) || !is_array($definition)) continue;
            $fields = self::fields((array) ($definition['fields'] ?? []));
            $sharedFields = self::fields((array) ($definition['shared_fields'] ?? []));
            if (!$fields && !$sharedFields) continue;
            $defaultFields=array_map(static fn(array$field):array=>array_replace($field,['required'=>false]),$fields);
            $defaultSharedFields=array_map(static fn(array$field):array=>array_replace($field,['required'=>false]),$sharedFields);
            $defaults=self::sanitizeData((array)($definition['defaults']??[]),$defaultFields);
            $localizedDefaults=[];
            foreach((array)($definition['localized_defaults']??[]) as$locale=>$localizedDefault){
                if(!is_string($locale)||!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/',$locale)||!is_array($localizedDefault))continue;
                $localizedDefaults[$locale]=self::sanitizeData(array_replace_recursive($defaults,$localizedDefault),$defaultFields);
            }
            $catalog[$type] = [
                'label'=>mb_substr(trim((string)($definition['label']??$type)),0,80), 'description'=>mb_substr(trim((string)($definition['description']??'')),0,220),
                'icon'=>preg_match('/^[a-z0-9-]{2,60}$/',(string)($definition['icon']??''))?$definition['icon']:'panel-top', 'group'=>mb_substr(trim((string)($definition['group']??$fallbackGroup)),0,80),
                'singleton'=>(bool)($definition['singleton']??false), 'source'=>$source, 'source_label'=>self::sourceLabel($source), 'fields'=>$fields, 'shared_fields'=>$sharedFields,
                'defaults'=>$defaults, 'localized_defaults'=>$localizedDefaults, 'shared_defaults'=>self::sanitizeData((array)($definition['shared_defaults']??[]),$defaultSharedFields),
                'renderer'=>self::safeRenderer((string)($definition['renderer']??''),$source),
            ];
        }
        return $catalog;
    }

    private static function fields(array $input): array
    {
        $fields = [];
        foreach ($input as $field) {
            if (!is_array($field)) continue;
            $key=(string)($field['key']??''); $type=(string)($field['type']??'text');
            if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/',$key) || !in_array($type,['text','textarea','url','email','image','video','number','checkbox','select','code','repeater'],true)) continue;
            $clean=['key'=>$key,'type'=>$type,'label'=>mb_substr(trim((string)($field['label']??$key)),0,100),'required'=>(bool)($field['required']??false),'wide'=>(bool)($field['wide']??false)];
            if(is_array($field['show_when']??null)&&count($field['show_when'])===1){$conditionKey=(string)array_key_first($field['show_when']);$conditionValue=(string)$field['show_when'][$conditionKey];if(preg_match('/^[a-z][a-z0-9_]{1,79}$/',$conditionKey)&&preg_match('/^[a-z0-9_-]{1,50}$/',$conditionValue))$clean['show_when']=[$conditionKey=>$conditionValue];}
            if ($type==='repeater') {
                $clean['min']=max(0,min(12,(int)($field['min']??0))); $clean['max']=max(1,min(12,(int)($field['max']??6))); $clean['item_label']=mb_substr(trim((string)($field['item_label']??'Item')),0,60); $clean['fields']=self::fields((array)($field['fields']??[])); if(!$clean['fields'])continue;
            } elseif ($type==='select') {
                $options=[]; foreach((array)($field['options']??[]) as$value=>$label)if(is_string($value)&&preg_match('/^[a-z0-9_-]{1,50}$/',$value))$options[$value]=mb_substr((string)$label,0,80); if(!$options)continue; $clean['options']=$options;
            } elseif ($type==='number') {
                $clean['min']=(int)($field['min']??0); $clean['max']=(int)($field['max']??10000); if($clean['max']<$clean['min'])[$clean['min'],$clean['max']]=[$clean['max'],$clean['min']];
            } elseif ($type!=='checkbox') $clean['max']=max(1,min(20000,(int)($field['max']??($type==='textarea'||$type==='code'?4000:500))));
            $fields[]=$clean;
        }
        return $fields;
    }

    private static function sanitizeData(array $input, array $fields): array
    {
        $data=[];
        foreach($fields as$field){
            $key=$field['key']; $value=$input[$key]??($field['type']==='repeater'?[]:($field['type']==='checkbox'?false:''));
            if($field['type']==='repeater'){
                $items=is_array($value)?array_slice(array_values($value),0,$field['max']):[];
                if(count($items)<$field['min'])throw new \RuntimeException($field['label'].' requires at least '.$field['min'].' items.');
                $data[$key]=array_map(static fn(mixed $item):array=>self::sanitizeData(is_array($item)?$item:[],$field['fields']),$items); continue;
            }
            if($field['type']==='checkbox'){$data[$key]=(bool)$value;continue;}
            if($field['type']==='number'){$data[$key]=max($field['min'],min($field['max'],(int)$value));continue;}
            $value=trim((string)$value);
            if($field['required']&&$value==='')throw new \RuntimeException($field['label'].' is required in every active language.');
            if($field['type']==='select'){if(!array_key_exists($value,$field['options']))$value=(string)array_key_first($field['options']);$data[$key]=$value;continue;}
            if(mb_strlen($value)>$field['max'])throw new \RuntimeException($field['label'].' exceeds its character limit.');
            if($field['type']==='email'&&$value!==''&&filter_var($value,FILTER_VALIDATE_EMAIL)===false)throw new \RuntimeException($field['label'].' must contain a valid email address.');
            if($value!==''&&in_array($field['type'],['url','image','video'],true)&&!self::validUrl($value,$field['type']==='url'))throw new \RuntimeException($field['label'].' must contain a safe URL or public media path.');
            $data[$key]=$field['type']==='code'?HtmlSanitizer::sanitize($value):$value;
        }
        return $data;
    }

    private static function validUrl(string $value,bool $allowAction):bool
    {
        if(str_starts_with($value,'/')&&!str_starts_with($value,'//'))return!str_contains($value,'..');
        if($allowAction&&(str_starts_with($value,'#')||str_starts_with($value,'mailto:')||str_starts_with($value,'tel:')))return true;
        return filter_var($value,FILTER_VALIDATE_URL)!==false&&in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true);
    }

    private static function dateTime(mixed $value, string $message): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T| )(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $parts) || (int) $parts[1] < 1000 || (int) $parts[1] > 9999) throw new \RuntimeException($message);
        foreach (['!Y-m-d\\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && \DateTimeImmutable::getLastErrors() === false) return $date->format('Y-m-d H:i:s');
        }
        throw new \RuntimeException($message);
    }

    private static function validateComponent(string $type,array $data,array $shared,string $locale):void
    {
        if($type==='hero'&&trim((string)($data[($data['media_type']??'video')==='image'?'image':'video']??''))==='')throw new \RuntimeException('Hero media is required for '.$locale.'.');
        if($type==='hero-slider')foreach((array)($data['slides']??[])as$index=>$slide)if(trim((string)($slide[($slide['media_type']??'image')==='video'?'video':'image']??''))==='')throw new \RuntimeException('Slide '.($index+1).' media is required for '.$locale.'.');
        if($type==='contact-form'){
            if(!empty($shared['sender_copy'])&&(empty($shared['store_submissions'])||empty($shared['captcha'])))throw new \RuntimeException('Sender copies require inbox storage and Sense CMS CAPTCHA.');
            if(empty($shared['store_submissions'])&&empty($shared['email_notifications']))throw new \RuntimeException('Contact Form must store submissions or send email notifications.');
            $keys=[];foreach((array)($data['fields']??[])as$field){$key=strtolower(trim((string)($field['key']??'')));if(!preg_match('/^[a-z][a-z0-9_]{1,39}$/',$key)||isset($keys[$key]))throw new \RuntimeException('Contact Form field keys must be unique lowercase identifiers in '.$locale.'.');$keys[$key]=true;if(($field['type']??'')==='select'&&count(array_filter(array_map('trim',preg_split('/\R/u',(string)($field['options']??''))?:[])))<1)throw new \RuntimeException('Every select field requires options in '.$locale.'.');}
        }
    }

    private static function sourceLabel(string $source):string{return $source==='core'?'SenseCMS':(str_starts_with($source,'plugin:')?'Plugin':(str_starts_with($source,'addon:')?'Add-on':'Theme'));}
    private static function safeRenderer(string $renderer,string $source):string{return (str_starts_with($source,'plugin:')||str_starts_with($source,'addon:'))&&preg_match('#^[a-z0-9/_-]+\.php$#',$renderer)&&!str_contains($renderer,'..')?$renderer:'';}
}
