<?php

declare(strict_types=1);

namespace App\Core;

final class HtmlSanitizer
{
    private const ALLOWED = ['a','abbr','article','aside','b','blockquote','br','caption','cite','code','dd','details','div','dl','dt','em','figcaption','figure','h2','h3','h4','h5','h6','hr','i','img','li','mark','ol','p','pre','section','small','span','strong','sub','summary','sup','table','tbody','td','tfoot','th','thead','tr','u','ul'];
    private const ATTRIBUTES = ['alt','aria-label','class','colspan','height','href','loading','rel','role','rowspan','src','target','title','width'];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div data-sensecms-root>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $root = $document->getElementsByTagName('div')->item(0);
        if (!$root) return '';
        self::cleanChildren($root);
        $result = '';
        foreach ($root->childNodes as $child) $result .= $document->saveHTML($child);
        return trim($result);
    }

    private static function cleanChildren(\DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof \DOMElement) continue;
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED, true)) {
                if (in_array($tag, ['script','style','iframe','object','embed','form','input','button','textarea','select','link','meta'], true)) { $parent->removeChild($node); continue; }
                while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                $parent->removeChild($node); continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, self::ATTRIBUTES, true) && !str_starts_with($name, 'aria-')) { $node->removeAttributeNode($attribute); continue; }
                if (in_array($name, ['href','src'], true) && !self::safeUrl($attribute->value, $tag === 'a')) $node->removeAttribute($name);
            }
            if ($tag === 'a' && $node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
            if ($tag === 'img' && !$node->hasAttribute('loading')) $node->setAttribute('loading', 'lazy');
            self::cleanChildren($node);
        }
    }

    private static function safeUrl(string $value, bool $action): bool
    {
        $value = trim($value);
        if ($value === '') return true;
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) return !str_contains($value, '..');
        if ($action && (str_starts_with($value, '#') || str_starts_with($value, 'mailto:') || str_starts_with($value, 'tel:'))) return true;
        return filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http','https'], true);
    }
}
