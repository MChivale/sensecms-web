<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\CmsRepository;
use App\Core\ManifestRegistry;
use App\Core\LiveChatSettings;
use App\Core\PageBuilder;
use App\Core\FacilityRepository;
use App\Core\ThemeContract;
use App\Core\SiteChrome;
use App\Core\SeoMeta;

final class PublicController extends Controller
{
    public function __construct(private readonly CmsRepository $cms, private readonly FacilityRepository $facilities, private readonly ManifestRegistry $themes, private readonly ManifestRegistry $plugins, private readonly ManifestRegistry $addons, private readonly array $config) {}

    /** Selects a published locale without overriding an explicit locale in the URL. */
    public function preferredLocale(): string
    {
        $available = array_values(array_filter(array_column($this->cms->languages(), 'locale'), 'is_string'));
        $fallback = $this->cms->defaultLocale($this->config['default_locale'] ?? 'en');
        foreach (['HTTP_ACCEPT_LANGUAGE', 'HTTP_SEC_CH_UA_LANG', 'HTTP_X_SYSTEM_LANGUAGE'] as $header) {
            $locale = $this->localeFromHeader((string) ($_SERVER[$header] ?? ''), $available);
            if ($locale !== null) return $locale;
        }
        $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_GEO_COUNTRY'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '')));
        if ($country === '' && function_exists('geoip_country_code_by_name')) {
            $country = strtoupper((string) @geoip_country_code_by_name((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
        }
        $countryLocale = match ($country) {
            'KH' => 'km',
            'CN', 'HK', 'MO', 'SG', 'TW' => 'zh',
            'DE', 'AT', 'CH' => 'de',
            'PL' => 'pl',
            default => $fallback,
        };
        return in_array($countryLocale, $available, true) ? $countryLocale : $fallback;
    }

    private function localeFromHeader(string $header, array $available): ?string
    {
        foreach (explode(',', $header) as $part) {
            $candidate = strtolower(trim(explode(';', $part, 2)[0]));
            if ($candidate === '' || $candidate === '*') continue;
            $candidate = str_replace('_', '-', $candidate);
            $choices = [$candidate, substr($candidate, 0, 2)];
            if (str_starts_with($candidate, 'zh')) $choices[] = 'zh';
            foreach ($choices as $choice) if (in_array($choice, $available, true)) return $choice;
        }
        return null;
    }

    public function show(string $locale, string $slug = 'home'): never
    {
        $fallback=$this->cms->defaultLocale($this->config['default_locale']);$facility=$this->facilities->primary($locale,$fallback);if(!$facility){http_response_code(404);exit('Facility not found');}$this->renderFacility($locale,$slug,$facility,false);
    }

    public function showPageAtPath(array $route): never
    {
        $locale = $this->cms->defaultLocale($this->config['default_locale']);
        foreach ($this->facilities->active($locale, $locale) as $facility) {
            if ((int) $facility['id'] === (int) $route['facility_id']) {
                $this->renderFacility($locale, null, $facility, false, null, (int) $route['id']);
            }
        }
        http_response_code(404); exit('Page not found');
    }

    public function showFacility(string$locale,string$citySlug,string$facilitySlug,?string$slug=null):never
    {
        $fallback=$this->cms->defaultLocale($this->config['default_locale']);$facility=$this->facilities->resolve($citySlug,$facilitySlug,$locale,$fallback);if(!$facility){http_response_code(404);exit('Facility not found');}$this->renderFacility($locale,$slug,$facility,$slug===null);
    }

    public function showPost(string $locale, string $slug): never
    {
        $fallback=$this->cms->defaultLocale($this->config['default_locale']);$facility=$this->facilities->primary($locale,$fallback);if(!$facility){http_response_code(404);exit('Facility not found');}$this->renderFacility($locale,null,$facility,false,$slug);
    }

    public function showFacilityPost(string $locale, string $citySlug, string $facilitySlug, string $slug): never
    {
        $fallback=$this->cms->defaultLocale($this->config['default_locale']);$facility=$this->facilities->resolve($citySlug,$facilitySlug,$locale,$fallback);if(!$facility){http_response_code(404);exit('Facility not found');}$this->renderFacility($locale,null,$facility,false,$slug);
    }

    public function robots(): never
    {
        $global=array_replace(SeoMeta::globalDefaults(),(array)$this->cms->setting('seo_global',[]));$base=rtrim((string)($global['site_url']?:$this->config['base_url']),'/');
        header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: public, max-age=3600');
        echo "User-agent: *\nAllow: /\nDisallow: /login\nDisallow: /install\nDisallow: /content/\nDisallow: /system/\nDisallow: /appearance/\nDisallow: /system/extensions/\nDisallow: /conversations/\nDisallow: /api/\n\nSitemap: {$base}/sitemap.xml\n";exit;
    }

    public function sitemap(): never
    {
        $global=array_replace(SeoMeta::globalDefaults(),(array)$this->cms->setting('seo_global',[]));$base=rtrim((string)($global['site_url']?:$this->config['base_url']),'/');$rows=$this->cms->sitemapPages();$pages=[];$paths=$this->cms->publicPagePaths();$pathsById=array_flip($paths);$defaultLocale=$this->cms->defaultLocale($this->config['default_locale']);
        foreach($rows as$row){$id=(int)$row['id'];$type=(string)($row['content_type']??'page');$key=$type.'-'.$id;$home=$type==='page'&&(int)($row['homepage_page_id']??0)===$id;$locale=(string)$row['locale'];$root=!empty($row['is_primary'])?'/'.rawurlencode($locale):'/'.rawurlencode($locale).'/facilities/'.rawurlencode((string)$row['city_slug']).'/'.rawurlencode((string)$row['facility_slug']);$path=$type==='post'?$root.'/posts/'.rawurlencode((string)$row['slug']):($home?($root.(!empty($row['is_primary'])?'/home':'')):$root.'/'.rawurlencode((string)$row['slug']));if($type==='page'&&$locale===$defaultLocale&&isset($pathsById[$id]))$path=$pathsById[$id];$pages[$key]['urls'][$locale]=$base.$path;$pages[$key]['lastmod']=date(DATE_ATOM,strtotime((string)($row['updated_at']??$row['published_at']??'now')));}
        $escape=static fn(string$value):string=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');$xml=['<?xml version="1.0" encoding="UTF-8"?>','<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];
        foreach($pages as$page)foreach($page['urls']as$locale=>$url){$xml[]='<url><loc>'.$escape($url).'</loc><lastmod>'.$escape($page['lastmod']).'</lastmod>';foreach($page['urls']as$alternateLocale=>$alternateUrl)$xml[]='<xhtml:link rel="alternate" hreflang="'.$escape($alternateLocale).'" href="'.$escape($alternateUrl).'"/>';$default=reset($page['urls']);if(is_string($default))$xml[]='<xhtml:link rel="alternate" hreflang="x-default" href="'.$escape($default).'"/>';$xml[]='</url>';}$xml[]='</urlset>';
        $theme = $this->themes->find((string) $this->cms->setting('active_theme', 'sensecms'));
        if (is_file($theme['_path'] . '/pages.php')) {
            $static = require $theme['_path'] . '/pages.php';
            array_pop($xml);
            foreach (array_keys($static) as $route) if (!isset($paths[$route])) $xml[] = '<url><loc>' . $escape($base . $route) . '</loc></url>';
            $xml[] = '</urlset>';
        }
        header('Content-Type: application/xml; charset=utf-8');header('Cache-Control: no-store');echo implode('', $xml);exit;
    }

    private function renderFacility(string$locale,?string$slug,array$facility,bool$facilityHome,?string$postSlug=null,?int$pageId=null):never
    {
        unset($_SESSION['ai_chat_ended']);
        $languages = $this->cms->languages();$fallbackLocale=$this->cms->defaultLocale($this->config['default_locale']);
        $validLocales = array_column($languages, 'locale');
        if (!in_array($locale, $validLocales, true)) { http_response_code(404); exit('Language not found'); }
        $contentType=$postSlug===null?'page':'post';$page=$pageId!==null?$this->cms->pageById($pageId,$locale,$fallbackLocale,(int)$facility['id']):($contentType==='post'?$this->cms->publicPost((string)$postSlug,$locale,$fallbackLocale,(int)$facility['id']):($facilityHome&&$facility['homepage_page_id']?$this->cms->pageById((int)$facility['homepage_page_id'],$locale,$fallbackLocale,(int)$facility['id']):$this->cms->page($slug??'home',$locale,$fallbackLocale,(int)$facility['id'])));
        if (!$page) { http_response_code(404); exit($contentType==='post'?'Post not found':'Page not found'); }
        if($contentType==='post')$page['blocks']=[['type'=>'text','payload'=>['eyebrow'=>'News','title'=>$page['title'],'text'=>$page['content']??''],'shared'=>[]]];
        $activeTheme=(string)$this->cms->setting('active_theme','sensecms');$preview=$this->themePreview();$themeSlug=$preview['slug']??$activeTheme;$theme=$this->themes->find($themeSlug);$configs=(array)$this->cms->setting('theme_configurations',[]);$appearance=$this->themeConfiguration($themeSlug,$theme,$configs);$isThemePreview=$preview!==null;if($isThemePreview){$drafts=(array)$this->cms->setting('theme_drafts',[]);$draft=is_array($drafts[$themeSlug]??null)?$drafts[$themeSlug]:[];$appearance=ThemeContract::sanitize($theme,(array)($draft['settings']??[]),$appearance);}
        $states=(array)$this->cms->setting('extension_states',[]);$activeAddons=array_keys(array_filter($states));$catalog = PageBuilder::catalog($theme, $this->plugins->all(), $this->cms->activePluginSlugs(),$this->addons->all(),$activeAddons);
        $supportedBlocks = array_fill_keys(array_keys($catalog), true);
        if ($supportedBlocks) $page['blocks'] = array_values(array_filter((array) ($page['blocks'] ?? []), static fn (array $block): bool => isset($supportedBlocks[(string) ($block['type'] ?? '')])));
        $page['blocks'] = array_map(static function(array $block)use($catalog):array{$definition=$catalog[(string)($block['type']??'')]??[];$block['component_renderer']=$definition['renderer']??'';$block['component_source']=$definition['source']??'';return$block;},(array)$page['blocks']);
        $profile = $this->cms->setting('school_profile', []);
        foreach(['email','phone','secondary_phone','website_url','map_url','address']as$key)if(trim((string)($facility[$key]??''))!=='')$profile[$key]=$facility[$key];$profile['facility_name']=$facility['name'];$profile['facility_city']=$facility['city_name'];
        $seoGlobal=array_replace_recursive(SeoMeta::globalDefaults(),(array)$this->cms->setting('seo_global',[]));$seoDocument=(array)$this->cms->setting('seo_'.$contentType.'_' . (int) $page['id'] . '_' . $locale, []);if($contentType==='post'&&($seoDocument['og_image']??'')===''&&($page['image']??'')!=='')$seoDocument=array_replace(['og_image'=>$page['image'],'og_image_alt'=>$page['image_alt']??'','og_image_width'=>(int)($page['image_width']??0),'og_image_height'=>(int)($page['image_height']??0),'og_image_type'=>$page['image_type']??'','author'=>$page['author_name']??''],$seoDocument);
        if (!empty($appearance['logo_url'])) $profile['logo'] = $appearance['logo_url'];
        $navigation = $this->cms->publicNavigation($locale, $fallbackLocale);
        $navigationBase = '/' . rawurlencode($locale) . '/facilities/' . rawurlencode($facility['city_slug']) . '/' . rawurlencode($facility['facility_slug']);
        foreach ($navigation as &$links) $links = $this->scopeMenu($links, $locale, $facility, $navigationBase);
        unset($links);
        $isFacilityHome=$contentType==='page'&&($facilityHome||(int)($facility['homepage_page_id']??0)===(int)$page['id']);$states=array_replace(['page-popups'=>true,'forms'=>true,'live-chat'=>true,'facility-geolocation'=>true],(array)$this->cms->setting('extension_states',[]));$campaigns=(array)$this->cms->setting('page_popups',[]);$configured=$contentType==='page'?($campaigns[(string)(int)$page['id']]??($isFacilityHome&&$facility['is_primary']?$this->cms->setting('home_popup',null):null)):null;$popup=$states['page-popups']&&is_array($configured)?$configured:['enabled'=>false];
        $facilityList=$this->facilities->publicList($locale,$fallbackLocale);$facilityBase='/'.rawurlencode($locale).'/facilities/'.rawurlencode((string)$facility['city_slug']).'/'.rawurlencode((string)$facility['facility_slug']);$legacy=(bool)$facility['is_primary'];$languageUrls=[];$localizedSlugs=$contentType==='post'?$this->cms->postSlugs((int)$page['id']):$this->cms->pageSlugs((int)$page['id']);foreach($languages as$language){$code=(string)$language['locale'];$base=$legacy?'/'.rawurlencode($code):'/'.rawurlencode($code).'/facilities/'.rawurlencode((string)$facility['city_slug']).'/'.rawurlencode((string)$facility['facility_slug']);$localizedSlug=(string)($localizedSlugs[$code]??$localizedSlugs[$fallbackLocale]??$page['localized_slug']);if($contentType==='post')$languageUrls[$code]=$base.'/posts/'.rawurlencode($localizedSlug);else{$home=$facilityHome||(int)($facility['homepage_page_id']??0)===(int)$page['id'];$languageUrls[$code]=$home?($legacy?$base.'/home':$base):$base.'/'.rawurlencode($localizedSlug);}}$facilityGeolocation=array_replace(['mode'=>'redirect','auto_prompt'=>true,'remember_days'=>30],(array)$this->cms->setting('facility_geolocation',[]));$facilityGeolocation['enabled']=$states['facility-geolocation']&&count($facilityList)>1;
        if ($contentType === 'page' && !empty($page['public_path'])) $languageUrls[$fallbackLocale] = $page['public_path'];
        $menu=$navigation['primary']??[];$footerMenu=$navigation['footer']??[];$footerConnectMenu=$navigation['footer-connect']??[];$siteChrome=SiteChrome::resolve((array)$this->cms->setting('site_chrome',SiteChrome::defaults()),$locale,$fallbackLocale,$profile);$showHostingCredit=(bool)$this->cms->setting('show_hosting_credit',true);$seo=SeoMeta::resolve($seoGlobal,$seoDocument,$page,['type'=>$contentType,'locale'=>$locale,'default_locale'=>$fallbackLocale,'base_url'=>$this->config['base_url'],'current_path'=>$languageUrls[$locale]??('/'.$locale.'/'.$page['localized_slug']),'language_urls'=>$languageUrls,'profile'=>$profile,'logo'=>$appearance['logo_url']??$profile['logo']??'','fallback_image'=>(string)($theme['default_social_image']??''),'facility'=>$facility]);$latestPosts=$this->cms->latestPosts($locale,$fallbackLocale,3,(int)$facility['id']);foreach($latestPosts as&$latestPost){$latestBase=$legacy?'/'.rawurlencode($locale):$facilityBase;$latestPost['url']=$latestBase.'/posts/'.rawurlencode((string)$latestPost['slug']);}unset($latestPost);$this->view((string)$theme['_view_path'], compact('page', 'theme', 'locale', 'languages', 'appearance','menu','footerMenu','footerConnectMenu','siteChrome','showHostingCredit','isThemePreview','contentType') + [
            'profile' => $profile,
            'themeSettings' => $appearance,
            'popup' => $popup,
            'extensionStates' => $states,
            'liveChatSettings' => LiveChatSettings::from($this->cms->setting('live_chat_settings', [])),
            'latestPosts' => $latestPosts,
            'facility'=>$facility,'facilities'=>$facilityList,'facilityBase'=>$facilityBase,'languageUrls'=>$languageUrls,'facilityGeolocation'=>$facilityGeolocation,'isFacilityHome'=>$isFacilityHome,
            'baseUrl' => $this->config['base_url'],
            'projectRoot' => dirname(__DIR__, 2),
            'navigation' => $navigation,
            'seo' => $seo,
            'formCsrf' => $this->publicFormToken(),
            'formCaptchaEnabled' => (bool) (((array) $this->cms->setting('captcha_settings', []))['enabled'] ?? true),
        ]);
    }

    private function publicFormToken(): string
    {
        if (empty($_SESSION['public_form_csrf'])) $_SESSION['public_form_csrf'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['public_form_csrf'];
    }

    private function themePreview():?array
    {
        $token=(string)($_GET['sensecms_theme_preview']??'');$preview=$_SESSION['sensecms_theme_preview']??null;if($token===''||!is_array($preview)||(int)($preview['expires']??0)<time()||(int)($preview['user_id']??0)<1||!isset($_SESSION['user_id'])||(int)$_SESSION['user_id']!==(int)$preview['user_id']||!hash_equals((string)($preview['token']??''),$token))return null;return$preview;
    }

    private function themeConfiguration(string$slug,array$theme,array$configs):array
    {
        $parent=(string)($theme['parent']??'');$base=$parent!==''?$this->themeConfiguration($parent,$this->themes->find($parent),$configs):ThemeContract::defaults($theme);if($slug===(string)$this->cms->setting('active_theme','sensecms'))$base=array_replace($base,(array)$this->cms->setting('theme_settings',[]));return ThemeContract::sanitize($theme,(array)($configs[$slug]??[]),$base);
    }

    private function scopeMenu(array$items,string$locale,array$facility,string$facilityBase):array
    {
        if($facility['is_primary'])return$items;$prefix='/'.rawurlencode($locale).'/';foreach($items as&$item){$url=(string)($item['url']??'');if(!str_starts_with($url,$prefix))continue;[$path,$fragment]=array_pad(explode('#',$url,2),2,'');$slug=trim(substr($path,strlen($prefix)),'/');if($slug===''||str_contains($slug,'/'))continue;$item['url']=($slug==='home'?$facilityBase:$facilityBase.'/'.rawurlencode($slug)).($fragment!==''?'#'.rawurlencode($fragment):'');}unset($item);return$items;
    }
}
