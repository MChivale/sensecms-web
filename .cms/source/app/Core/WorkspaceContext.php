<?php

declare(strict_types=1);

namespace App\Core;

/** Working context narrows lists; it never grants or replaces authorization. */
final class WorkspaceContext
{
    private ?array $options = null;
    private ?string $sessionKey = null;

    public function __construct(private readonly Auth $auth, private readonly AccessControl $access, private readonly FacilityRepository $facilities) {}

    public function options(): array { return $this->options ??= $this->facilities->options(false, $this->access->facilityIds()); }
    private function key(): string { return $this->sessionKey ??= 'workspace_facility_' . (int) $this->auth->id(); }
    public function id(): int
    {
        $id = (int) ($_SESSION[$this->key()] ?? 0);
        if ($id && !in_array($id, array_map('intval', array_column($this->options(), 'id')), true)) { unset($_SESSION[$this->key()]); return 0; }
        return $id;
    }
    public function select(int $id): void
    {
        if ($id < 0 || ($id && !in_array($id, array_map('intval', array_column($this->options(), 'id')), true))) throw new \RuntimeException('This facility is not available in your workspace.', 403);
        $_SESSION[$this->key()] = $id;
    }
    public function scope(): ?array { $id = $this->id(); return $id ? [$id] : $this->access->facilityIds(); }
    public function record(int $id): void
    {
        // Direct links retain the actual record's context, after its permission check.
        if ($this->id() && $this->id() !== $id) $this->select($id);
    }
    public function state(): array
    {
        $id = $this->id(); $options = $this->options(); $label = 'All facilities';
        foreach ($options as $option) if ((int) $option['id'] === $id) $label = ($option['city_name'] ?? $option['city_slug']) . ' · ' . $option['name'];
        return ['id'=>$id, 'label'=>$label, 'options'=>$options];
    }

    public static function returnUrl(string $url, string $fallback): string
    {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\\\\]/', $url)) return $fallback;
        $parts = parse_url($url);
        if (!$parts || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])) return $fallback;
        $path = $parts['path'] ?? '';
        if (!preg_match('~^/(?:dashboard|content/(?:pages|posts)(?:/[1-9][0-9]*/edit)?|content/(?:builder|media|workflow|facilities)|seo|surveys|forms/submissions|ai(?:/knowledge)?|conversations(?:/configuration)?|appearance(?:/themes)?|marketplace|system/(?:access|extensions|update|cache|search-index|email|sounds|notifications|captcha|languages)|social-publishing|calendar|license|profile|settings)$~D', $path)) return $fallback;
        parse_str($parts['query'] ?? '', $query);
        $query = array_filter(array_intersect_key($query, array_flip(['q','status','state','page','facility','facility_id','document','type','tab','lang','edit','from'])), 'is_scalar');
        return $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
    public static function locale(array $languages, mixed $requested): string
    {
        $locales = array_column($languages, 'locale');
        if (is_string($requested) && in_array($requested, $locales, true)) return $requested;
        foreach ($languages as $language) if (!empty($language['is_default'])) return (string) $language['locale'];
        return (string) ($locales[0] ?? 'en');
    }
    public static function switchTarget(string $url): string
    {
        $url = self::returnUrl($url, '/dashboard'); $parts = parse_url($url); $path = $parts['path'];
        if (preg_match('~^/content/(pages|posts)/[0-9]+/edit$~', $path, $match)) return '/content/' . $match[1];
        parse_str($parts['query'] ?? '', $query);
        foreach (['facility','facility_id','document','page','edit'] as $key) unset($query[$key]);
        return $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
