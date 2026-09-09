<?php

declare(strict_types=1);

namespace App\Core;

final class ExtensionCatalog
{
    public static function modules(): array
    {
        $definitions = require dirname(__DIR__, 2) . '/config/page-builder.php';
        $items = [];
        foreach ($definitions as $slug => $definition) {
            $items[] = [
                'slug' => (string) $slug,
                'name' => (string) ($definition['label'] ?? $slug),
                'description' => (string) ($definition['description'] ?? ''),
                'icon' => (string) ($definition['icon'] ?? 'box'),
                'group' => (string) ($definition['group'] ?? 'Content'),
                'publisher' => 'QUANT Software House',
                'version' => '1.0.0',
                'status' => 'system',
            ];
        }
        return $items;
    }

    public static function addons(array $states = []): array
    {
        return [
            ['slug'=>'page-popups','name'=>'Page Pop-ups','description'=>'Multilingual, scheduled campaign pop-ups configured independently for every page.','icon'=>'panel-top-open','group'=>'Engagement','publisher'=>'QUANT Software House','version'=>'1.0.0','status'=>($states['page-popups']??true)?'active':'inactive','config_url'=>'/system/extensions/popups'],
            ['slug'=>'live-chat','name'=>'Live Chat','description'=>'Human support, teams, transfers, retention, availability and conversation transcripts.','icon'=>'messages-square','group'=>'Communication','publisher'=>'QUANT Software House','version'=>'1.0.0','status'=>($states['live-chat']??true)?'active':'inactive','config_url'=>'/conversations/configuration'],
            ['slug'=>'facility-geolocation','name'=>'Facility Geolocation','description'=>'Privacy-conscious nearest-facility discovery, distance-aware switching and optional automatic routing.','icon'=>'map-pinned','group'=>'Facility experience','publisher'=>'QUANT Software House','version'=>'1.0.0','status'=>($states['facility-geolocation']??true)?'active':'inactive','config_url'=>'/content/facilities?tab=geolocation'],
            ['slug'=>'surveys','name'=>'Professional Surveys','description'=>'Multilingual research and feedback campaigns with conditional paths, NPS/CSAT analytics, privacy controls and professional exports.','icon'=>'clipboard-list','group'=>'Research & feedback','publisher'=>'QUANT Software House','version'=>'1.0.0','status'=>($states['surveys']??true)?'active':'inactive','config_url'=>'/surveys'],
        ];
    }

    public static function catalog(): array
    {
        return [];
    }
}
