<?php
declare(strict_types=1);

namespace App\Core;

/** Three-way merge of normalized Builder fields and identifiable media entries. */
final class BuilderDraftMerge
{
    public array $conflicts = [];
    public int $unresolved = 0;
    private ?string $mediaField=null;
    private ?array $mediaContext=null;

    public function __construct(private readonly array $choices = []) {}

    public function value(array $path, mixed $base, mixed $mine, mixed $live, bool $known, bool $atomic=false): mixed
    {
        if ($mine === $live) return $live;
        if ($known && $mine === $base) return $live;
        if ($known && $live === $base) return $mine;
        if (!$atomic && count($path)===5 && $path[0]==='section' && $path[2]==='localized'
            && $path[4]===$this->mediaField && is_array($mine) && is_array($live)) {
            return $this->media($path,$base,$mine,$live,$known);
        }
        if (!$atomic && is_array($mine) && is_array($live) && !array_is_list($mine) && !array_is_list($live)) {
            $result = [];
            foreach (array_unique([...array_keys($mine), ...array_keys($live)]) as $key) {
                $value = $this->value([...$path,$key],$base[$key]??null,$mine[$key]??null,$live[$key]??null,$known);
                if ($value !== null || (array_key_exists($key,$mine) && array_key_exists($key,$live))) $result[$key]=$value;
            }
            return $result;
        }
        $key = hash('sha256',json_encode($path,JSON_THROW_ON_ERROR));
        $this->conflicts[] = ['key'=>$key,'path'=>$path,'mine'=>$mine,'live'=>$live,'media'=>$this->mediaContext];
        $choice = $this->choices[$key]??null;
        if ($choice==='mine') return $mine;
        if ($choice==='live') return $live;
        if (is_array($choice) && is_string($mine) && is_string($live) && is_string($choice['text']??null)) return $choice['text'];
        ++$this->unresolved;
        return $live;
    }

    private function media(array $path,?array $base,array $mine,array $live,bool $known): array
    {
        $base=BuilderMediaItems::identified($base??[]);
        $local=BuilderMediaItems::align($mine,$base);
        $current=BuilderMediaItems::align($live,$base);
        $context=['kind'=>$this->mediaField==='slides'?'slide':'image'];
        if ($local===null || $current===null || (!$known && (count(array_column($mine,'_item_id'))!==count($mine) || count(array_column($live,'_item_id'))!==count($live)))) {
            $this->mediaContext=$context+['reason'=>'identity'];
            $result=$this->value($path,$base,$mine,$live,$known,true);
            $this->mediaContext=null;
            return $result;
        }
        $original=array_column($base,null,'_item_id');
        $mineById=array_column($local,null,'_item_id');
        $liveById=array_column($current,null,'_item_id');
        $ids=array_unique([...array_keys($liveById),...array_keys($mineById)]);
        // Count every item that could survive conflict resolution. The choice set
        // must not change depending on which radio buttons the editor selects.
        $possible=array_filter($ids,static function($id) use ($original,$mineById,$liveById,$known) {
            if (isset($mineById[$id],$liveById[$id])) return true;
            $survivor=$mineById[$id]??$liveById[$id];
            return !$known || $survivor!==($original[$id]??null);
        });
        if (count($possible)>10) {
            $this->mediaContext=$context+['reason'=>'limit'];
            $result=$this->value($path,$base,$mine,$live,$known,true);
            $this->mediaContext=null;
            return $result;
        }
        $merged=[]; $names=[];
        foreach ($ids as $id) {
            $a=$original[$id]??null; $b=$mineById[$id]??null; $c=$liveById[$id]??null;
            $row=$c??$b;
            $title=trim((string)($row['title']??'')) ?: (trim((string)($row['alt']??'')) ?: basename((string)(($row['video']??'')?:($row['image']??''))));
            $number=array_search($id,array_keys($c?$liveById:$mineById),true)+1;
            $names[$id]=trim($number.'. '.$title);
            $this->mediaContext=$context+['title'=>$title,'number'=>$number];
            $item=$this->value([...$path,'item',$id],$a,$b,$c,$known,$known && !$a && $b!==null && $c!==null);
            if ($item!==null) $merged[$id]=$item;
        }
        $this->mediaContext=$context+['reason'=>'order','names'=>$names];
        $result=$this->ordered([...$path,'order'],$original,$mineById,$liveById,$merged,$known);
        $this->mediaContext=null;
        return $result;
    }

    public function blocks(?array $base,array $mine,array $live): array
    {
        $known = $base!==null;
        $original=array_column($base??[],null,'uid');
        $local=array_column($mine,null,'uid');
        $current=array_column($live,null,'uid');
        $merged=[];
        foreach (array_unique([...array_keys($current),...array_keys($local)]) as $uid) {
            $a=$original[$uid]??null; $b=$local[$uid]??null; $c=$current[$uid]??null;
            // Edition counters are preconditions supplied by the latest shared section.
            $version=$c['global_version']??$b['global_version']??0;
            unset($a['global_version'],$b['global_version'],$c['global_version']);
            $whole=$b && $c && ($b['type']!==$c['type'] || $b['global_section_id']!==$c['global_section_id'] || ($known && !$a));
            $this->mediaField=BuilderMediaItems::field($c['type']??$b['type']??'');
            $item=$this->value(['section',$uid],$a,$b,$c,$known,$whole);
            if ($item!==null) {
                $item['global_version']=($item['global_section_id']??null)===($c['global_section_id']??null)?$version:($local[$uid]['global_version']??0);
                $merged[$uid]=$item;
            }
        }
        return $this->ordered(['order'],$original,$local,$current,$merged,$known);
    }

    private function ordered(array $path,array $original,array $local,array $current,array $merged,bool $known): array
    {
        $ids=fn($map)=>array_values(array_intersect(array_keys($map),array_keys($merged)));
        $mineOrder=$ids($local); $liveOrder=$ids($current);
        $common=array_intersect($mineOrder,$liveOrder);
        $filter=fn($order)=>array_values(array_intersect($order,$common));
        $order=$this->value($path,$filter($ids($original)),$filter($mineOrder),$filter($liveOrder),$known,true);
        foreach ([$liveOrder,$mineOrder] as $side) {
            foreach ($side as $index=>$uid) {
                if (in_array($uid,$order,true)) continue;
                $next=null;
                foreach (array_slice($side,$index+1) as $following) {
                    if (in_array($following,$order,true)) { $next=array_search($following,$order,true);break; }
                }
                array_splice($order,$next??count($order),0,[$uid]);
            }
        }
        return array_map(fn($id)=>$merged[$id],$order);
    }
}
