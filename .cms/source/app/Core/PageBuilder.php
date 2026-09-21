<?php

declare(strict_types=1);

namespace App\Core;

final class PageBuilder
{
    public static function catalog(array $theme, array $plugins = [], array $activePlugins = [], array $addons = [], array $activeAddons = []): array
    {
        $catalog = self::definitions((array) ($theme['builder']['blocks'] ?? []), 'theme:' . (string) ($theme['slug'] ?? 'theme'), 'Theme sections');
        $system = require dirname(__DIR__, 2) . '/config/page-builder.php';
        $catalog = array_replace($catalog, self::definitions(is_array($system) ? $system : [], 'core', 'Core sections'));
        // Core sections are part of the portable CMS contract. A theme may style
        // them or add its own sections, but it must never remove Core editing
        // capabilities from the Workspace.
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

    public static function patterns(array $catalog): array
    {
        $definitions = require dirname(__DIR__, 2) . '/config/page-builder-patterns.php';
        $patterns = [];
        foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9-]{1,79}$/', $key) || !is_array($definition)) continue;
            $sections = array_values(array_filter(array_slice((array) ($definition['sections'] ?? []), 0, 20), static fn(mixed $type): bool => is_string($type) && isset($catalog[$type])));
            if (!$sections) continue;
            $patterns[$key] = [
                'label' => mb_substr(trim((string) ($definition['label'] ?? $key)), 0, 80),
                'description' => mb_substr(trim((string) ($definition['description'] ?? '')), 0, 240),
                'icon' => preg_match('/^[a-z0-9-]{2,60}$/', (string) ($definition['icon'] ?? 'layout-template')) ? $definition['icon'] : 'layout-template',
                'group' => mb_substr(trim((string) ($definition['group'] ?? 'Page layouts')), 0, 80),
                'sections' => $sections,
            ];
        }
        return $patterns;
    }

    public static function aiActions(array$plugins=[],array$activePlugins=[],array$addons=[],array$activeAddons=[]):array
    {
        $actions=[
            'generate-page'=>['label'=>'Generate page draft','description'=>'Create a complete section structure from a brief.','icon'=>'sparkles','scope'=>['page'],'instruction'=>'Create a complete, coherent page draft. Replace the current section document only in the proposal.'],
            'add-sections'=>['label'=>'Add sections','description'=>'Propose supporting sections without replacing existing work.','icon'=>'list-plus','scope'=>['page'],'instruction'=>'Create only new supporting sections that complement the existing page.'],
            'rewrite'=>['label'=>'Rewrite content','description'=>'Improve clarity while preserving meaning and facts.','icon'=>'wand-sparkles','scope'=>['page','section'],'instruction'=>'Rewrite the selected content for clarity and consistency. Preserve every fact and do not invent claims.'],
            'shorten'=>['label'=>'Make concise','description'=>'Reduce repetition and keep the essential message.','icon'=>'shrink','scope'=>['page','section'],'instruction'=>'Shorten the selected content substantially while preserving its essential meaning and facts.'],
            'expand'=>['label'=>'Expand content','description'=>'Add useful detail without inventing facts.','icon'=>'expand','scope'=>['page','section'],'instruction'=>'Expand the selected content with useful structure. Do not invent facts, prices, dates, policies or claims.'],
            'translate'=>['label'=>'Translate language','description'=>'Translate from the default language into the active language.','icon'=>'languages','scope'=>['page','section'],'instruction'=>'Translate the source-language content naturally into the requested target language. Preserve meaning, names, links and factual details.'],
            'quality-fix'=>['label'=>'Address quality issues','description'=>'Propose fixes for the current Core quality report.','icon'=>'shield-check','scope'=>['page','section'],'instruction'=>'Correct the supplied quality issues in content fields only. Do not hide sections or remove meaningful information merely to silence a warning.'],
        ];
        foreach([[$plugins,$activePlugins,'plugin'],[$addons,$activeAddons,'addon']]as[$items,$active,$kind])foreach($items as$slug=>$manifest){if(!in_array($slug,$active,true))continue;foreach((array)($manifest['builder_ai_actions']??[])as$key=>$definition){if(!is_string($key)||!preg_match('/^[a-z][a-z0-9-]{1,59}$/',$key)||!is_array($definition))continue;$label=mb_substr(trim((string)($definition['label']??'')),0,80);$instruction=mb_substr(trim((string)($definition['instruction']??'')),0,1000);$scope=array_values(array_intersect((array)($definition['scope']??[]),['page','section']));if($label===''||$instruction===''||!$scope)continue;$actions[$kind.'-'.$slug.'-'.$key]=['label'=>$label,'description'=>mb_substr(trim((string)($definition['description']??'')),0,200),'icon'=>preg_match('/^[a-z0-9-]{2,60}$/',(string)($definition['icon']??''))?$definition['icon']:'sparkles','scope'=>$scope,'instruction'=>$instruction,'source'=>$kind.':'.$slug];}}
        return$actions;
    }

    public static function layoutPresets(): array
    {
        return [
            ['key'=>'container','label'=>'Content container','description'=>'Keep selected sections in a readable content-width stack.','icon'=>'panel-top','min'=>1,'max'=>6,'mode'=>'stack','container'=>'content','spans'=>[12]],
            ['key'=>'columns-50','label'=>'Columns 50 / 50','description'=>'Two equal columns that stack cleanly on smaller screens.','icon'=>'columns-2','min'=>2,'max'=>2,'mode'=>'columns','container'=>'wide','spans'=>[6,6]],
            ['key'=>'columns-33','label'=>'Columns 33 / 67','description'=>'A supporting column beside a wider primary section.','icon'=>'panel-left','min'=>2,'max'=>2,'mode'=>'columns','container'=>'wide','spans'=>[4,8]],
            ['key'=>'columns-25','label'=>'Columns 25 / 75','description'=>'A compact rail beside a dominant content section.','icon'=>'panel-left-dashed','min'=>2,'max'=>2,'mode'=>'columns','container'=>'wide','spans'=>[3,9]],
            ['key'=>'grid','label'=>'Responsive grid','description'=>'Arrange two to six sections in an even responsive grid.','icon'=>'grid-2x2','min'=>2,'max'=>6,'mode'=>'grid','container'=>'wide','spans'=>[]],
            ['key'=>'stack','label'=>'Vertical stack','description'=>'Group selected sections in one controlled vertical flow.','icon'=>'rows-3','min'=>1,'max'=>6,'mode'=>'stack','container'=>'content','spans'=>[12]],
            ['key'=>'sidebar-left','label'=>'Left sidebar','description'=>'A narrow left rail with a wider content area.','icon'=>'panel-left','min'=>2,'max'=>2,'mode'=>'sidebar','container'=>'wide','spans'=>[4,8]],
            ['key'=>'sidebar-right','label'=>'Right sidebar','description'=>'A wider content area with a narrow right rail.','icon'=>'panel-right','min'=>2,'max'=>2,'mode'=>'sidebar','container'=>'wide','spans'=>[8,4]],
            ['key'=>'full-width','label'=>'Full width','description'=>'Let the selected section or group span the viewport safely.','icon'=>'move-horizontal','min'=>1,'max'=>6,'mode'=>'stack','container'=>'full','spans'=>[12]],
        ];
    }

    public static function defaultLayout(): array
    {
        $breakpoint = ['span'=>12,'order'=>0,'hidden'=>false];
        return ['group'=>null,'mode'=>'stack','container'=>'content','gap'=>'medium','align'=>'stretch','wrap'=>true,'desktop'=>$breakpoint,'tablet'=>$breakpoint,'mobile'=>$breakpoint];
    }

    public static function appearanceOptions(): array
    {
        return [
            'variant'=>['label'=>'Frame','options'=>['default'=>'Theme default','card'=>'Card','outline'=>'Outline','minimal'=>'Minimal','spotlight'=>'Spotlight']],
            'surface'=>['label'=>'Surface','options'=>['default'=>'Theme default','canvas'=>'Canvas','soft'=>'Soft','brand'=>'Brand','contrast'=>'Contrast']],
            'spacing'=>['label'=>'Spacing','options'=>['default'=>'Theme default','compact'=>'Compact','comfortable'=>'Comfortable','spacious'=>'Spacious']],
            'radius'=>['label'=>'Corners','options'=>['default'=>'Theme default','none'=>'None','small'=>'Small','medium'=>'Medium','large'=>'Large']],
            'align'=>['label'=>'Content alignment','options'=>['default'=>'Theme default','left'=>'Left','center'=>'Centre']],
        ];
    }

    public static function defaultAppearance(): array
    {
        return ['variant'=>'default','surface'=>'default','spacing'=>'default','radius'=>'default','align'=>'default'];
    }

    public static function sanitizeAppearance(mixed $input): array
    {
        $input=is_array($input)?$input:[];$appearance=self::defaultAppearance();
        foreach(self::appearanceOptions()as$key=>$definition){$value=(string)($input[$key]??'default');$appearance[$key]=array_key_exists($value,$definition['options'])?$value:'default';}
        return$appearance;
    }

    public static function sanitizeLayout(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $layout = self::defaultLayout();
        $group = strtolower(trim((string) ($input['group'] ?? '')));
        $layout['group'] = preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $group) ? $group : null;
        foreach (['mode'=>['stack','columns','grid','sidebar'],'container'=>['content','wide','full'],'gap'=>['none','small','medium','large'],'align'=>['start','center','end','stretch']] as $key => $allowed) {
            $value = (string) ($input[$key] ?? $layout[$key]);
            $layout[$key] = in_array($value, $allowed, true) ? $value : $layout[$key];
        }
        $layout['wrap'] = !array_key_exists('wrap', $input) || (bool) $input['wrap'];
        foreach (['desktop','tablet','mobile'] as $breakpoint) {
            $value = is_array($input[$breakpoint] ?? null) ? $input[$breakpoint] : [];
            $layout[$breakpoint] = [
                'span'=>max(1,min(12,(int)($value['span']??12))),
                'order'=>max(-20,min(20,(int)($value['order']??0))),
                'hidden'=>(bool)($value['hidden']??false),
            ];
        }
        return $layout;
    }

    public static function sanitizeBlocks(array $input, array $catalog, array $locales, bool $draft = false): array
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
            $shared = self::sanitizeData(is_array($block['shared'] ?? null) ? $block['shared'] : [], $catalog[$type]['shared_fields'], false, $draft);
            $localized = [];
            foreach ($locales as $locale) {
                $data = is_array($block['localized'][$locale] ?? null) ? $block['localized'][$locale] : [];
                $localized[$locale] = self::sanitizeData($data, $catalog[$type]['fields'], false, $draft);
                if(!$draft)self::validateComponent($type, $localized[$locale], $shared, (string) $locale);
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
                'layout'=>self::sanitizeLayout($block['layout']??[]),
                'appearance'=>self::sanitizeAppearance($block['appearance']??[]),
                'shared'=>$shared,
                'localized'=>$localized,
            ];
        }
        return self::normalizeLayoutGroups($blocks);
    }

    /** Converts a sanitized Builder document into the portable public block contract. */
    public static function previewBlocks(array $blocks, string $locale, string $fallbackLocale): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $localized = is_array($block['localized'] ?? null) ? $block['localized'] : [];
            $payload = $localized[$locale] ?? $localized[$fallbackLocale] ?? reset($localized);
            $result[] = [
                'uid'=>(string)($block['uid']??''),
                'type'=>(string)($block['type']??''),
                'source_theme'=>(string)($block['source_theme']??$block['source']??''),
                'payload'=>is_array($payload)?$payload:[],
                'shared'=>is_array($block['shared']??null)?$block['shared']:[],
                'layout'=>self::sanitizeLayout($block['layout']??[]),
                'appearance'=>self::sanitizeAppearance($block['appearance']??[]),
                'visible'=>(bool)($block['visible']??false),
                'builder_preview'=>true,
            ];
        }
        return $result;
    }

    /** Produces a portable pre-publication report for the current Builder draft. */
    public static function qualityReport(array $blocks, array $catalog, array $languages, array $page = []): array
    {
        $locales=[];$languageNames=[];
        foreach($languages as$language){$locale=is_array($language)?(string)($language['locale']??''):(string)$language;if($locale==='')continue;$locales[]=$locale;$languageNames[$locale]=is_array($language)?(string)($language['native_name']??$language['name']??strtoupper($locale)):strtoupper($locale);}
        $issues=[];$issueKeys=[];$sequence=0;
        $add=static function(string$severity,string$category,string$code,string$message,array$context=[])use(&$issues,&$issueKeys,&$sequence):void{
            $key=implode('|',[$severity,$code,(string)($context['block_uid']??''),(string)($context['locale']??''),(string)($context['field']??'')]);if(isset($issueKeys[$key]))return;$issueKeys[$key]=true;
            $issues[]=['id'=>$code.'-'.(++$sequence),'severity'=>$severity,'category'=>$category,'code'=>$code,'message'=>$message,'block_uid'=>(string)($context['block_uid']??''),'locale'=>(string)($context['locale']??''),'field'=>(string)($context['field']??''),'target'=>(string)($context['target']??'')];
        };
        $translations=is_array($page['translations']??null)?$page['translations']:[];$media=is_array($page['media']??null)?$page['media']:[];
        foreach($locales as$locale){
            $translation=is_array($translations[$locale]??null)?$translations[$locale]:[];$language=$languageNames[$locale]??strtoupper($locale);$title=trim((string)($translation['title']??''));$slug=trim((string)($translation['slug']??''));$seoTitle=trim((string)($translation['seo_title']??''));$seoDescription=trim((string)($translation['seo_description']??''));
            if($title==='')$add('error','Content','page-title','Add the page title for '.$language.'.',['locale'=>$locale,'target'=>'page']);
            elseif(mb_strlen($title)>70)$add('warning','SEO','page-title-length','Shorten the '.$language.' page title to about 60–70 characters.',['locale'=>$locale,'target'=>'page']);
            if($slug==='')$add('error','Links','page-slug','Add the public URL slug for '.$language.'.',['locale'=>$locale,'target'=>'page']);
            if($seoTitle==='')$add('warning','SEO','seo-title','Add a dedicated SEO title for '.$language.'.',['locale'=>$locale,'target'=>'seo']);
            elseif(mb_strlen($seoTitle)>60)$add('warning','SEO','seo-title-length','The '.$language.' SEO title is longer than 60 characters.',['locale'=>$locale,'target'=>'seo']);
            if($seoDescription==='')$add('warning','SEO','seo-description','Add an SEO description for '.$language.'.',['locale'=>$locale,'target'=>'seo']);
            elseif(mb_strlen($seoDescription)>160)$add('warning','SEO','seo-description-length','The '.$language.' SEO description is longer than 160 characters.',['locale'=>$locale,'target'=>'seo']);
        }
        if(!$blocks)$add('error','Content','empty-page','Add at least one section before publishing this page.');
        $visible=array_values(array_filter($blocks,static fn(array$block):bool=>(bool)($block['visible']??false)));
        if($blocks&&!$visible)$add('error','Visibility','no-visible-sections','Every section is hidden. At least one section must be visible before publishing.');
        $now=time();
        foreach($blocks as$index=>$block){
            $type=(string)($block['type']??'');$definition=$catalog[$type]??null;if(!is_array($definition))continue;$uid=(string)($block['uid']??'');$label=(string)($definition['label']??$type);$base=['block_uid'=>$uid];
            if(!(bool)($block['visible']??false))$add('advice','Visibility','hidden-section',$label.' is hidden from the public page.',$base);
            $from=strtotime((string)($block['visible_from']??''));$until=strtotime((string)($block['visible_until']??''));
            if($until!==false&&$until<=$now)$add('warning','Visibility','expired-section',$label.' has passed its visibility end date.',$base);
            elseif($from!==false&&$from>$now)$add('advice','Visibility','scheduled-section',$label.' is scheduled to appear later.',$base);
            foreach($locales as$locale){
                $data=is_array($block['localized'][$locale]??null)?$block['localized'][$locale]:[];$context=$base+['locale'=>$locale];
                self::inspectFields((array)($definition['fields']??[]),$data,$label,$languageNames[$locale]??strtoupper($locale),$context,$add,$media);
            }
            self::inspectFields((array)($definition['shared_fields']??[]),is_array($block['shared']??null)?$block['shared']:[],$label,'all languages',$base,$add,$media);
        }
        foreach($locales as$locale){$hasHeading=false;foreach($visible as$block){$definition=$catalog[(string)($block['type']??'')]??[];$data=is_array($block['localized'][$locale]??null)?$block['localized'][$locale]:[];if(self::hasHeading((array)($definition['fields']??[]),$data)){$hasHeading=true;break;}}if(!$hasHeading&&$visible)$add('warning','Accessibility','missing-heading','Add a clear section heading for '.($languageNames[$locale]??strtoupper($locale)).'.',['locale'=>$locale]);}
        foreach(['desktop'=>'desktop','tablet'=>'tablet','mobile'=>'mobile']as$breakpoint=>$label){
            $available=array_filter($visible,static fn(array$block):bool=>!(bool)($block['layout'][$breakpoint]['hidden']??false));
            if($visible&&!$available)$add($breakpoint==='desktop'?'error':'warning','Responsive','device-empty','Every visible section is hidden on '.$label.'.');
        }
        $counts=['error'=>0,'warning'=>0,'advice'=>0];$categories=[];
        foreach($issues as$issue){$counts[$issue['severity']]++;$categories[$issue['category']]=($categories[$issue['category']]??0)+1;}
        $score=max(0,100-$counts['error']*15-$counts['warning']*5-$counts['advice']);
        return['ready'=>$counts['error']===0,'score'=>$score,'counts'=>$counts,'categories'=>$categories,'issues'=>$issues,'checked_sections'=>count($blocks),'checked_languages'=>count($locales)];
    }

    public static function mediaPaths(array $blocks,array $catalog):array
    {
        $paths=[];$collect=static function(array$fields,array$data)use(&$collect,&$paths):void{foreach($fields as$field){if(!is_array($field))continue;$key=(string)($field['key']??'');$type=(string)($field['type']??'');$value=$data[$key]??null;if($type==='repeater'){foreach(is_array($value)?$value:[]as$item)$collect((array)($field['fields']??[]),is_array($item)?$item:[]);continue;}if(in_array($type,['image','video'],true)&&is_string($value)&&str_starts_with($value,'/media/')&&!str_contains($value,'..'))$paths[]=$value;}};
        foreach($blocks as$block){$definition=$catalog[(string)($block['type']??'')]??null;if(!is_array($definition))continue;$collect((array)($definition['shared_fields']??[]),is_array($block['shared']??null)?$block['shared']:[]);foreach((array)($block['localized']??[])as$data)$collect((array)($definition['fields']??[]),is_array($data)?$data:[]);}
        return array_slice(array_values(array_unique($paths)),0,250);
    }

    private static function inspectFields(array $fields,array $data,string $section,string $language,array $context,callable $add,array $media=[],string $path=''):void
    {
        foreach($fields as$field){
            if(!is_array($field))continue;$key=(string)($field['key']??'');$type=(string)($field['type']??'text');if($key==='')continue;
            if(is_array($field['show_when']??null)){foreach($field['show_when']as$whenKey=>$whenValue)if((string)($data[$whenKey]??'')!==(string)$whenValue)continue 2;}
            $value=$data[$key]??($type==='repeater'?[]:'');$fieldPath=$path===''?$key:$path.'.'.$key;$label=(string)($field['label']??$key);$fieldContext=$context+['field'=>$fieldPath];
            if($type==='repeater'){
                $items=is_array($value)?array_values($value):[];$minimum=max(0,(int)($field['min']??0));
                if(count($items)<$minimum)$add('error','Content','required-items',$section.': add at least '.$minimum.' '.$label.' item'.($minimum===1?'':'s').' for '.$language.'.',$fieldContext);
                foreach($items as$index=>$item)self::inspectFields((array)($field['fields']??[]),is_array($item)?$item:[],$section,$language,$context,$add,$media,$fieldPath.'.'.$index);
                continue;
            }
            if($type==='content-multiselect'){
                if((bool)($field['required']??false)&&(!is_array($value)||$value===[]))$add('error','Content','required-field',$section.': choose at least one “'.$label.'” item for '.$language.'.',$fieldContext);
                continue;
            }
            $text=trim((string)$value);
            if((bool)($field['required']??false)&&$text==='')$add('error','Content','required-field',$section.': complete “'.$label.'” for '.$language.'.',$fieldContext);
            if($text!==''&&in_array($type,['url','image','video'],true)&&!self::validUrl($text,$type==='url',true))$add('error','Links','unsafe-url',$section.': “'.$label.'” contains an unsafe or invalid address.',$fieldContext);
            if($type==='url'){
                $labelKey=str_ends_with($key,'_url')?substr($key,0,-4).'_label':($key==='url'?'label':'');$pair=$labelKey!==''?trim((string)($data[$labelKey]??'')):'';
                if($text!==''&&$labelKey!==''&&$pair==='')$add('warning','Accessibility','link-label',$section.': add a descriptive label for “'.$label.'” in '.$language.'.',$fieldContext);
                elseif($text===''&&$pair!=='')$add('warning','Links','link-target',$section.': add the destination for “'.$label.'” in '.$language.'.',$fieldContext);
            }
            if($type==='image'&&$text!==''){
                $keys=array_column($fields,'key');$altKey=in_array($key.'_alt',$keys,true)?$key.'_alt':(in_array('image_alt',$keys,true)?'image_alt':(in_array('alt',$keys,true)?'alt':''));
                if($altKey!==''&&trim((string)($data[$altKey]??''))==='')$add('warning','Accessibility','image-alt',$section.': add alternative text for “'.$label.'” in '.$language.'.',$fieldContext);
            }
            if($type==='video'&&$text!==''&&in_array('poster',array_column($fields,'key'),true)&&trim((string)($data['poster']??''))==='')$add('warning','Accessibility','video-poster',$section.': add a poster image for the video in '.$language.'.',$fieldContext);
            $asset=is_array($media[$text]??null)?$media[$text]:null;$bytes=(int)($asset['size_bytes']??0);
            $performanceContext=$fieldContext;$performanceContext['locale']='';$performanceContext['field']=$text;
            if($asset&&$type==='image'&&$bytes>2_097_152)$add('warning','Performance','heavy-image',$section.': optimize “'.$label.'” ('.number_format($bytes/1_048_576,1).' MB).',$performanceContext);
            if($asset&&$type==='video'&&$bytes>52_428_800)$add('warning','Performance','heavy-video',$section.': optimize “'.$label.'” ('.number_format($bytes/1_048_576,1).' MB).',$performanceContext);
        }
    }

    private static function hasHeading(array$fields,array$data):bool
    {
        foreach($fields as$field){if(!is_array($field))continue;$key=(string)($field['key']??'');$type=(string)($field['type']??'');$value=$data[$key]??null;if($type==='repeater'){foreach(is_array($value)?$value:[]as$item)if(self::hasHeading((array)($field['fields']??[]),is_array($item)?$item:[]))return true;continue;}if(in_array($key,['title','headline','heading'],true)&&trim((string)$value)!=='')return true;}return false;
    }

    private static function normalizeLayoutGroups(array $blocks): array
    {
        $groups = [];
        foreach ($blocks as $index => $block) if (($group = $block['layout']['group'] ?? null) !== null) $groups[$group][] = $index;
        foreach ($groups as $group => $indexes) {
            if (count($indexes) === 1) {
                $blocks[$indexes[0]]['layout']['group'] = null;
                $blocks[$indexes[0]]['layout']['mode'] = 'stack';
                continue;
            }
            if (count($indexes) > 6 || end($indexes) - $indexes[0] + 1 !== count($indexes)) throw new \RuntimeException('Layout groups must contain two to six consecutive sections.');
            $common = array_intersect_key($blocks[$indexes[0]]['layout'], array_flip(['mode','container','gap','align','wrap']));
            foreach ($indexes as $index) foreach ($common as $key => $value) $blocks[$index]['layout'][$key] = $value;
        }
        return $blocks;
    }

    public static function revisionDiff(array $from, array $to, array $catalog): array
    {
        $from=array_values((array)($from['blocks']??$from));$to=array_values((array)($to['blocks']??$to));
        $old=[];$new=[];foreach($from as$index=>$block)if(is_array($block)&&isset($block['uid']))$old[(string)$block['uid']]=[$index,$block];foreach($to as$index=>$block)if(is_array($block)&&isset($block['uid']))$new[(string)$block['uid']]=[$index,$block];
        $changes=[];$summary=['added'=>0,'removed'=>0,'changed'=>0,'moved'=>0];
        foreach($old as$uid=>[$index,$block])if(!isset($new[$uid])){$summary['removed']++;$changes[]=['uid'=>$uid,'kind'=>'removed','label'=>self::revisionBlockLabel($block,$catalog),'details'=>['Section removed']];}
        foreach($new as$uid=>[$index,$block]){
            if(!isset($old[$uid])){$summary['added']++;$changes[]=['uid'=>$uid,'kind'=>'added','label'=>self::revisionBlockLabel($block,$catalog),'details'=>['Section added']];continue;}
            [$oldIndex,$before]=$old[$uid];$details=[];
            if($oldIndex!==$index){$summary['moved']++;$details[]='Position '.($oldIndex+1).' → '.($index+1);}
            if((string)($before['type']??'')!==(string)($block['type']??''))$details[]='Section type';
            if((bool)($before['visible']??false)!==(bool)($block['visible']??false))$details[]='Website visibility';
            if((string)($before['visible_from']??'')!==(string)($block['visible_from']??'')||(string)($before['visible_until']??'')!==(string)($block['visible_until']??''))$details[]='Visibility schedule';
            if(self::revisionValue($before['layout']??[])!==self::revisionValue($block['layout']??[]))$details[]='Responsive layout';
            if(self::revisionValue($before['appearance']??[])!==self::revisionValue($block['appearance']??[]))$details[]='Appearance';
            $definition=$catalog[(string)($block['type']??'')]??[];
            $shared=self::revisionFields((array)($before['shared']??[]),(array)($block['shared']??[]),(array)($definition['shared_fields']??[]));if($shared)$details[]='Settings: '.implode(', ',$shared);
            $locales=array_values(array_unique(array_merge(array_keys((array)($before['localized']??[])),array_keys((array)($block['localized']??[])))));
            foreach($locales as$locale){$fields=self::revisionFields((array)($before['localized'][$locale]??[]),(array)($block['localized'][$locale]??[]),(array)($definition['fields']??[]));if($fields)$details[]=strtoupper((string)$locale).' content: '.implode(', ',$fields);}
            if($details){$summary['changed']++;$changes[]=['uid'=>$uid,'kind'=>$oldIndex!==$index&&count($details)===1?'moved':'changed','label'=>self::revisionBlockLabel($block,$catalog),'details'=>$details];}
        }
        return['summary'=>$summary,'changes'=>$changes,'empty'=>!$changes];
    }

    private static function revisionFields(array $before,array $after,array $fields):array
    {
        $labels=[];$definitions=[];foreach($fields as$field)if(isset($field['key']))$definitions[(string)$field['key']]=(string)($field['label']??$field['key']);
        foreach(array_values(array_unique(array_merge(array_keys($before),array_keys($after))))as$key)if(self::revisionValue($before[$key]??null)!==self::revisionValue($after[$key]??null))$labels[]=$definitions[(string)$key]??ucfirst(str_replace('_',' ',(string)$key));
        return$labels;
    }

    private static function revisionValue(mixed$value):string
    {
        if(is_array($value)){ksort($value);foreach($value as&$item)if(is_array($item))$item=json_decode(self::revisionValue($item),true);unset($item);}
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
    }

    private static function revisionBlockLabel(array$block,array$catalog):string
    {
        foreach((array)($block['localized']??[])as$data)if(is_array($data))foreach(['title','headline','eyebrow','name']as$key)if(trim((string)($data[$key]??''))!=='')return mb_substr(trim((string)$data[$key]),0,100);
        return(string)($catalog[(string)($block['type']??'')]['label']??$block['type']??'Section');
    }

    private static function definitions(array $input, string $source, string $fallbackGroup): array
    {
        $catalog = [];
        foreach ($input as $type => $definition) {
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9-]{1,79}$/', $type) || !is_array($definition)) continue;
            $fields = self::fields((array) ($definition['fields'] ?? []));
            $sharedFields = self::fields((array) ($definition['shared_fields'] ?? []));
            if (!$fields && !$sharedFields) continue;
            $defaults=self::sanitizeData((array)($definition['defaults']??[]),$fields,true);
            $localizedDefaults=[];
            foreach((array)($definition['localized_defaults']??[]) as$locale=>$localizedDefault){
                if(!is_string($locale)||!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/',$locale)||!is_array($localizedDefault))continue;
                $localizedDefaults[$locale]=self::sanitizeData(array_replace_recursive($defaults,$localizedDefault),$fields,true);
            }
            $catalog[$type] = [
                'label'=>mb_substr(trim((string)($definition['label']??$type)),0,80), 'description'=>mb_substr(trim((string)($definition['description']??'')),0,220),
                'icon'=>preg_match('/^[a-z0-9-]{2,60}$/',(string)($definition['icon']??''))?$definition['icon']:'panel-top', 'group'=>mb_substr(trim((string)($definition['group']??$fallbackGroup)),0,80),
                'singleton'=>(bool)($definition['singleton']??false), 'source'=>$source, 'source_label'=>self::sourceLabel($source), 'fields'=>$fields, 'shared_fields'=>$sharedFields,
                'defaults'=>$defaults, 'localized_defaults'=>$localizedDefaults, 'shared_defaults'=>self::sanitizeData((array)($definition['shared_defaults']??[]),$sharedFields,true),
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
            if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/',$key) || !in_array($type,['text','textarea','url','email','image','video','number','checkbox','select','content-select','content-multiselect','code','repeater'],true)) continue;
            $clean=['key'=>$key,'type'=>$type,'label'=>mb_substr(trim((string)($field['label']??$key)),0,100),'required'=>(bool)($field['required']??false),'wide'=>(bool)($field['wide']??false)];
            if(is_array($field['show_when']??null)&&count($field['show_when'])<=3){$conditions=[];foreach($field['show_when']as$conditionKey=>$conditionValue){$conditionKey=(string)$conditionKey;$conditionValue=(string)$conditionValue;if(!preg_match('/^[a-z][a-z0-9_]{1,79}$/',$conditionKey)||!preg_match('/^[a-z0-9_-]{1,50}$/',$conditionValue)){$conditions=[];break;}$conditions[$conditionKey]=$conditionValue;}if($conditions)$clean['show_when']=$conditions;}
            if ($type==='repeater') {
                $clean['min']=max(0,min(12,(int)($field['min']??0))); $clean['max']=max(1,min(12,(int)($field['max']??6))); $clean['item_label']=mb_substr(trim((string)($field['item_label']??'Item')),0,60); $clean['fields']=self::fields((array)($field['fields']??[])); if(!$clean['fields'])continue;
            } elseif ($type==='select') {
                $options=[]; foreach((array)($field['options']??[]) as$value=>$label)if(is_string($value)&&preg_match('/^[a-z0-9_-]{1,50}$/',$value))$options[$value]=mb_substr((string)$label,0,80); if(!$options)continue; $clean['options']=$options;
            } elseif ($type==='content-select') {
                $source=(string)($field['options_source']??'');if(!preg_match('/^[a-z][a-z0-9_-]{1,50}$/D',$source))continue;$clean['options_source']=$source;$clean['empty_label']=mb_substr(trim((string)($field['empty_label']??'Any')),0,80);
            } elseif ($type==='content-multiselect') {
                $sourceField=(string)($field['options_source_field']??'');if(!preg_match('/^[a-z][a-z0-9_]{1,79}$/D',$sourceField))continue;$clean['options_source_field']=$sourceField;$clean['max']=max(1,min(20,(int)($field['max']??12)));
            } elseif ($type==='number') {
                $clean['min']=(int)($field['min']??0); $clean['max']=(int)($field['max']??10000); if($clean['max']<$clean['min'])[$clean['min'],$clean['max']]=[$clean['max'],$clean['min']];
            } elseif ($type!=='checkbox') $clean['max']=max(1,min(20000,(int)($field['max']??($type==='textarea'||$type==='code'?4000:500))));
            $fields[]=$clean;
        }
        return $fields;
    }

    private static function sanitizeData(array $input, array $fields, bool $defaults = false, bool $draft = false): array
    {
        $data=[];
        foreach($fields as$field){
            $key=$field['key']; $value=$input[$key]??(in_array($field['type'],['repeater','content-multiselect'],true)?[]:($field['type']==='checkbox'?false:''));
            if($field['type']==='repeater'){
                $items=is_array($value)?array_slice(array_values($value),0,$field['max']):[];
                if(!$draft&&count($items)<$field['min'])throw new \RuntimeException($field['label'].' requires at least '.$field['min'].' items.');
                $data[$key]=array_map(static fn(mixed $item):array=>self::sanitizeData(is_array($item)?$item:[],$field['fields'],$defaults,$draft),$items); continue;
            }
            if($field['type']==='checkbox'){$data[$key]=(bool)$value;continue;}
            if($field['type']==='number'){$data[$key]=max($field['min'],min($field['max'],(int)$value));continue;}
            if($field['type']==='content-multiselect'){$ids=is_array($value)?array_values(array_unique(array_filter(array_map('intval',$value),static fn(int$id):bool=>$id>0))):[];$data[$key]=array_slice($ids,0,$field['max']);continue;}
            $value=trim((string)$value);
            if(!$defaults&&!$draft&&$field['required']&&$value==='')throw new \RuntimeException($field['label'].' is required in every active language.');
            if($field['type']==='select'){if(!array_key_exists($value,$field['options']))$value=(string)array_key_first($field['options']);$data[$key]=$value;continue;}
            if($field['type']==='content-select'){$data[$key]=(string)max(0,(int)$value);if($data[$key]==='0')$data[$key]='';continue;}
            if(mb_strlen($value)>$field['max'])throw new \RuntimeException($field['label'].' exceeds its character limit.');
            if(!$draft&&$field['type']==='email'&&$value!==''&&filter_var($value,FILTER_VALIDATE_EMAIL)===false)throw new \RuntimeException($field['label'].' must contain a valid email address.');
            if($value!==''&&in_array($field['type'],['url','image','video'],true)&&!self::validUrl($value,$field['type']==='url',$draft))throw new \RuntimeException($field['label'].' must contain a safe URL or public media path.');
            $data[$key]=$field['type']==='code'?HtmlSanitizer::sanitize($value):$value;
        }
        return $data;
    }

    private static function validUrl(string $value,bool $allowAction,bool $draft=false):bool
    {
        if(str_starts_with($value,'/')&&!str_starts_with($value,'//'))return!str_contains($value,'..');
        if($allowAction&&(str_starts_with($value,'#')||str_starts_with($value,'mailto:')||str_starts_with($value,'tel:')))return true;
        if($draft){if(preg_match('/[\x00-\x1F\x7F]/',$value))return false;if(!preg_match('/^([a-z][a-z0-9+.-]*):/i',$value,$match))return true;return in_array(strtolower($match[1]),$allowAction?['http','https','mailto','tel']:['http','https'],true);}
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
