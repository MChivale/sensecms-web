<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class SeoMeta
{
    public static function globalDefaults(): array
    {
        return [
            'site_name' => 'Sense CMS', 'alternate_name' => '', 'site_url' => '',
            'organization_type' => 'Organization', 'organization_description' => '',
            'organization_logo' => '', 'default_social_image' => '',
            'default_social_image_alt' => '', 'default_image_width' => 1200, 'default_image_height' => 630,
            'default_image_type' => 'image/webp', 'author' => '', 'publisher' => '',
            'twitter_site' => '', 'twitter_creator' => '', 'facebook_app_id' => '',
            'facebook_url' => '', 'instagram_url' => '', 'linkedin_url' => '', 'youtube_url' => '',
            'country_code' => '', 'google_site_verification' => '', 'bing_site_verification' => '',
            'yandex_verification' => '', 'pinterest_domain_verify' => '',
            'robots' => 'index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1',
            'localized' => [],
        ];
    }

    public static function documentDefaults(string $type): array
    {
        return [
            'title' => '', 'description' => '', 'keywords' => '', 'canonical' => '',
            'robots' => 'index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1',
            'author' => '', 'schema_type' => $type === 'post' ? 'BlogPosting' : 'WebPage',
            'og_type' => $type === 'post' ? 'article' : 'website', 'og_title' => '',
            'og_description' => '', 'og_image' => '', 'og_image_alt' => '',
            'og_image_width' => 0, 'og_image_height' => 0, 'og_image_type' => '',
            'twitter_card' => 'summary_large_image', 'twitter_title' => '',
            'twitter_description' => '', 'twitter_image' => '', 'twitter_image_alt' => '',
            'twitter_creator' => '',
        ];
    }

    public static function sanitizeGlobal(array $input, array $languages): array
    {
        $result = self::globalDefaults();
        foreach (['site_name','alternate_name','organization_description','author','publisher','twitter_site','twitter_creator','facebook_app_id','country_code','google_site_verification','bing_site_verification','yandex_verification','pinterest_domain_verify','default_social_image_alt'] as $key) {
            $result[$key] = self::text($input[$key] ?? '', $key === 'organization_description' ? 1000 : 255);
        }
        $result['organization_type'] = self::choice($input['organization_type'] ?? '', ['EducationalOrganization','School','CollegeOrUniversity','Organization'], 'EducationalOrganization');
        $result['robots'] = self::robots((string) ($input['robots'] ?? ''));
        foreach (['site_url','organization_logo','default_social_image','facebook_url','instagram_url','linkedin_url','youtube_url'] as $key) {
            $value = self::text($input[$key] ?? '', 1000);
            if ($value !== '' && !self::validUrl($value, $key !== 'site_url')) throw new InvalidArgumentException('Enter a valid HTTPS URL or local asset path for ' . str_replace('_', ' ', $key) . '.');
            $result[$key] = $key === 'site_url' ? rtrim($value, '/') : $value;
        }
        $result['default_image_width'] = self::dimension($input['default_image_width'] ?? 1200, 1200);
        $result['default_image_height'] = self::dimension($input['default_image_height'] ?? 630, 630);
        $result['default_image_type'] = self::choice($input['default_image_type'] ?? '', ['image/jpeg','image/png','image/webp','image/gif'], 'image/webp');
        $localized = is_array($input['localized'] ?? null) ? $input['localized'] : [];
        foreach ($languages as $language) {
            $locale = (string) ($language['locale'] ?? '');
            if ($locale === '') continue;
            $source = is_array($localized[$locale] ?? null) ? $localized[$locale] : [];
            $result['localized'][$locale] = [
                'title_template' => self::text($source['title_template'] ?? '%title%', 255),
                'default_title' => self::text($source['default_title'] ?? '', 255),
                'default_description' => self::text($source['default_description'] ?? '', 500),
                'default_keywords' => self::text($source['default_keywords'] ?? '', 500),
                'default_social_image_alt' => self::text($source['default_social_image_alt'] ?? '', 420),
            ];
        }
        return $result;
    }

    public static function sanitizeDocument(array $input, string $type): array
    {
        $result = self::documentDefaults($type);
        foreach (['title'=>255,'description'=>500,'keywords'=>500,'author'=>255,'og_title'=>255,'og_description'=>500,'og_image_alt'=>420,'og_image_type'=>100,'twitter_title'=>255,'twitter_description'=>500,'twitter_image_alt'=>420,'twitter_creator'=>255] as $key => $limit) {
            $result[$key] = self::text($input[$key] ?? '', $limit);
        }
        foreach (['canonical','og_image','twitter_image'] as $key) {
            $value = self::text($input[$key] ?? '', 1000);
            if ($value !== '' && !self::validUrl($value, $key !== 'canonical')) throw new InvalidArgumentException('Enter a valid HTTPS URL or local path for ' . str_replace('_', ' ', $key) . '.');
            $result[$key] = $value;
        }
        $result['robots'] = self::robots((string) ($input['robots'] ?? ''));
        $result['schema_type'] = self::choice($input['schema_type'] ?? '', $type === 'post' ? ['Article','BlogPosting','NewsArticle'] : ['WebPage','AboutPage','ContactPage','CollectionPage'], $type === 'post' ? 'BlogPosting' : 'WebPage');
        $result['og_type'] = self::choice($input['og_type'] ?? '', $type === 'post' ? ['article','website'] : ['website','article'], $type === 'post' ? 'article' : 'website');
        $result['twitter_card'] = self::choice($input['twitter_card'] ?? '', ['summary_large_image','summary'], 'summary_large_image');
        $result['og_image_width'] = self::dimension($input['og_image_width'] ?? 0, 0);
        $result['og_image_height'] = self::dimension($input['og_image_height'] ?? 0, 0);
        return $result;
    }

    public static function resolve(array $global, array $document, array $content, array $context): array
    {
        $global = array_replace(self::globalDefaults(), $global);
        $type = (string) ($context['type'] ?? 'page');
        $document = array_replace(self::documentDefaults($type), $document);
        $locale = (string) ($context['locale'] ?? 'en');
        $localized = is_array($global['localized'][$locale] ?? null) ? $global['localized'][$locale] : [];
        $siteName = self::first($global['site_name'] ?? '', $context['profile']['name'] ?? '', 'Sense CMS');
        $rawTitle = self::first($document['title'] ?? '', $content['seo_title'] ?? '', $content['title'] ?? '', $localized['default_title'] ?? '', $siteName);
        $template = self::first($localized['title_template'] ?? '', '%title%');
        $title = trim(strtr($template, ['%title%' => $rawTitle, '%site_name%' => $siteName]));
        $description = self::first($document['description'] ?? '', $content['seo_description'] ?? '', $content['excerpt'] ?? '', $localized['default_description'] ?? '', $global['organization_description'] ?? '');
        $baseUrl = rtrim(self::first($global['site_url'] ?? '', $context['base_url'] ?? ''), '/');
        $currentPath = (string) ($context['current_path'] ?? '/');
        $canonical = self::absolute(self::first($document['canonical'] ?? '', $currentPath), $baseUrl);
        $image = self::absolute(self::first($document['og_image'] ?? '', $global['default_social_image'] ?? '', $context['fallback_image'] ?? ''), $baseUrl);
        $twitterImage = self::absolute(self::first($document['twitter_image'] ?? '', $image), $baseUrl);
        $logo = self::absolute(self::first($global['organization_logo'] ?? '', $context['logo'] ?? ''), $baseUrl);
        $imageAlt = self::first($document['og_image_alt'] ?? '', $localized['default_social_image_alt'] ?? '', $global['default_social_image_alt'] ?? '', $rawTitle);
        $twitterAlt = self::first($document['twitter_image_alt'] ?? '', $imageAlt);
        $width = (int) ($document['og_image_width'] ?: $global['default_image_width']);
        $height = (int) ($document['og_image_height'] ?: $global['default_image_height']);
        $imageType = self::first($document['og_image_type'] ?? '', $global['default_image_type'] ?? '');
        $author = self::first($document['author'] ?? '', $global['author'] ?? '', $siteName);
        $publisher = self::first($global['publisher'] ?? '', $siteName);
        $alternates = [];
        foreach ((array) ($context['language_urls'] ?? []) as $code => $path) $alternates[(string) $code] = self::absolute((string) $path, $baseUrl);
        $defaultLocale = (string) ($context['default_locale'] ?? $locale);
        $social = array_values(array_filter(array_map('strval', [$global['facebook_url'] ?? '', $global['instagram_url'] ?? '', $global['linkedin_url'] ?? '', $global['youtube_url'] ?? ''])));
        $orgId = $baseUrl . '/#organization'; $websiteId = $baseUrl . '/#website'; $pageId = $canonical . '#webpage';
        $organization = ['@type' => $global['organization_type'], '@id' => $orgId, 'name' => $siteName, 'url' => $baseUrl];
        if (($global['alternate_name'] ?? '') !== '') $organization['alternateName'] = $global['alternate_name'];
        if (($global['organization_description'] ?? '') !== '') $organization['description'] = $global['organization_description'];
        if ($logo !== '') $organization['logo'] = ['@type'=>'ImageObject','url'=>$logo];
        if ($social) $organization['sameAs'] = $social;
        foreach (['email','telephone'] as $schemaKey) { $profileKey = $schemaKey === 'telephone' ? 'phone' : 'email'; if (($context['profile'][$profileKey] ?? '') !== '') $organization[$schemaKey] = $context['profile'][$profileKey]; }
        if (($context['profile']['address'] ?? '') !== '') $organization['address'] = ['@type'=>'PostalAddress','streetAddress'=>$context['profile']['address'],'addressCountry'=>$global['country_code'] ?: null];
        $webPage = ['@type'=>$type==='post'?'WebPage':$document['schema_type'],'@id'=>$pageId,'url'=>$canonical,'name'=>$title,'description'=>$description,'inLanguage'=>$locale,'isPartOf'=>['@id'=>$websiteId],'about'=>['@id'=>$orgId]];
        if ($image !== '') $webPage['primaryImageOfPage'] = ['@type'=>'ImageObject','url'=>$image,'caption'=>$imageAlt,'width'=>$width ?: null,'height'=>$height ?: null];
        $graph = [$organization, ['@type'=>'WebSite','@id'=>$websiteId,'url'=>$baseUrl,'name'=>$siteName,'publisher'=>['@id'=>$orgId],'inLanguage'=>$locale], $webPage];
        if ($type === 'post' || $document['og_type'] === 'article') $graph[] = ['@type'=>$document['schema_type'],'headline'=>$rawTitle,'description'=>$description,'url'=>$canonical,'mainEntityOfPage'=>['@id'=>$pageId],'image'=>$image !== '' ? [$image] : [],'author'=>['@type'=>'Person','name'=>$author],'publisher'=>['@id'=>$orgId],'datePublished'=>$content['published_at'] ?? null,'dateModified'=>$content['updated_at'] ?? null,'inLanguage'=>$locale];
        return array_replace($document, [
            'site_name'=>$siteName,'site_url'=>$baseUrl,'raw_title'=>$rawTitle,'title'=>$title,'description'=>$description,
            'keywords'=>self::first($document['keywords'] ?? '', $localized['default_keywords'] ?? ''),'canonical'=>$canonical,
            'author'=>$author,'publisher'=>$publisher,'image'=>$image,'image_alt'=>$imageAlt,'image_width'=>$width,'image_height'=>$height,'image_type'=>$imageType,
            'og_title'=>self::first($document['og_title'] ?? '', $title),'og_description'=>self::first($document['og_description'] ?? '', $description),
            'twitter_title'=>self::first($document['twitter_title'] ?? '', $document['og_title'] ?? '', $title),
            'twitter_description'=>self::first($document['twitter_description'] ?? '', $document['og_description'] ?? '', $description),
            'twitter_image'=>$twitterImage,'twitter_image_alt'=>$twitterAlt,
            'twitter_site'=>$global['twitter_site'],'twitter_creator'=>self::first($document['twitter_creator'] ?? '', $global['twitter_creator'] ?? ''),
            'facebook_app_id'=>$global['facebook_app_id'],'verification'=>['google'=>$global['google_site_verification'],'bing'=>$global['bing_site_verification'],'yandex'=>$global['yandex_verification'],'pinterest'=>$global['pinterest_domain_verify']],
            'alternates'=>$alternates,'x_default'=>$alternates[$defaultLocale] ?? reset($alternates) ?: $canonical,
            'og_locale'=>self::ogLocale($locale),'og_locale_alternates'=>array_values(array_map([self::class,'ogLocale'],array_keys(array_diff_key($alternates,[$locale=>true])))),
            'json_ld'=>['@context'=>'https://schema.org','@graph'=>self::clean($graph)],
        ]);
    }

    public static function ogLocale(string $locale): string
    {
        return ['en'=>'en_GB','de'=>'de_DE','zh'=>'zh_CN','pl'=>'pl_PL','km'=>'km_KH'][$locale] ?? str_replace('-', '_', $locale);
    }

    private static function text(mixed $value, int $limit): string { return mb_substr(trim((string) $value), 0, $limit); }
    private static function choice(mixed $value, array $allowed, string $fallback): string { $value=(string)$value;return in_array($value,$allowed,true)?$value:$fallback; }
    private static function dimension(mixed $value, int $fallback): int { $value=(int)$value;return $value===0?0:($value>=1&&$value<=10000?$value:$fallback); }
    private static function first(mixed ...$values): string { foreach($values as$value){$value=trim((string)$value);if($value!=='')return$value;}return''; }
    private static function robots(string $value): string { $allowed=['index,follow','noindex,follow','noindex,nofollow','index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1'];return in_array($value,$allowed,true)?$value:$allowed[3]; }
    private static function validUrl(string $value, bool $allowLocal): bool { if($allowLocal&&str_starts_with($value,'/')&&!str_starts_with($value,'//'))return true;if(!filter_var($value,FILTER_VALIDATE_URL))return false;return strtolower((string)parse_url($value,PHP_URL_SCHEME))==='https'; }
    private static function absolute(string $value, string $baseUrl): string { if($value==='')return'';if(preg_match('#^https?://#i',$value))return$value;return $baseUrl.'/'.ltrim($value,'/'); }
    private static function clean(mixed $value): mixed { if(!is_array($value))return$value;$result=[];foreach($value as$key=>$item){$item=self::clean($item);if($item===null||$item===''||$item===[])continue;$result[$key]=$item;}return$result; }
}
