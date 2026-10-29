<?php

declare(strict_types=1);

$page = (array) ($page ?? []);
$page['template'] = ($page['kind'] ?? '') === 'home' ? 'home' : (string) ($page['template'] ?? 'default');
$appearance = (array) ($themeSettings ?? []);
$theme = (array) ($descriptor ?? []);
$locale = (string) ($locale ?? 'en');
$defaultLocale = (string) ($fallbackLocale ?? $locale);
$profile = array_replace(['name' => 'Villa Italiana', 'facility_city' => 'Gdynia'], (array) ($profile ?? []));
$isFacilityHome = !empty($isFacilityHome) || ($page['kind'] ?? '') === 'home' || ($page['template'] ?? '') === 'home';
$contentType = ($page['kind'] ?? '') === 'post' ? 'post' : 'page';
$menu = (array) ($navigation['primary'] ?? []);
$footerMenu = (array) ($navigation['footer'] ?? []);
$languages = is_array($languages ?? null) ? $languages : [];
$languageUrls = is_array($languageUrls ?? null) ? $languageUrls : [];
$seo = $seo ?? \App\Core\SeoMeta::resolve([], [], $page, [
    'type' => $contentType,
    'locale' => $locale,
    'default_locale' => $defaultLocale,
    'base_url' => $baseUrl,
    'current_path' => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    'language_urls' => $languageUrls,
    'profile' => $profile,
    'fallback_image' => (string) ($theme['default_social_image'] ?? ''),
    'fallback_image_type' => (string) ($theme['default_social_image_type'] ?? ''),
    'fallback_image_width' => (int) ($theme['default_social_image_width'] ?? 0),
    'fallback_image_height' => (int) ($theme['default_social_image_height'] ?? 0),
]);

require __DIR__ . '/views/page.php';
