<?php
declare(strict_types=1);

namespace App\Core;

/** Page-local edits to the static editorial regions of the official signed theme. */
final class CanvasThemeContent
{
    private const REGIONS = '.product-hero,.product-principles,#private-ai-chatbot,.solutions-section,.app-presentation,.workspace-tour,.app-journey,.platform-faq,.product-features,.ecosystem-section,.ownership-section,.product-closing,.working-together,.industry-related,.subpage-next,.platform-hero,.platform-showcase,.subpage-hero,.industry-hero';
    private const SKIP = '.sense-layout,[data-sensecms-builder-uid],form,script,style,svg,template,iframe,nav,button,[aria-hidden="true"],.platform-hero h1,.subpage-hero h1,.industry-hero h1,.platform-hero h1 + p,.subpage-hero h1 + p,.industry-hero h1 + p,#chatbot-title,.chatbot-lead,.chatbot-copy > p:not([class])';

    public static function key(int $page, string $locale): string
    {
        return 'canvas_sensecms_'.$page.'_'.$locale;
    }

    public static function revision(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public static function validate(array $defaults, mixed $input): array
    {
        if (!is_array($input) || array_diff_key($defaults,$input) || array_diff_key($input,$defaults)) throw new \RuntimeException('Reopen the element to edit its current fields.',422);
        $result=[];
        foreach ($defaults as $key=>$_) {
            $value=$input[$key];
            if (!is_string($value) || mb_strlen($value)>($key==='alt'?500:($key==='text'?4000:2048))) throw new \RuntimeException('The field exceeds its length limit.',422);
            $value=trim($value);
            if ($key!=='alt' && $value==='') throw new \RuntimeException('Enter a value before publishing.',422);
            if (in_array($key,['url','image'],true)) {
                $local=str_starts_with($value,'/') && !str_starts_with($value,'//') && !str_contains($value,'..');
                $action=$key==='url' && preg_match('~^(?:#[^\s]*|(?:mailto|tel):[^\s]+)$~iD',$value);
                $https=str_starts_with(strtolower($value),'https://') && filter_var($value,FILTER_VALIDATE_URL);
                if (strpbrk($value,"\\<>\"'")!==false || preg_match('/[\x00-\x20\x7f]/',$value) || !($local || $action || $https)) throw new \RuntimeException('Use a website path or a secure web address.',422);
            }
            $result[$key]=$value;
        }
        return $result;
    }

    public static function render(string $html, array $saved, bool $edit, array &$inventory, array $overlay=[]): string
    {
        $inventory=[];
        if ((!$edit && !$saved && !$overlay) || !preg_match('~<main\b[^>]*>~i',$html,$m,PREG_OFFSET_CAPTURE)) return $html;
        $start=$m[0][1]+strlen($m[0][0]); $end=strpos($html,'</main>',$start);
        if ($end===false) return $html;
        $doc=\Dom\HTMLDocument::createFromString('<!doctype html><html><body><main>'.substr($html,$start,$end-$start).'</main></body></html>',LIBXML_NOERROR,'UTF-8');
        $main=$doc->querySelector('main'); $changed=false;
        foreach ($main->querySelectorAll(self::REGIONS) as $region) {
            $regionId=$region->id ?: $region->className;
            $walk=function ($node,string $path) use (&$walk,$edit,$saved,$overlay,&$inventory,&$changed,$regionId) {
                if ($node->matches(self::SKIP) || ($path!=='root' && $node->matches(self::REGIONS))) return;
                $nodes=iterator_to_array($node->childNodes);
                $texts=array_values(array_filter($nodes,static fn($n)=>$n instanceof \Dom\Text && trim($n->data)!==''));
                $link=$node->localName==='a' && $node->hasAttribute('href');
                $image=$node->localName==='img' && $node->hasAttribute('src');
                $direct=count($texts)===1 ? $texts[0] : null;
                $defaults=[];
                if ($link) $defaults['url']=$node->getAttribute('href');
                if ($image) {$defaults['image']=$node->getAttribute('src');$defaults['alt']=$node->getAttribute('alt')??'';}
                if ($direct && !$image && preg_match('/[\pL\pN]/u',$direct->data) && !preg_match('/^\d+$/D',trim($direct->data))) $defaults['text']=trim($direct->data);
                if ($defaults) {
                    $id=substr(hash('sha256',$regionId.'|'.$path.'|'.self::revision($defaults)),0,32);
                    $base=$saved[$id]??$defaults; $values=$overlay[$id]??$base;
                    if ($edit) {
                        $inventory[$id]=$defaults;
                        $node->setAttribute('data-canvas-element',$id);
                        $node->setAttribute('data-canvas-values',json_encode($values,JSON_THROW_ON_ERROR));
                        $node->setAttribute('data-canvas-revision',self::revision($base));
                        $node->setAttribute('data-canvas-base',json_encode($base,JSON_THROW_ON_ERROR));
                        $node->setAttribute('data-canvas-defaults',json_encode($defaults,JSON_THROW_ON_ERROR));
                        if ($values!==$defaults) $node->setAttribute('data-canvas-modified','');
                    }
                    if ($values!==$defaults) {
                        $changed=true;
                        if (isset($values['url'])) $node->setAttribute('href',$values['url']);
                        if (isset($values['image'])) {$node->setAttribute('src',$values['image']);$node->setAttribute('alt',$values['alt']);}
                        if (isset($values['text'])) $direct->data=(preg_match('/^\s/u',$direct->data)?' ':'').$values['text'].(preg_match('/\s$/u',$direct->data)?' ':'');
                    }
                }
                $counts=[];
                foreach ($nodes as $child) if ($child instanceof \Dom\Element) {
                    $tag=$child->localName; $counts[$tag]=($counts[$tag]??0)+1;
                    $walk($child,$path.'/'.$tag.':'.$counts[$tag]);
                }
            };
            $walk($region,'root');
        }
        if (!$edit && !$changed) return $html;
        $inner='';
        foreach ($main->childNodes as $child) $inner.=$doc->saveHtml($child);
        return substr($html,0,$start).$inner.substr($html,$end);
    }
}
