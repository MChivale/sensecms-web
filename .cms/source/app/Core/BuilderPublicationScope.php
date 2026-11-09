<?php
declare(strict_types=1);

namespace App\Core;

/** Resolve the affected managed pages before committing a reviewed Builder revision. */
final class BuilderPublicationScope
{
    public static function resolve(CmsRepository $cms, AccessControl $access, int $pageId, array $catalog, array $locales, array $blocks, array $edits): array
    {
        $document=$cms->builderDocument($pageId);
        $stored=array_values(array_filter($document['blocks'], static fn($b)=>isset($catalog[$b['type']])));
        $before=PageBuilder::sanitizeBlocks($stored,$catalog,$locales,true);
        $old=array_column($before,null,'uid');
        $pages=array_column($cms->pages(),null,'id');
        $rows=[];
        $add=static function(int $id,array $languages,string $reason) use (&$rows,$pages,$access,$pageId) {
            if (!$languages) return;
            $page=$pages[$id]??null;
            if (!$page || ($id!==$pageId && !$access->allows('content.pages.publish',(int)$page['facility_id'])))
                throw new \RuntimeException('This shared change needs a publisher with access to all affected pages.');
            $rows[$id]??=['id'=>$id,'title'=>$page['title']?:'Page #'.$id,'path'=>$page['public_path']?:$page['slug'],'facility'=>$page['facility_name'],'status'=>$page['status'],'visibility'=>$page['visibility'],'languages'=>[],'reasons'=>[]];
            $rows[$id]['languages']=array_values(array_unique(array_merge($rows[$id]['languages'],$languages)));
            $rows[$id]['reasons']=array_values(array_unique([...$rows[$id]['reasons'],$reason]));
        };
        if (array_column($before,'uid')!==array_column($blocks,'uid')) $add($pageId,$locales,'structure');
        $globals=array_column($cms->globalSections(),null,'id');
        foreach ($blocks as $block) {
            $block['localized']=BuilderMediaItems::identify($block['type'],$block['localized']);
            $previous=$old[$block['uid']]??null;
            if ($previous) {
                $content=array_values(array_filter($locales,static fn($l)=>$previous['localized'][$l]!=$block['localized'][$l]));
                $settings=array_diff_key($previous,['localized'=>true,'global_version'=>true]);
                $add($pageId,$settings!=array_intersect_key($block,$settings)?$locales:$content,'section');
            }
            $global=$globals[$block['global_section_id']]??null;
            if (!$global) continue;
            $changed=$global['shared']!=$block['shared']?$locales:array_values(array_filter($locales,static fn($l)=>($global['localized'][$l]??null)!=$block['localized'][$l]));
            if (!$changed) continue;
            $add($pageId,$changed,'shared');
            foreach ($cms->sharedSectionPages((int)$global['id'],$locales[0],null) as $use) $add((int)$use['id'],$changed,'shared');
        }
        foreach ($edits as $edit) {
            if ($edit['id']!=='banner') {$add($pageId,[$edit['locale']],'element');continue;}
            $languages=$edit['locale']==='pl'?['pl']:array_values(array_diff($locales,['pl']));
            foreach ($pages as $page) {
                $path=$page['public_path']; $type=$page['template'];
                if ($type==='home' || ($type==='product' && in_array($path,['/platform','/platform/ai'],true))
                    || ($type!=='product' && in_array($path,['/docs','/extensions/catalog/system/sensecms'],true)))
                    $add((int)$page['id'],$languages,'banner');
            }
        }
        ksort($rows);
        foreach ($rows as &$row) {sort($row['languages']);sort($row['reasons']);}
        return array_values($rows);
    }
}
