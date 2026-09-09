<?php

declare(strict_types=1);

namespace App\Core;

final class ConsoleSearchIndex
{
    private const SETTING = 'console_search_index';

    public function __construct(
        private readonly CmsRepository $cms,
        private readonly ManifestRegistry $themes,
        private readonly ManifestRegistry $plugins,
        private readonly ManifestRegistry $addons,
        private readonly PackageManager $packages,
    ) {}

    public function overview(): array
    {
        return $this->ensure();
    }

    public function rebuild(int $userId = 0): array
    {
        $items = $this->baseItems();
        $states = (array) $this->cms->setting('extension_states', []);

        foreach ($this->themes->all() as $manifest) {
            $items[] = $this->item(
                (string) ($manifest['name'] ?? $manifest['slug']),
                (string) ($manifest['description'] ?? 'Installed presentation theme.'),
                '/appearance/themes?configure=' . rawurlencode((string) $manifest['slug']),
                'Themes',
                'panels-top-left',
                'appearance.manage',
                ['theme', 'presentation', 'configure', (string) ($manifest['author'] ?? '')]
            );
        }

        foreach ($this->plugins->all() as $manifest) {
            $url = (string) ($manifest['config_url'] ?? ($manifest['slug'] === 'forms' ? '/forms/submissions' : '/system/extensions?tab=plugins'));
            $items[] = $this->item(
                (string) ($manifest['name'] ?? $manifest['slug']),
                (string) ($manifest['description'] ?? 'Installed SenseCMS plugin.'),
                $url,
                'Plugins',
                (string) ($manifest['icon'] ?? 'plug'),
                $manifest['slug'] === 'forms' ? 'forms.view' : 'extensions.manage',
                ['plugin', 'extension', (string) ($manifest['author'] ?? '')]
            );
        }

        $registeredAddons = $this->addons->all();
        foreach (ExtensionCatalog::addons($states) as $addon) {
            $manifest = (array) ($registeredAddons[$addon['slug']] ?? []);
            $items[] = $this->item(
                (string) $addon['name'],
                (string) $addon['description'],
                (string) ($addon['config_url'] ?? '/system/extensions?tab=addons'),
                'Add-ons',
                (string) ($addon['icon'] ?? $manifest['icon'] ?? 'blocks'),
                $this->permissionForUrl((string) ($addon['config_url'] ?? '/system/extensions')),
                ['addon', 'extension', (string) ($addon['group'] ?? ''), (string) ($addon['publisher'] ?? '')]
            );
        }

        $unique = [];
        foreach ($items as $item) $unique[$item['url'] . '|' . $item['title']] = $item;
        $items = array_values($unique);
        usort($items, static fn(array $a, array $b): int => [$a['section'], $a['title']] <=> [$b['section'], $b['title']]);

        $index = [
            'schema' => 2,
            'fingerprint' => $this->fingerprint(),
            'built_at' => date(DATE_ATOM),
            'items' => $items,
            'count' => count($items),
            'sections' => count(array_unique(array_column($items, 'section'))),
        ];
        $this->cms->saveSetting(self::SETTING, $index);
        if ($userId > 0) $this->cms->recordActivity($userId, 'console.search-index.rebuilt', 'system', ['items' => count($items)]);
        return $index;
    }

    public function search(string $query, array $permissions, int $limit = 10): array
    {
        $index = $this->ensure();
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? ''));
        if ($query === '') return [];
        $owner = in_array('system.owner', $permissions, true);
        $terms = array_values(array_filter(explode(' ', $query)));
        $matches = [];

        foreach ($index['items'] as $item) {
            if (!$owner && $item['permission'] !== '' && !in_array($item['permission'], $permissions, true)) continue;
            $haystack = mb_strtolower(implode(' ', [$item['title'], $item['description'], $item['section'], implode(' ', $item['keywords'])]));
            $score = 0;
            foreach ($terms as $term) {
                if (!str_contains($haystack, $term)) { $score = 0; break; }
                $score += str_starts_with(mb_strtolower($item['title']), $term) ? 12 : (str_contains(mb_strtolower($item['title']), $term) ? 7 : 2);
            }
            if ($score > 0) $matches[] = $item + ['score' => $score];
        }

        usort($matches, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) $a['title'], (string) $b['title']));
        return array_slice($matches, 0, max(1, min(20, $limit)));
    }

    private function ensure(): array
    {
        $stored = $this->cms->setting(self::SETTING, []);
        if (!is_array($stored) || ($stored['schema'] ?? 0) !== 2 || ($stored['fingerprint'] ?? '') !== $this->fingerprint() || !is_array($stored['items'] ?? null)) return $this->rebuild();
        return $stored;
    }

    private function fingerprint(): string
    {
        $packages = array_map(static fn(array $row): array => [
            'type' => $row['type'] ?? '',
            'slug' => $row['slug'] ?? '',
            'version' => $row['version'] ?? '',
            'active' => (bool) ($row['active'] ?? false),
        ], $this->packages->packages());
        $source = [
            'themes' => array_keys($this->themes->all()),
            'plugins' => array_keys($this->plugins->all()),
            'addons' => array_keys($this->addons->all()),
            'states' => (array) $this->cms->setting('extension_states', []),
            'packages' => $packages,
        ];
        return hash('sha256', json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function baseItems(): array
    {
        $definitions = [
            ['Overview','Operational summary, shortcuts and current platform state.','/dashboard','Workspace','layout-dashboard','console.access',['dashboard','home']],
            ['My profile','Display name, profile photo and personal identity.','/profile','Workspace','user-round','console.access',['avatar','account']],
            ['My settings','Personal settings, e-mail address and password security.','/settings','Workspace','settings-2','console.access',['account','password']],
            ['Editorial workflow','Review, approve and publish accountable multilingual content.','/content/workflow','Content','git-pull-request-arrow','content.workflow.view',['approval','publishing','review']],
            ['Page Builder','Compose pages from portable multilingual sections and modules.','/content/builder','Content','panels-top-left','content.pages.edit',['sections','modules','builder']],
            ['Media Library','Search, organize, tag and safely manage reusable files.','/content/media','Content','images','content.media.manage',['images','video','files','folders']],
            ['Pages','Manage page status, hierarchy, translations and trash.','/content/pages','Content','files','content.pages.view',['page','archive','trash']],
            ['Create page','Create a new multilingual page.','/content/pages/new','Content','file-plus-2','content.pages.edit',['new page','add page']],
            ['Facilities','Manage facility routes, localized identity and geolocation.','/content/facilities','Content','school','facilities.view',['school','city','location']],
            ['Posts','Manage multilingual news and school stories.','/content/posts','Content','newspaper','content.posts.view',['news','articles','stories']],
            ['Create post','Create a new multilingual post.','/content/posts/new','Content','square-pen','content.posts.edit',['new post','article']],
            ['Categories','Organize posts in multilingual taxonomies.','/content/categories','Content','tags','content.navigation.manage',['taxonomy','tags']],
            ['Navigation','Configure website menus and localized labels.','/content/navigation','Content','menu','content.navigation.manage',['menu','links']],
            ['SEO','Manage global, page and post search, social and structured metadata.','/seo','Content','search-check','content.seo.manage',['metadata','open graph','twitter','schema','sitemap','robots']],
            ['Live chat','Handle visitor conversations and operator assignments.','/conversations','Engagement','messages-square','chat.view',['conversation','support']],
            ['Live chat configuration','Configure teams, availability, sounds and appearance.','/conversations/configuration','Engagement','message-square-cog','chat.manage',['chat settings','routing']],
            ['Form inbox','Review and export website form submissions.','/forms/submissions','Engagement','inbox','forms.view',['forms','messages','enquiries']],
            ['Professional Surveys','Build surveys, publish campaigns and analyze responses.','/surveys','Engagement','clipboard-list','surveys.view',['nps','csat','responses']],
            ['AI & Live Support','Monitor assistant providers and human handover readiness.','/ai','Engagement','bot-message-square','ai.manage',['assistant','provider','support']],
            ['Appearance','Manage shared brand assets, colors and typography.','/appearance','Experience','palette','appearance.manage',['logo','favicon','colors','fonts']],
            ['Themes','Configure, preview and activate presentation themes.','/appearance/themes','Experience','panels-top-left','appearance.manage',['theme','design']],
            ['SenseCMS Marketplace','Discover verified themes, plugins and add-ons.','/marketplace','Experience','store','extensions.manage',['catalog','packages']],
            ['Access control','Manage users, teams, roles, permissions and audit history.','/system/access','System','shield-user','users.view',['roles','users','teams','permissions']],
            ['Extensions','Manage installed modules, plugins, add-ons and packages.','/system/extensions','System','blocks','extensions.manage',['addons','plugins','modules']],
            ['Search index','Inspect and rebuild the dynamic administration search index.','/system/search-index','System','scan-search','system.manage',['quick search','indexing','ctrl k']],
            ['Cache','Configure and clear application cache safely.','/system/cache','System','database-zap','system.manage',['performance','redis','file cache']],
            ['E-mail','Configure secure mail delivery, branded appearance and transactional templates.','/system/email','System','mail-check','system.manage',['smtp','welcome','password reset','templates']],
            ['Sounds','Configure administrator action and notification sounds.','/system/sounds','System','volume-2','system.manage',['audio','notifications']],
            ['CAPTCHA','Configure administrator sign-in protection.','/system/captcha','System','shield-check','system.manage',['security','login']],
            ['Languages','Manage installed and active platform languages.','/system/languages','System','languages','system.manage',['locales','translations']],
            ['License','Manage the encrypted SenseCMS installation entitlement.','/license','System','badge-check','system.manage',['activation','entitlement','chivale']],
        ];
        return array_map(fn(array $row): array => $this->item(...$row), $definitions);
    }

    private function item(string $title, string $description, string $url, string $section, string $icon, string $permission, array $keywords): array
    {
        return compact('title', 'description', 'url', 'section', 'icon', 'permission', 'keywords');
    }

    private function permissionForUrl(string $url): string
    {
        return match (true) {
            str_starts_with($url, '/conversations') => 'chat.view',
            str_starts_with($url, '/surveys') => 'surveys.view',
            str_starts_with($url, '/content/facilities') => 'facilities.view',
            str_starts_with($url, '/forms') => 'forms.view',
            default => 'extensions.manage',
        };
    }
}
