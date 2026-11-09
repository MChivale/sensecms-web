<?php
declare(strict_types=1);
namespace App\Core;

/** Item identities belong to one localized list, including on linked sections. */
final class BuilderMediaItems
{
    public static function field(string $type): ?string
    {
        return match ($type) { 'gallery'=>'items', 'hero-slider'=>'slides', default=>null };
    }

    public static function preserve(string $type,array $raw,array &$clean): void
    {
        $field=self::field($type);
        if (!$field || !isset($clean[$field]) || !is_array($raw[$field]??null)) return;
        $seen=[];
        $rows=array_values($raw[$field]);
        foreach ($clean[$field] as $index=>&$item) {
            $id=$rows[$index]['_item_id']??null;
            if ($id===null) continue;
            if (!is_string($id) || !preg_match('/^(?:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|legacy-[a-f0-9]{32}-[0-9]{1,3})$/D',$id) || isset($seen[$id]))
                throw new \RuntimeException('A gallery image or slide has an invalid or duplicate identifier.');
            $item['_item_id']=$id;
            $seen[$id]=true;
        }
    }

    public static function identify(string $type,array $localized): array
    {
        $field=self::field($type);
        if (!$field) return $localized;
        foreach ($localized as &$translation) {
            if (is_array($translation[$field]??null)) $translation[$field]=self::identified($translation[$field]);
        }
        return $localized;
    }

    public static function identified(array $rows): array
    {
        $taken=array_fill_keys(array_column($rows,'_item_id'),true);
        foreach ($rows as &$row) {
            if (!is_array($row) || isset($row['_item_id'])) continue;
            $signature=[];
            foreach (['image','video','title','alt','eyebrow','text'] as $key) $signature[]=(string)($row[$key]??'');
            $prefix='legacy-'.substr(hash('sha256',json_encode($signature,JSON_THROW_ON_ERROR)),0,32).'-';
            $n=1;
            while (isset($taken[$prefix.$n])) ++$n;
            $row['_item_id']=$prefix.$n;
            $taken[$prefix.$n]=true;
        }
        return $rows;
    }

    /** Relate legacy rows to the common base only if the match is unambiguous. */
    public static function align(array $rows,array $base): ?array
    {
        $rows=self::identified($rows);
        $remaining=array_diff_key(array_column($base,null,'_item_id'),array_flip(array_column($rows,'_item_id')));
        // Identical pre-ID entries share a signature. An old client can renumber the
        // survivors after deletion, making it unsafe to infer which copy was removed.
        foreach (array_keys($remaining) as $id) {
            if (!str_starts_with($id,'legacy-')) continue;
            $prefix=substr($id,0,strrpos($id,'-')+1);
            if (count(array_filter($base,fn($row)=>str_starts_with($row['_item_id'],$prefix)))>1
                && array_filter($rows,fn($row)=>str_starts_with($row['_item_id'],$prefix))) return null;
        }
        $unmatched=[];
        foreach ($rows as $i=>$row) {
            if (str_starts_with($row['_item_id'],'legacy-') && !in_array($row['_item_id'],array_column($base,'_item_id'),true)) $unmatched[]=$i;
        }
        // A changed caption may still have the same unique media path. Do not use list positions.
        $asset=static fn($row)=>(string)(($row['media_type']??'image')==='video'?($row['video']??''):($row['image']??''));
        foreach ($unmatched as $k=>$i) {
            $path=$asset($rows[$i]);
            $matches=array_filter($remaining,fn($row)=>$path!=='' && $asset($row)===$path);
            if (count($matches)!==1 || count(array_filter($base,fn($row)=>$asset($row)===$path))!==1
                || count(array_filter($rows,fn($row)=>$asset($row)===$path))!==1) continue;
            $id=array_key_first($matches);
            $rows[$i]['_item_id']=$id;
            unset($remaining[$id],$unmatched[$k]);
        }
        return $remaining && $unmatched?null:$rows;
    }
}
