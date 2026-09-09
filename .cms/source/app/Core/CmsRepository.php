<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class CmsRepository
{
    public function __construct(private readonly PDO $db, private readonly EventBus $events, private readonly ?Runtime $runtime = null) {}

    public function setting(string $key, mixed $fallback = null): mixed
    {
        if ($key === 'active_theme' && $this->runtime !== null) {
            $active = (new Packages\ThemeManager($this->runtime))->active();
            if ($active !== null) return $active['slug'];
        }
        if ($key === 'theme_settings' && $this->runtime !== null && ($active = (new Packages\ThemeManager($this->runtime))->active()) !== null) {
            $configs = (array) $this->setting('theme_configurations', []);
            if (isset($configs[$active['slug']])) return $configs[$active['slug']];
            $legacy = $this->db->query("SELECT value FROM settings WHERE `key`='active_theme'")->fetchColumn();
            if ((json_decode((string) $legacy, true) ?? $legacy) !== $active['slug']) return [];
        }
        $statement = $this->db->prepare('SELECT value FROM settings WHERE `key` = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? $fallback : json_decode($value, true) ?? $value;
    }

    public function saveSetting(string $key, mixed $value): void
    {
        $statement = $this->db->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        $statement->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** Serialize draft/publish/discard and commit their audit with the configuration. */
    public function withThemeConfigurationLock(callable $operation): mixed
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec("INSERT INTO settings (`key`, value) VALUES ('theme_drafts', '{}') ON DUPLICATE KEY UPDATE `key` = VALUES(`key`)");
            $this->db->query("SELECT value FROM settings WHERE `key` = 'theme_drafts' FOR UPDATE")->fetchColumn();
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function recordActivity(int $userId,string $event,string $type,array $context=[]):void
    {
        $this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,NULL,?,NOW())')->execute([$userId?:null,$event,$type,json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    public function languages(): array
    {
        return $this->normalizeLanguages($this->db->query('SELECT * FROM languages WHERE enabled = 1 ORDER BY is_default DESC, sort_order, id')->fetchAll());
    }

    public function allLanguages(): array
    {
        return $this->normalizeLanguages($this->db->query('SELECT * FROM languages ORDER BY is_default DESC, sort_order, id')->fetchAll());
    }

    public function language(string $locale): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM languages WHERE locale=? LIMIT 1');
        $statement->execute([$locale]);
        $row = $statement->fetch();
        return $row ? $this->normalizeLanguages([$row])[0] : null;
    }

    public function defaultLocale(string $fallback = 'en'): string
    {
        $locale = $this->db->query('SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY sort_order,id LIMIT 1')->fetchColumn();
        if (is_string($locale) && $locale !== '') return $locale;
        $locale = $this->db->query('SELECT locale FROM languages WHERE enabled=1 ORDER BY sort_order,id LIMIT 1')->fetchColumn();
        return is_string($locale) && $locale !== '' ? $locale : $fallback;
    }

    public function saveLanguage(array $input): void
    {
        $existing = $this->language((string) $input['locale']);
        $makeDefault = !empty($input['is_default']);
        if (!empty($existing['is_default']) && empty($input['enabled'])) throw new \RuntimeException('The default language cannot be disabled. Choose another default language first.');
        if (!empty($existing['is_default'])) $makeDefault = true;
        if ($makeDefault) $input['enabled'] = 1;
        $input['is_default'] = $makeDefault ? 1 : 0;
        $this->db->beginTransaction();
        try {
            if ($makeDefault) $this->db->exec('UPDATE languages SET is_default=0');
            $statement = $this->db->prepare('INSERT INTO languages (locale,name,native_name,flag,enabled,is_default,sort_order) VALUES (:locale,:name,:native_name,:flag,:enabled,:is_default,:sort_order) ON DUPLICATE KEY UPDATE name=VALUES(name), native_name=VALUES(native_name), flag=VALUES(flag), enabled=VALUES(enabled), is_default=VALUES(is_default), sort_order=VALUES(sort_order)');
            $statement->execute($input);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function setLanguageEnabled(string $locale, bool $enabled): void
    {
        $language = $this->language($locale);
        if (!$language) throw new \RuntimeException('The language was not found.');
        if (!empty($language['is_default']) && !$enabled) throw new \RuntimeException('The default language cannot be disabled. Choose another default language first.');
        $statement = $this->db->prepare('UPDATE languages SET enabled=? WHERE locale=?');
        $statement->execute([$enabled ? 1 : 0, $locale]);
    }

    public function setDefaultLanguage(string $locale): void
    {
        if (!$this->language($locale)) throw new \RuntimeException('The language was not found.');
        $this->db->beginTransaction();
        try {
            $this->db->exec('UPDATE languages SET is_default=0');
            $statement = $this->db->prepare('UPDATE languages SET enabled=1,is_default=1 WHERE locale=?');
            $statement->execute([$locale]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function normalizeLanguages(array $rows): array
    {
        $flags = ['en'=>'/sensecms/images/flags/gb.svg','de'=>'/sensecms/images/flags/de.svg','zh'=>'/sensecms/images/flags/cn.svg','pl'=>'/sensecms/images/flags/pl.svg','km'=>'/sensecms/images/flags/kh.svg'];
        foreach ($rows as &$row) if (trim((string) ($row['flag'] ?? '')) === '') $row['flag'] = $flags[(string) ($row['locale'] ?? '')] ?? '/sensecms/images/flags/gb.svg';
        unset($row);
        return $rows;
    }

    public function page(string $slug, string $locale, string $fallbackLocale, ?int $facilityId = null): ?array
    {
        $statement = $this->db->prepare("SELECT p.*, COALESCE(t.title, ft.title) title, COALESCE(t.slug, ft.slug) localized_slug, COALESCE(t.excerpt, ft.excerpt) excerpt, COALESCE(t.seo_title, ft.seo_title) seo_title, COALESCE(t.seo_description, ft.seo_description) seo_description
            FROM pages p
            LEFT JOIN page_translations t ON t.page_id = p.id AND t.locale = :locale
            LEFT JOIN page_translations ft ON ft.page_id = p.id AND ft.locale = :fallback
            WHERE (t.slug = :translated_slug OR (t.slug IS NULL AND ft.slug = :fallback_slug)) AND (p.status = 'published' OR (p.status = 'scheduled' AND p.published_at <= NOW())) AND p.visibility = 'public'" . ($facilityId ? ' AND p.facility_id = :facility' : '') . " LIMIT 1");
        $parameters=['translated_slug' => $slug, 'fallback_slug' => $slug, 'locale' => $locale, 'fallback' => $fallbackLocale];if($facilityId)$parameters['facility']=$facilityId;$statement->execute($parameters);
        $page = $statement->fetch();
        if (!$page) return null;
        $page['blocks'] = $this->blocks((int) $page['id'], $locale, $fallbackLocale);
        return $page;
    }

    /** Reserve unpublished paths too: withdrawing content must not reveal theme fallback content. */
    public function pageAtPath(string $path): ?array
    {
        $statement = $this->db->prepare('SELECT id,facility_id FROM pages WHERE public_path=? LIMIT 1');
        $statement->execute([$path]);
        return $statement->fetch() ?: null;
    }

    public function publicPagePaths(): array
    {
        return $this->db->query('SELECT public_path,id FROM pages WHERE public_path IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function pageById(int $id,string $locale,string $fallbackLocale,int $facilityId):?array
    {
        $statement=$this->db->prepare("SELECT p.*,COALESCE(t.title,ft.title) title,COALESCE(t.slug,ft.slug) localized_slug,COALESCE(t.excerpt,ft.excerpt) excerpt,COALESCE(t.seo_title,ft.seo_title) seo_title,COALESCE(t.seo_description,ft.seo_description) seo_description FROM pages p LEFT JOIN page_translations t ON t.page_id=p.id AND t.locale=? LEFT JOIN page_translations ft ON ft.page_id=p.id AND ft.locale=? WHERE p.id=? AND p.facility_id=? AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=NOW())) AND p.visibility='public' LIMIT 1");$statement->execute([$locale,$fallbackLocale,$id,$facilityId]);$page=$statement->fetch();if(!$page)return null;$page['blocks']=$this->blocks((int)$page['id'],$locale,$fallbackLocale);return$page;
    }

    public function blocks(int $pageId, string $locale, string $fallbackLocale): array
    {
        $statement = $this->db->prepare("SELECT b.id,b.uid,b.page_id,COALESCE(g.type,b.type) type,COALESCE(g.source_theme,b.source_theme) source_theme,b.global_section_id,COALESCE(g.settings,b.settings) settings,b.visible,b.visible_from,b.visible_until,b.sort_order,b.created_at,b.updated_at,COALESCE(gt.data,gft.data,t.data,ft.data,'{}') data
            FROM content_blocks b
            LEFT JOIN global_sections g ON g.id=b.global_section_id AND g.active=1 AND g.archived_at IS NULL
            LEFT JOIN content_block_translations t ON t.block_id = b.id AND t.locale = :locale
            LEFT JOIN content_block_translations ft ON ft.block_id = b.id AND ft.locale = :fallback
            LEFT JOIN global_section_translations gt ON gt.global_section_id=g.id AND gt.locale=:global_locale
            LEFT JOIN global_section_translations gft ON gft.global_section_id=g.id AND gft.locale=:global_fallback
            WHERE b.page_id = :page AND b.visible = 1 AND b.archived_at IS NULL AND (b.visible_from IS NULL OR b.visible_from<=NOW()) AND (b.visible_until IS NULL OR b.visible_until>NOW()) ORDER BY b.sort_order, b.id");
        $statement->execute(['page' => $pageId, 'locale' => $locale, 'fallback' => $fallbackLocale, 'global_locale'=>$locale, 'global_fallback'=>$fallbackLocale]);
        return array_map(static function(array $block):array{$shared=json_decode((string)($block['settings']??''),true);return $block+['payload'=>json_decode($block['data'],true,512,JSON_THROW_ON_ERROR),'shared'=>is_array($shared)?$shared:[]];},$statement->fetchAll());
    }

    public function menu(string $location, string $locale, string $fallbackLocale): array
    {
        $statement = $this->db->prepare("SELECT mi.*, COALESCE(t.label, ft.label, mi.label) label, COALESCE(t.url, ft.url, mi.url) url
            FROM menus m JOIN menu_items mi ON mi.menu_id = m.id
            LEFT JOIN menu_item_translations t ON t.menu_item_id = mi.id AND t.locale = :locale
            LEFT JOIN menu_item_translations ft ON ft.menu_item_id = mi.id AND ft.locale = :fallback
            WHERE m.location = :location AND mi.visible = 1 AND mi.archived_at IS NULL ORDER BY mi.sort_order, mi.id");
        $statement->execute(['location' => $location, 'locale' => $locale, 'fallback' => $fallbackLocale]);
        return $statement->fetchAll();
    }

    public function publicNavigation(string $locale, string $fallbackLocale): array
    {
        $query = $this->db->prepare("SELECT m.location,mi.id,mi.target,COALESCE(t.label,ft.label,mi.label) label,COALESCE(t.url,ft.url,mi.url) url
            FROM menus m LEFT JOIN menu_items mi ON mi.menu_id=m.id AND mi.visible=1 AND mi.archived_at IS NULL
            LEFT JOIN menu_item_translations t ON t.menu_item_id=mi.id AND t.locale=?
            LEFT JOIN menu_item_translations ft ON ft.menu_item_id=mi.id AND ft.locale=?
            WHERE m.location IN ('primary','footer','footer-connect') ORDER BY mi.sort_order,mi.id");
        $query->execute([$locale, $fallbackLocale]); $menus = [];
        foreach ($query->fetchAll() as $item) {
            $location = $item['location']; $menus[$location] ??= [];
            if ($item['id'] !== null) $menus[$location][] = $item;
        }
        return $menus;
    }

    public function dashboard(?array $facilityIds = null): array
    {
        $pageCounts = $this->statusCounts('pages', null, $facilityIds);
        $postCounts = $this->statusCounts('posts', null, $facilityIds);
        $facilityWhere = ["status = 'active'"]; $facilityParameters = [];
        $this->facilityScope($facilityWhere, $facilityParameters, 'id', $facilityIds);
        $facilities = $this->db->prepare('SELECT COUNT(*) FROM facilities WHERE ' . implode(' AND ', $facilityWhere));
        $facilities->execute($facilityParameters);
        return [
            'pages' => $pageCounts,
            'posts' => $postCounts,
            'content_total' => array_sum(array_intersect_key($pageCounts, array_flip(['draft','published','private','scheduled'])))
                + array_sum(array_intersect_key($postCounts, array_flip(['draft','published','scheduled']))),
            'published_total' => $pageCounts['published'] + $postCounts['published'],
            'scheduled_total' => $pageCounts['scheduled'] + $postCounts['scheduled'],
            'draft_total' => $pageCounts['draft'] + $postCounts['draft'],
            'facilities' => (int) $facilities->fetchColumn(),
            'languages' => (int) $this->db->query('SELECT COUNT(*) FROM languages WHERE enabled = 1')->fetchColumn(),
            'themes' => (int) $this->db->query('SELECT COUNT(*) FROM installed_themes WHERE active = 1')->fetchColumn(),
            'plugins' => (int) $this->db->query("SELECT COUNT(*) FROM (SELECT 'plugin' type,slug FROM installed_plugins WHERE active=1 UNION SELECT type,slug FROM extension_packages WHERE active=1 AND type IN ('plugin','addon')) active_extensions")->fetchColumn(),
            'recent_content' => $this->recentDashboardContent($facilityIds),
            'scheduled_content' => $this->scheduledDashboardContent($facilityIds),
            'forms' => $this->formSubmissionCounts($facilityIds),
        ];
    }

    private function recentDashboardContent(?array $facilityIds): array
    {
        $scope = []; $parameters = [];
        $this->facilityScope($scope, $parameters, 'x.facility_id', $facilityIds);
        $scopeSql = $scope ? ' AND ' . implode(' AND ', $scope) : '';
        $locale = '(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1)';
        $page = "SELECT 'page' entity_type,x.id,x.status,x.workflow_state,x.updated_at,COALESCE(t.title,CONCAT('Page #',x.id)) title,COALESCE(ct.name,c.facility_slug,'Shared') facility_name FROM pages x LEFT JOIN page_translations t ON t.page_id=x.id AND t.locale={$locale} LEFT JOIN facilities c ON c.id=x.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale={$locale} WHERE x.status<>'archived'{$scopeSql}";
        $post = "SELECT 'post' entity_type,x.id,x.status,x.workflow_state,x.updated_at,COALESCE(t.title,CONCAT('Post #',x.id)) title,COALESCE(ct.name,c.facility_slug,'Shared') facility_name FROM posts x LEFT JOIN post_translations t ON t.post_id=x.id AND t.locale={$locale} LEFT JOIN facilities c ON c.id=x.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale={$locale} WHERE x.status<>'archived'{$scopeSql}";
        $statement = $this->db->prepare("SELECT * FROM ({$page} UNION ALL {$post}) content ORDER BY updated_at DESC LIMIT 6");
        $statement->execute(array_merge($parameters, $parameters));
        return $statement->fetchAll();
    }

    private function scheduledDashboardContent(?array $facilityIds): array
    {
        $scope = []; $parameters = [];
        $this->facilityScope($scope, $parameters, 'x.facility_id', $facilityIds);
        $scopeSql = $scope ? ' AND ' . implode(' AND ', $scope) : '';
        $locale = '(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1)';
        $page = "SELECT 'page' entity_type,x.id,x.published_at,COALESCE(t.title,CONCAT('Page #',x.id)) title FROM pages x LEFT JOIN page_translations t ON t.page_id=x.id AND t.locale={$locale} WHERE x.status='scheduled' AND x.published_at>NOW(){$scopeSql}";
        $post = "SELECT 'post' entity_type,x.id,x.published_at,COALESCE(t.title,CONCAT('Post #',x.id)) title FROM posts x LEFT JOIN post_translations t ON t.post_id=x.id AND t.locale={$locale} WHERE x.status='scheduled' AND x.published_at>NOW(){$scopeSql}";
        $statement = $this->db->prepare("SELECT * FROM ({$page} UNION ALL {$post}) content ORDER BY published_at LIMIT 4");
        $statement->execute(array_merge($parameters, $parameters));
        return $statement->fetchAll();
    }

    public function activePluginSlugs(): array
    {
        return array_values(array_filter($this->db->query('SELECT slug FROM installed_plugins WHERE active=1 ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    public function installedPlugins(): array
    {
        return array_column($this->db->query('SELECT slug,version,active,installed_at FROM installed_plugins ORDER BY slug')->fetchAll(), null, 'slug');
    }

    public function installedThemes(): array
    {
        return array_column($this->db->query('SELECT slug,version,active,installed_at FROM installed_themes ORDER BY slug')->fetchAll(), null, 'slug');
    }

    public function setPluginActive(string $slug, string $version, bool $active): void
    {
        $statement = $this->db->prepare('INSERT INTO installed_plugins (slug,version,active,settings,installed_at) VALUES (?,?,?,JSON_OBJECT(),NOW()) ON DUPLICATE KEY UPDATE version=VALUES(version),active=VALUES(active)');
        $statement->execute([$slug, $version, $active ? 1 : 0]);
    }

    public function posts(string $query = '', string $status = 'all', int $page = 1, int $perPage = 12, ?int $facilityId = null, ?array $facilityIds = null): array
    {
        $where = []; $parameters = [];
        if ($facilityId) { $where[]='p.facility_id=?';$parameters[]=$facilityId; }
        elseif ($facilityIds !== null) { $this->facilityScope($where,$parameters,'p.facility_id',$facilityIds); }
        if (in_array($status, ['draft', 'published', 'scheduled', 'archived'], true)) { $where[] = 'p.status=?'; $parameters[] = $status; }
        if ($query !== '') { $where[] = '(t.title LIKE ? OR t.slug LIKE ? OR t.excerpt LIKE ?)'; $like = '%' . $query . '%'; array_push($parameters, $like, $like, $like); }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $count = $this->db->prepare("SELECT COUNT(*) FROM posts p LEFT JOIN post_translations t ON t.post_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) {$whereSql}");
        $count->execute($parameters); $total = (int) $count->fetchColumn(); $perPage = max(5, min(50, $perPage)); $pages = max(1, (int) ceil($total / $perPage)); $page = min(max(1, $page), $pages);
        $sql = "SELECT p.*,COALESCE(t.title,CONCAT('Post #',p.id)) title,COALESCE(t.slug,'') slug,c.slug category,COALESCE(ct.name,cp.facility_slug) facility_name,cp.city_slug,cp.facility_slug,(SELECT COUNT(*) FROM post_translations x WHERE x.post_id=p.id) translation_count FROM posts p LEFT JOIN post_translations t ON t.post_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) LEFT JOIN categories c ON c.id=p.category_id INNER JOIN facilities cp ON cp.id=p.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=cp.id AND ct.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) {$whereSql} ORDER BY COALESCE(p.published_at,p.updated_at) DESC LIMIT ? OFFSET ?";
        $statement = $this->db->prepare($sql); $index = 1; foreach ($parameters as $value) $statement->bindValue($index++, $value); $statement->bindValue($index++, $perPage, PDO::PARAM_INT); $statement->bindValue($index, ($page - 1) * $perPage, PDO::PARAM_INT); $statement->execute();
        return ['items'=>$statement->fetchAll(),'pagination'=>['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'total'=>$total],'counts'=>$this->statusCounts('posts',$facilityId,$facilityIds)];
    }

    public function postAdmin(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM posts WHERE id=? LIMIT 1');$statement->execute([$id]);$post=$statement->fetch();if(!$post)return null;
        $translations=$this->db->prepare('SELECT * FROM post_translations WHERE post_id=?');$translations->execute([$id]);$post['translations']=array_column($translations->fetchAll(),null,'locale');return$post;
    }

    public function categories(bool $includeArchived = true): array
    {
        $where=$includeArchived?'':'WHERE c.archived_at IS NULL';
        $rows=$this->db->query("SELECT c.*,COUNT(p.id) posts FROM categories c LEFT JOIN posts p ON p.category_id=c.id {$where} GROUP BY c.id ORDER BY c.archived_at IS NOT NULL,c.slug")->fetchAll();
        if(!$rows)return[];$ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$translations=$this->db->prepare("SELECT * FROM category_translations WHERE category_id IN ({$marks})");$translations->execute($ids);$localized=[];foreach($translations->fetchAll()as$row)$localized[(int)$row['category_id']][(string)$row['locale']]=$row;foreach($rows as&$row)$row['translations']=$localized[(int)$row['id']]??[];unset($row);return$rows;
    }

    public function saveCategory(int $id, string $slug, array $translations, int $userId): int
    {
        $this->db->beginTransaction();try{if($id){$statement=$this->db->prepare('UPDATE categories SET slug=?,archived_at=NULL,updated_at=NOW() WHERE id=?');$statement->execute([$slug,$id]);if(!$statement->rowCount()&&!$this->categoryExists($id))throw new \RuntimeException('The category was not found.');}else{$this->db->prepare('INSERT INTO categories (slug,created_at,updated_at) VALUES (?,NOW(),NOW())')->execute([$slug]);$id=(int)$this->db->lastInsertId();}$upsert=$this->db->prepare('INSERT INTO category_translations (category_id,locale,name,description) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description)');foreach($translations as$locale=>$value)$upsert->execute([$id,$locale,$value['name'],$value['description']]);$this->audit($userId,'category.saved','category',$id,['slug'=>$slug]);$this->db->commit();return$id;}catch(\Throwable$error){$this->db->rollBack();throw$error;}
    }

    public function setCategoryArchived(int $id, bool $archived, int $userId): bool
    {
        $statement=$this->db->prepare('UPDATE categories SET archived_at='.($archived?'NOW()':'NULL').',updated_at=NOW() WHERE id=?');$statement->execute([$id]);if(!$statement->rowCount())return false;$this->audit($userId,$archived?'category.archived':'category.restored','category',$id,[]);return true;
    }

    public function savePost(array $input, array $translations, int $userId): int
    {
        $id=(int)($input['id']??0);$publishedAt=$input['status']==='published'?($input['published_at']?:date('Y-m-d H:i:s')):($input['status']==='scheduled'?$input['published_at']:null);
        if(!empty($input['category_id'])&&!$this->activeCategoryExists((int)$input['category_id']))throw new \RuntimeException('Choose an active category or leave the post uncategorised.');
        $facilityId=(int)($input['facility_id']??0);if(!$facilityId)throw new \RuntimeException('Choose a facility for this post.');
        $workflow=in_array($input['status'],['published','scheduled'],true)?'approved':'draft';$this->db->beginTransaction();try{$post=['facility_id'=>$facilityId,'category_id'=>$input['category_id']?:null,'featured_media_id'=>$input['featured_media_id']?:null,'status'=>$input['status'],'published_at'=>$publishedAt,'assigned_user_id'=>(int)($input['assigned_user_id']??0)?:null,'editorial_note'=>mb_substr(trim((string)($input['editorial_note']??'')),0,1000)?:null,'workflow_state'=>$workflow];if($id){$statement=$this->db->prepare('UPDATE posts SET facility_id=:facility_id,category_id=:category_id,featured_media_id=:featured_media_id,status=:status,published_at=:published_at,assigned_user_id=:assigned_user_id,editorial_note=:editorial_note,workflow_state=:workflow_state,updated_at=NOW() WHERE id=:id');$statement->execute($post+['id'=>$id]);if(!$statement->rowCount()&&!$this->postAdmin($id))throw new \RuntimeException('The post was not found.');}else{$this->db->prepare('INSERT INTO posts (author_id,facility_id,owner_user_id,assigned_user_id,workflow_state,editorial_note,category_id,featured_media_id,status,published_at,created_at,updated_at) VALUES (:author_id,:facility_id,:owner_user_id,:assigned_user_id,:workflow_state,:editorial_note,:category_id,:featured_media_id,:status,:published_at,NOW(),NOW())')->execute($post+['author_id'=>$input['author_id'],'owner_user_id'=>$userId]);$id=(int)$this->db->lastInsertId();}$upsert=$this->db->prepare('INSERT INTO post_translations (post_id,facility_id,locale,title,slug,excerpt,content,seo_title,seo_description) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE facility_id=VALUES(facility_id),title=VALUES(title),slug=VALUES(slug),excerpt=VALUES(excerpt),content=VALUES(content),seo_title=VALUES(seo_title),seo_description=VALUES(seo_description)');foreach($translations as$locale=>$value)$upsert->execute([$id,$facilityId,$locale,$value['title'],$value['slug'],$value['excerpt'],$value['content'],$value['seo_title'],$value['seo_description']]);$this->audit($userId,'post.saved','post',$id,['status'=>$input['status'],'workflow_state'=>$workflow,'facility_id'=>$facilityId]);$this->db->commit();$this->events->dispatch('post.updated',['post_id'=>$id]);return$id;}catch(\Throwable$error){$this->db->rollBack();throw$error;}
    }

    public function setPostStatus(int $id, string $status, int $userId): bool
    {
        if(!in_array($status,['draft','archived'],true))return false;$statement=$this->db->prepare("UPDATE posts SET status=?,workflow_state='draft',assigned_user_id=NULL,review_requested_at=NULL,reviewed_at=NULL,reviewed_by=NULL,published_at=NULL,updated_at=NOW() WHERE id=?");$statement->execute([$status,$id]);if(!$statement->rowCount())return false;$this->audit($userId,$status==='archived'?'post.archived':'post.restored','post',$id,[]);return true;
    }

    public function duplicatePost(int $id, int $userId): int
    {
        $post=$this->postAdmin($id);if(!$post)throw new \RuntimeException('The post was not found.');$translations=[];foreach($post['translations']as$locale=>$value){$translations[$locale]=$value;$translations[$locale]['title']=mb_substr($value['title'].' — Copy',0,255);$translations[$locale]['slug']=$this->uniqueTranslationSlug('post_translations','post_id',(int)$post['facility_id'],$locale,$value['slug'].'-copy');}$input=['id'=>0,'author_id'=>$userId,'facility_id'=>$post['facility_id'],'category_id'=>$post['category_id'],'featured_media_id'=>$post['featured_media_id'],'status'=>'draft','published_at'=>null];return$this->savePost($input,$translations,$userId);
    }

    public function navigation(): array
    {
        $rows=$this->db->query("SELECT m.location,m.name,mi.id,mi.type,mi.page_id,mi.label,mi.url,mi.target,mi.visible,mi.sort_order FROM menus m LEFT JOIN menu_items mi ON mi.menu_id=m.id AND mi.archived_at IS NULL ORDER BY m.location,mi.sort_order,mi.id")->fetchAll();$ids=array_values(array_filter(array_map('intval',array_column($rows,'id'))));if(!$ids)return$rows;$marks=implode(',',array_fill(0,count($ids),'?'));$statement=$this->db->prepare("SELECT * FROM menu_item_translations WHERE menu_item_id IN ({$marks})");$statement->execute($ids);$localized=[];foreach($statement->fetchAll()as$row)$localized[(int)$row['menu_item_id']][(string)$row['locale']]=$row;foreach($rows as&$row)$row['translations']=$localized[(int)($row['id']??0)]??[];unset($row);return$rows;
    }

    public function saveNavigation(string $location, string $name, array $items, int $userId): void
    {
        $this->db->beginTransaction();try{$statement=$this->db->prepare('INSERT INTO menus (name,location,created_at,updated_at) VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()');$statement->execute([$name,$location]);$menu=$this->db->prepare('SELECT id FROM menus WHERE location=?');$menu->execute([$location]);$menuId=(int)$menu->fetchColumn();$this->db->prepare('UPDATE menu_items SET archived_at=NOW() WHERE menu_id=? AND archived_at IS NULL')->execute([$menuId]);$insert=$this->db->prepare("INSERT INTO menu_items (menu_id,type,label,url,target,visible,sort_order,archived_at) VALUES (?,'url',?,?,?, ?,?,NULL)");$update=$this->db->prepare("UPDATE menu_items SET type='url',label=?,url=?,target=?,visible=?,sort_order=?,archived_at=NULL WHERE id=? AND menu_id=?");$translate=$this->db->prepare('INSERT INTO menu_item_translations (menu_item_id,locale,label,url) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),url=VALUES(url)');foreach($items as$order=>$item){$fallback=reset($item['translations'])?:['label'=>'','url'=>''];if(trim((string)$fallback['label'])===''||trim((string)$fallback['url'])==='')continue;$id=(int)($item['id']??0);$values=[$fallback['label'],$fallback['url'],$item['target']==='_blank'?'_blank':'_self',!empty($item['visible'])?1:0,$order];if($id){$update->execute([...$values,$id,$menuId]);if(!$update->rowCount())$id=0;}if(!$id){$insert->execute([$menuId,...$values]);$id=(int)$this->db->lastInsertId();}foreach($item['translations']as$locale=>$value)$translate->execute([$id,$locale,$value['label'],$value['url']]);}$this->audit($userId,'navigation.saved','menu',$menuId,['location'=>$location,'items'=>count($items)]);$this->db->commit();}catch(\Throwable$error){$this->db->rollBack();throw$error;}
    }

    public function latestPosts(string $locale, string $fallbackLocale, int $limit = 3, ?int $facilityId = null): array
    {
        $statement = $this->db->prepare("SELECT p.*, COALESCE(t.title, ft.title) title, COALESCE(t.slug, ft.slug) slug, COALESCE(t.excerpt, ft.excerpt) excerpt, m.path image FROM posts p LEFT JOIN post_translations t ON t.post_id=p.id AND t.locale=:locale LEFT JOIN post_translations ft ON ft.post_id=p.id AND ft.locale=:fallback LEFT JOIN media m ON m.id=p.featured_media_id WHERE (p.status='published' OR (p.status='scheduled' AND p.published_at<=NOW()))".($facilityId?' AND p.facility_id=:facility':'')." ORDER BY p.published_at DESC LIMIT :limit");
        $statement->bindValue('locale', $locale); $statement->bindValue('fallback', $fallbackLocale);if($facilityId)$statement->bindValue('facility',$facilityId,PDO::PARAM_INT); $statement->bindValue('limit', $limit, PDO::PARAM_INT); $statement->execute(); return $statement->fetchAll();
    }

    public function publicPost(string $slug, string $locale, string $fallbackLocale, int $facilityId): ?array
    {
        $statement=$this->db->prepare("SELECT p.*,COALESCE(t.title,ft.title) title,COALESCE(t.slug,ft.slug) localized_slug,COALESCE(t.excerpt,ft.excerpt) excerpt,COALESCE(t.content,ft.content) content,COALESCE(t.seo_title,ft.seo_title) seo_title,COALESCE(t.seo_description,ft.seo_description) seo_description,m.path image,m.mime_type image_type,m.width image_width,m.height image_height,m.alt_text image_alt,u.name author_name FROM posts p LEFT JOIN post_translations t ON t.post_id=p.id AND t.locale=:locale LEFT JOIN post_translations ft ON ft.post_id=p.id AND ft.locale=:fallback LEFT JOIN media m ON m.id=p.featured_media_id LEFT JOIN users u ON u.id=p.author_id WHERE (t.slug=:translated_slug OR (t.slug IS NULL AND ft.slug=:fallback_slug)) AND p.facility_id=:facility AND (p.status='published' OR (p.status='scheduled' AND p.published_at<=NOW())) LIMIT 1");
        $statement->execute(['locale'=>$locale,'fallback'=>$fallbackLocale,'translated_slug'=>$slug,'fallback_slug'=>$slug,'facility'=>$facilityId]);$post=$statement->fetch();return$post?:null;
    }

    public function postSlugs(int $id): array
    {
        $statement=$this->db->prepare('SELECT locale,slug FROM post_translations WHERE post_id=?');$statement->execute([$id]);return$statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function publicSearch(string $locale, string $fallbackLocale, string $query, int $limit = 12, ?array $facility = null): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: []));
        $tokens = array_slice(array_unique($tokens), 0, 6);
        if ($tokens === []) return [];
        $brandOnly = count($tokens) === 1 && $tokens[0] === 'sensecms';

        $conditions = [];
        $parameters = [$locale, $fallbackLocale];
        foreach ($tokens as $token) {
            $pattern = '%' . strtr($token, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            $conditions[] = "(LOWER(COALESCE(t.title, ft.title, '')) LIKE ? ESCAPE '=' OR LOWER(COALESCE(t.excerpt, ft.excerpt, '')) LIKE ? ESCAPE '=' OR LOWER(COALESCE(CAST(bt.data AS CHAR), CAST(fbt.data AS CHAR), '')) LIKE ? ESCAPE '=')";
            array_push($parameters, $pattern, $pattern, $pattern);
        }

        $sql = "SELECT p.id, COALESCE(t.title, ft.title) page_title, COALESCE(t.slug, ft.slug) slug,
                       COALESCE(t.excerpt, ft.excerpt, '') page_excerpt, b.id block_id, b.type block_type,
                       COALESCE(bt.data, fbt.data) block_data
                FROM pages p
                LEFT JOIN page_translations t ON t.page_id = p.id AND t.locale = ?
                LEFT JOIN page_translations ft ON ft.page_id = p.id AND ft.locale = ?
                LEFT JOIN content_blocks b ON b.page_id = p.id AND b.visible = 1 AND b.archived_at IS NULL
                LEFT JOIN content_block_translations bt ON bt.block_id = b.id AND bt.locale = ?
                LEFT JOIN content_block_translations fbt ON fbt.block_id = b.id AND fbt.locale = ?
                WHERE (p.status = 'published' OR (p.status = 'scheduled' AND p.published_at <= NOW())) AND p.visibility = 'public'
                  AND COALESCE(t.id, ft.id) IS NOT NULL".($facility?' AND p.facility_id = ?':'')." AND " . implode(' AND ', $conditions) . "
                ORDER BY p.sort_order, p.id, b.sort_order, b.id LIMIT ?";
        array_splice($parameters, 2, 0, [$locale, $fallbackLocale]);if($facility)array_splice($parameters,4,0,[(int)$facility['id']]);
        $parameters[] = max(1, min(50, $limit * 4));
        $statement = $this->db->prepare($sql);
        foreach ($parameters as $index => $value) $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->execute();

        $results = [];
        foreach ($statement->fetchAll() as $row) {
            $payload = json_decode((string) ($row['block_data'] ?? ''), true);
            $blockText = is_array($payload) ? $this->searchableText($payload) : '';
            $pageText = trim((string) $row['page_title'] . ' ' . (string) $row['page_excerpt']);
            $blockMatches = !$brandOnly && $blockText !== '' && $this->matchesSearch($blockText, $tokens);
            $pageMatches = $this->matchesSearch($pageText, $tokens);
            $anchor = match ((string) ($row['block_type'] ?? '')) {
                'story', 'split' => 'community', 'programs' => 'programs', 'statistics' => 'highlights', 'gallery' => 'moments', 'motion' => 'motion', 'admissions' => 'admissions', 'news' => 'stories', 'cta' => 'contact', default => '',
            };
            $base=$facility&&!$facility['is_primary']?'/'.rawurlencode($locale).'/facilities/'.rawurlencode((string)$facility['city_slug']).'/'.rawurlencode((string)$facility['facility_slug']):'/'.rawurlencode($locale);$isHome=$facility&&(int)($facility['homepage_page_id']??0)===(int)$row['id'];$url=$base.($isHome?'':'/'.rawurlencode((string)$row['slug'])).($blockMatches&&$anchor!==''?'#'.$anchor:'');
            $title = $blockMatches ? $this->searchTitle($payload, (string) $row['page_title']) : (string) $row['page_title'];
            $excerpt = $blockMatches ? $this->searchExcerpt($payload, (string) $row['page_excerpt']) : (string) $row['page_excerpt'];
            $score = $this->searchScore($title, $blockMatches ? $blockText : $pageText, $query);
            if (!$blockMatches && !$pageMatches) continue;
            $key = $url;
            if (!isset($results[$key]) || $score > $results[$key]['score']) {
                $results[$key] = ['title' => $title, 'excerpt' => $excerpt, 'url' => $url, 'type' => $blockMatches ? 'section' : 'page', 'score' => $score];
            }
        }

        uasort($results, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        return array_slice(array_values($results), 0, $limit);
    }

    private function searchableText(array $data): string
    {
        $text = [];
        array_walk_recursive($data, static function (mixed $value) use (&$text): void {
            if (is_string($value) && !preg_match('#^(?:https?://|mailto:|tel:|/|\#)#i', $value)) $text[] = $value;
        });
        return implode(' ', $text);
    }

    private function matchesSearch(string $text, array $tokens): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($tokens as $token) if (!str_contains($haystack, $token)) return false;
        return true;
    }

    private function searchTitle(?array $data, string $fallback): string
    {
        if (!is_array($data)) return $fallback;
        foreach (['title', 'eyebrow', 'label'] as $key) if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') return trim($data[$key]);
        return $fallback;
    }

    private function searchExcerpt(?array $data, string $fallback): string
    {
        if (!is_array($data)) return $fallback;
        foreach (['text', 'excerpt', 'description'] as $key) if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') return mb_substr(trim($data[$key]), 0, 240);
        $text = $this->searchableText($data);
        return $text !== '' ? mb_substr($text, 0, 240) : $fallback;
    }

    private function searchScore(string $title, string $text, string $query): int
    {
        $title = mb_strtolower($title); $text = mb_strtolower($text); $query = mb_strtolower(trim($query));
        if ($title === $query) return 100;
        if (str_starts_with($title, $query)) return 80;
        if (str_contains($title, $query)) return 60;
        return str_contains($text, $query) ? 40 : 20;
    }

    public function pages(?int $facilityId = null, ?array $facilityIds = null): array
    {
        $where=["p.status<>'archived'"];$parameters=[];if($facilityId){$where[]='p.facility_id=?';$parameters[]=$facilityId;}elseif($facilityIds!==null)$this->facilityScope($where,$parameters,'p.facility_id',$facilityIds);$statement=$this->db->prepare("SELECT p.*,t.title,t.slug,t.locale,COALESCE(ct.name,c.facility_slug) facility_name,c.city_slug,c.facility_slug,c.is_primary,c.homepage_page_id FROM pages p LEFT JOIN page_translations t ON t.page_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) INNER JOIN facilities c ON c.id=p.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) WHERE ".implode(' AND ',$where)." ORDER BY c.is_primary DESC,c.sort_order,p.updated_at DESC");$statement->execute($parameters);return$statement->fetchAll();
    }

    public function seoDocuments(string $type, ?array $facilityIds = null): array
    {
        if (!in_array($type, ['page', 'post'], true)) return [];
        $table = $type === 'page' ? 'pages' : 'posts';
        $translations = $type === 'page' ? 'page_translations' : 'post_translations';
        $foreign = $type . '_id';
        $where = ["d.status<>'archived'"]; $parameters = [];
        if ($facilityIds !== null) $this->facilityScope($where, $parameters, 'd.facility_id', $facilityIds);
        $statement = $this->db->prepare("SELECT d.*,COALESCE(t.title,CONCAT('".ucfirst($type)." #',d.id)) title,COALESCE(t.slug,'') slug,t.locale,COALESCE(ct.name,c.facility_slug) facility_name,c.city_slug,c.facility_slug,c.is_primary,c.homepage_page_id FROM {$table} d LEFT JOIN {$translations} t ON t.{$foreign}=d.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) INNER JOIN facilities c ON c.id=d.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) WHERE ".implode(' AND ',$where)." ORDER BY c.is_primary DESC,c.sort_order,d.updated_at DESC");
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function seoDocumentTranslations(string $type, int $id): array
    {
        if (!in_array($type, ['page', 'post'], true) || $id < 1) return [];
        $table = $type === 'page' ? 'page_translations' : 'post_translations';
        $foreign = $type . '_id';
        $statement = $this->db->prepare("SELECT * FROM {$table} WHERE {$foreign}=?");
        $statement->execute([$id]);
        return array_column($statement->fetchAll(), null, 'locale');
    }

    public function sitemapPages(): array
    {
        $rows = $this->db->query("SELECT 'page' content_type,p.id,p.updated_at,p.published_at,t.locale,t.slug,c.city_slug,c.facility_slug,c.is_primary,c.homepage_page_id FROM pages p INNER JOIN page_translations t ON t.page_id=p.id INNER JOIN languages l ON l.locale=t.locale AND l.enabled=1 INNER JOIN facilities c ON c.id=p.facility_id AND c.status='active' WHERE (p.status='published' OR (p.status='scheduled' AND p.published_at<=NOW())) AND p.visibility='public' UNION ALL SELECT 'post' content_type,p.id,p.updated_at,p.published_at,t.locale,t.slug,c.city_slug,c.facility_slug,c.is_primary,c.homepage_page_id FROM posts p INNER JOIN post_translations t ON t.post_id=p.id INNER JOIN languages l ON l.locale=t.locale AND l.enabled=1 INNER JOIN facilities c ON c.id=p.facility_id AND c.status='active' WHERE (p.status='published' OR (p.status='scheduled' AND p.published_at<=NOW())) ORDER BY content_type,id")->fetchAll();
        $excluded = [];
        $settings = $this->db->query("SELECT `key`,value FROM settings WHERE `key` LIKE 'seo\\_page\\_%' OR `key` LIKE 'seo\\_post\\_%'")->fetchAll();
        foreach ($settings as $setting) {
            $meta = json_decode((string) $setting['value'], true);
            if (is_array($meta) && str_contains((string) ($meta['robots'] ?? ''), 'noindex')) $excluded[$setting['key']] = true;
        }
        return array_values(array_filter($rows, static fn(array $row): bool => !isset($excluded['seo_' . $row['content_type'] . '_' . $row['id'] . '_' . $row['locale']])));
    }

    public function contentPages(string $query = '', string $status = 'all', int $page = 1, int $perPage = 12, ?int $facilityId = null, ?array $facilityIds = null): array
    {
        $where=[];$parameters=[];if($facilityId){$where[]='p.facility_id=?';$parameters[]=$facilityId;}elseif($facilityIds!==null)$this->facilityScope($where,$parameters,'p.facility_id',$facilityIds);if(in_array($status,['draft','published','private','scheduled','archived'],true)){$where[]='p.status=?';$parameters[]=$status;}if($query!==''){$where[]='(t.title LIKE ? OR t.slug LIKE ? OR t.excerpt LIKE ?)';$like='%'.$query.'%';array_push($parameters,$like,$like,$like);}$whereSql=$where?'WHERE '.implode(' AND ',$where):'';$count=$this->db->prepare("SELECT COUNT(*) FROM pages p LEFT JOIN page_translations t ON t.page_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) {$whereSql}");$count->execute($parameters);$total=(int)$count->fetchColumn();$perPage=max(5,min(50,$perPage));$pages=max(1,(int)ceil($total/$perPage));$page=min(max(1,$page),$pages);
        $sql="SELECT p.*,COALESCE(t.title,CONCAT('Page #',p.id)) title,COALESCE(t.slug,'') slug,t.locale,COALESCE(ct.name,c.facility_slug) facility_name,c.city_slug,c.facility_slug,c.is_primary,c.homepage_page_id,(SELECT COUNT(*) FROM page_translations x WHERE x.page_id=p.id) translation_count,(SELECT COUNT(*) FROM content_blocks b WHERE b.page_id=p.id AND b.archived_at IS NULL) block_count FROM pages p LEFT JOIN page_translations t ON t.page_id=p.id AND t.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) INNER JOIN facilities c ON c.id=p.facility_id LEFT JOIN facility_translations ct ON ct.facility_id=c.id AND ct.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) {$whereSql} ORDER BY c.is_primary DESC,c.sort_order,p.updated_at DESC LIMIT ? OFFSET ?";$statement=$this->db->prepare($sql);$index=1;foreach($parameters as$value)$statement->bindValue($index++,$value);$statement->bindValue($index++,$perPage,PDO::PARAM_INT);$statement->bindValue($index,($page-1)*$perPage,PDO::PARAM_INT);$statement->execute();return['items'=>$statement->fetchAll(),'pagination'=>['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'total'=>$total],'counts'=>$this->statusCounts('pages',$facilityId,$facilityIds)];
    }

    public function pageAdmin(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM pages WHERE id=? LIMIT 1');$statement->execute([$id]);$page=$statement->fetch();if(!$page)return null;$translations=$this->db->prepare('SELECT * FROM page_translations WHERE page_id=?');$translations->execute([$id]);$page['translations']=array_column($translations->fetchAll(),null,'locale');return$page;
    }

    public function pageSlugs(int$id):array{$statement=$this->db->prepare('SELECT locale,slug FROM page_translations WHERE page_id=?');$statement->execute([$id]);return$statement->fetchAll(PDO::FETCH_KEY_PAIR);}

    public function setPageStatus(int $id, string $status, int $userId): bool
    {
        if(!in_array($status,['draft','archived'],true))return false;if($status==='archived'){$this->assertNotHomepage($id);$children=$this->db->prepare("SELECT COUNT(*) FROM pages WHERE parent_id=? AND status<>'archived'");$children->execute([$id]);if((int)$children->fetchColumn()>0)throw new \RuntimeException('Reassign or archive this page’s child pages first.');}$statement=$this->db->prepare("UPDATE pages SET status=?,workflow_state='draft',assigned_user_id=NULL,review_requested_at=NULL,reviewed_at=NULL,reviewed_by=NULL,published_at=NULL,updated_at=NOW() WHERE id=?");$statement->execute([$status,$id]);if(!$statement->rowCount())return false;$this->audit($userId,$status==='archived'?'page.archived':'page.restored','page',$id,[]);return true;
    }

    public function deletePagePermanently(int $id, int $userId): bool
    {
        $this->db->beginTransaction();
        try{
            $statement=$this->db->prepare('SELECT status FROM pages WHERE id=? FOR UPDATE');$statement->execute([$id]);$status=$statement->fetchColumn();
            if($status===false){$this->db->rollBack();return false;}
            if($status!=='archived')throw new \RuntimeException('Only pages already in the trash can be permanently deleted.');
            $this->assertNotHomepage($id);$this->deletePageRows($id);$this->audit($userId,'page.deleted','page',$id,['permanent'=>true]);$this->db->commit();return true;
        }catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function emptyPageTrash(int $userId, ?array $facilityIds = null): int
    {
        $this->db->beginTransaction();
        try{
            $where=["status='archived'"];$parameters=[];$this->facilityScope($where,$parameters,'facility_id',$facilityIds);$statement=$this->db->prepare('SELECT id FROM pages WHERE '.implode(' AND ',$where).' ORDER BY id FOR UPDATE');$statement->execute($parameters);$ids=array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
            foreach($ids as$id){$this->assertNotHomepage($id);$this->deletePageRows($id);$this->audit($userId,'page.deleted','page',$id,['permanent'=>true,'trash_empty'=>true]);}
            $this->audit($userId,'page.trash_emptied','page',0,['count'=>count($ids)]);$this->db->commit();return count($ids);
        }catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function duplicatePage(int $id, int $userId): int
    {
        $page=$this->pageAdmin($id);
        if(!$page)throw new \RuntimeException('The page was not found.');
        $this->db->beginTransaction();
        try{
            $this->db->prepare("INSERT INTO pages (parent_id,facility_id,owner_user_id,assigned_user_id,workflow_state,editorial_note,template,featured_media_id,status,visibility,sort_order,published_at,created_at,updated_at) VALUES (?,?,?,NULL,'draft',NULL,?,?,?,?,?,NULL,NOW(),NOW())")
                ->execute([$page['parent_id'],$page['facility_id'],$userId,$page['template'],$page['featured_media_id'],'draft',$page['visibility'],$page['sort_order']]);
            $copyId=(int)$this->db->lastInsertId();
            $translation=$this->db->prepare('INSERT INTO page_translations (page_id,facility_id,locale,title,slug,excerpt,seo_title,seo_description,canonical_url,robots) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach($page['translations']as$locale=>$value)$translation->execute([$copyId,$page['facility_id'],$locale,mb_substr($value['title'].' — Copy',0,255),$this->uniqueTranslationSlug('page_translations','page_id',(int)$page['facility_id'],$locale,$value['slug'].'-copy'),$value['excerpt'],$value['seo_title'],$value['seo_description'],$value['canonical_url'],$value['robots']]);
            $blocks=$this->db->prepare('SELECT * FROM content_blocks WHERE page_id=? AND archived_at IS NULL ORDER BY sort_order,id');
            $blocks->execute([$id]);
            $blockInsert=$this->db->prepare('INSERT INTO content_blocks (uid,page_id,type,source_theme,global_section_id,settings,visible,visible_from,visible_until,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $blockTranslation=$this->db->prepare('INSERT INTO content_block_translations (block_id,locale,data) SELECT ?,locale,data FROM content_block_translations WHERE block_id=?');
            foreach($blocks->fetchAll()as$block){
                $blockInsert->execute([$this->uuid(),$copyId,$block['type'],$block['source_theme'],$block['global_section_id']??null,$block['settings'],$block['visible'],$block['visible_from']??null,$block['visible_until']??null,$block['sort_order']]);
                $blockTranslation->execute([(int)$this->db->lastInsertId(),$block['id']]);
            }
            $this->db->prepare('INSERT INTO page_builder_states (page_id,version,updated_by,updated_at) VALUES (?,0,?,NOW())')->execute([$copyId,$userId]);
            $this->audit($userId,'page.duplicated','page',$copyId,['source_id'=>$id,'facility_id'=>(int)$page['facility_id']]);
            $this->db->commit();
            return$copyId;
        }catch(\Throwable$error){
            $this->db->rollBack();
            throw$error;
        }
    }

    private function assertNotHomepage(int$id):void{$statement=$this->db->prepare('SELECT 1 FROM facilities WHERE homepage_page_id=? LIMIT 1');$statement->execute([$id]);if($statement->fetchColumn())throw new \RuntimeException('A facility homepage is protected and cannot be archived or permanently deleted. Choose another homepage first.');}
    private function deletePageRows(int$id):void
    {
        $this->db->prepare('DELETE t FROM content_block_translations t INNER JOIN content_blocks b ON b.id=t.block_id WHERE b.page_id=?')->execute([$id]);
        $this->db->prepare('DELETE FROM content_blocks WHERE page_id=?')->execute([$id]);
        $this->db->prepare('DELETE FROM page_builder_revisions WHERE page_id=?')->execute([$id]);
        $this->db->prepare('DELETE FROM page_builder_states WHERE page_id=?')->execute([$id]);
        $this->db->prepare('UPDATE form_submissions SET page_id=NULL WHERE page_id=?')->execute([$id]);
        $this->db->prepare('UPDATE menu_items SET page_id=NULL,archived_at=COALESCE(archived_at,NOW()) WHERE page_id=?')->execute([$id]);
        $this->db->prepare('UPDATE pages SET parent_id=NULL,updated_at=NOW() WHERE parent_id=?')->execute([$id]);
        $this->db->prepare('DELETE FROM page_translations WHERE page_id=?')->execute([$id]);
        $campaigns=(array)$this->setting('page_popups',[]);if(array_key_exists((string)$id,$campaigns)||array_key_exists($id,$campaigns)){unset($campaigns[(string)$id],$campaigns[$id]);$this->saveSetting('page_popups',$campaigns);}
        $this->db->prepare('DELETE FROM pages WHERE id=?')->execute([$id]);
    }

    public function builderDocument(int $pageId): ?array
    {
        $page = $this->db->prepare('SELECT p.*, COALESCE(pt.title, CONCAT("Page #", p.id)) title, COALESCE(pt.slug, "") slug, COALESCE(s.version, 0) builder_version FROM pages p LEFT JOIN page_translations pt ON pt.page_id=p.id AND pt.locale=(SELECT locale FROM languages WHERE enabled=1 AND is_default=1 ORDER BY id LIMIT 1) LEFT JOIN page_builder_states s ON s.page_id=p.id WHERE p.id=? LIMIT 1');
        $page->execute([$pageId]); $document = $page->fetch();
        if (!$document) return null;
        $translations = $this->db->prepare('SELECT pt.* FROM page_translations pt INNER JOIN languages l ON l.locale=pt.locale AND l.enabled=1 WHERE pt.page_id=? ORDER BY l.sort_order,l.id');
        $translations->execute([$pageId]); $document['translations'] = array_column($translations->fetchAll(), null, 'locale');
        $blocks = $this->db->prepare('SELECT b.id,b.uid,COALESCE(g.type,b.type) type,COALESCE(g.source_theme,b.source_theme) source_theme,b.global_section_id,g.name global_name,COALESCE(g.version,0) global_version,COALESCE(g.settings,b.settings) settings,b.visible,b.visible_from,b.visible_until,b.sort_order,b.updated_at FROM content_blocks b LEFT JOIN global_sections g ON g.id=b.global_section_id AND g.active=1 AND g.archived_at IS NULL WHERE b.page_id=? AND b.archived_at IS NULL ORDER BY b.sort_order,b.id');
        $blocks->execute([$pageId]); $document['blocks'] = $blocks->fetchAll();
        foreach ($document['blocks'] as &$block) { $shared=json_decode((string)($block['settings']??''),true); $block['shared']=is_array($shared)?$shared:[]; unset($block['settings']); }
        unset($block);
        if ($document['blocks']) {
            $ids = array_column($document['blocks'], 'id'); $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->db->prepare("SELECT block_id,locale,data FROM content_block_translations WHERE block_id IN ({$placeholders})"); $rows->execute($ids);
            $localized = [];
            foreach ($rows->fetchAll() as $row) { $data = json_decode((string) $row['data'], true); $localized[(int) $row['block_id']][(string) $row['locale']] = is_array($data) ? $data : []; }
            $globalIds=array_values(array_unique(array_filter(array_map(static fn(array$block):int=>(int)($block['global_section_id']??0),$document['blocks']))));
            $globalLocalized=[];
            if($globalIds){$globalMarks=implode(',',array_fill(0,count($globalIds),'?'));$globalRows=$this->db->prepare("SELECT global_section_id,locale,data FROM global_section_translations WHERE global_section_id IN ({$globalMarks})");$globalRows->execute($globalIds);foreach($globalRows->fetchAll()as$row){$data=json_decode((string)$row['data'],true);$globalLocalized[(int)$row['global_section_id']][(string)$row['locale']]=is_array($data)?$data:[];}}
            foreach ($document['blocks'] as &$block) $block['localized'] = $globalLocalized[(int)($block['global_section_id']??0)] ?? $localized[(int) $block['id']] ?? [];
            unset($block);
        }
        return $document;
    }

    public function saveBuilderDocument(int $pageId, int $version, int $userId, string $theme, array $blocks, array $managedTypes): int
    {
        $this->db->beginTransaction();
        try {
            $page = $this->db->prepare('SELECT id,status FROM pages WHERE id=? FOR UPDATE'); $page->execute([$pageId]); $pageRecord=$page->fetch();
            if (!$pageRecord) throw new \RuntimeException('The selected page no longer exists.');
            $this->db->prepare('INSERT IGNORE INTO page_builder_states (page_id,version,updated_by,updated_at) VALUES (?,0,?,NOW())')->execute([$pageId,$userId]);
            $state = $this->db->prepare('SELECT version FROM page_builder_states WHERE page_id=? FOR UPDATE'); $state->execute([$pageId]); $current = (int) $state->fetchColumn();
            if ($current !== $version) throw new \DomainException('This page was changed in another session. Reload the builder before saving.');
            $existing = $this->db->prepare('SELECT id,page_id,uid FROM content_blocks WHERE page_id=? FOR UPDATE'); $existing->execute([$pageId]); $existing = array_column($existing->fetchAll(), null, 'uid');
            $saved = []; $linked = [];
            foreach ($blocks as $order => $block) {
                $uid = (string) $block['uid']; $saved[] = $uid;
                $globalId=(int)($block['global_section_id']??0)?:null;
                if($globalId){
                    if(isset($linked[$globalId]))throw new \RuntimeException('A shared section can only appear once on the same page.');
                    $linked[$globalId]=true;$global=$this->db->prepare('SELECT id,type,settings,version FROM global_sections WHERE id=? AND active=1 AND archived_at IS NULL FOR UPDATE');$global->execute([$globalId]);$global=$global->fetch();
                    if(!$global||$global['type']!==$block['type'])throw new \RuntimeException('The selected shared section is no longer available.');
                    $storedShared=json_decode((string)$global['settings'],true);$storedShared=is_array($storedShared)?$storedShared:[];$storedTranslation=$this->db->prepare('SELECT locale,data FROM global_section_translations WHERE global_section_id=?');$storedTranslation->execute([$globalId]);$storedLocalized=[];foreach($storedTranslation->fetchAll()as$row){$data=json_decode((string)$row['data'],true);$storedLocalized[(string)$row['locale']]=is_array($data)?$data:[];}$changed=$storedShared!=$block['shared']||$storedLocalized!=$block['localized'];
                    if($changed&&(int)$global['version']!==(int)($block['global_version']??0))throw new \DomainException('This shared section was changed in another session. Reload the builder before saving.');
                    if($changed){$this->db->prepare('UPDATE global_sections SET source_theme=?,settings=?,version=version+1,updated_by=?,updated_at=NOW() WHERE id=?')->execute([$block['source'],json_encode($block['shared'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,$globalId]);$globalTranslation=$this->db->prepare('INSERT INTO global_section_translations (global_section_id,locale,data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)');foreach($block['localized']as$locale=>$data)$globalTranslation->execute([$globalId,$locale,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
                }
                if (isset($existing[$uid])) {
                    $blockId = (int) $existing[$uid]['id'];
                    $this->db->prepare('UPDATE content_blocks SET type=?,source_theme=?,global_section_id=?,visible=?,visible_from=?,visible_until=?,sort_order=?,settings=?,archived_at=NULL,updated_at=NOW() WHERE id=? AND page_id=?')->execute([$block['type'],$block['source'],$globalId,$block['visible']?1:0,$block['visible_from'],$block['visible_until'],$order,json_encode($block['shared'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$blockId,$pageId]);
                } else {
                    $collision = $this->db->prepare('SELECT page_id FROM content_blocks WHERE uid=? LIMIT 1'); $collision->execute([$uid]);
                    if ($collision->fetchColumn() !== false) throw new \RuntimeException('A section identifier belongs to another page.');
                    $this->db->prepare('INSERT INTO content_blocks (uid,page_id,type,source_theme,global_section_id,settings,visible,visible_from,visible_until,archived_at,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NULL,?,NOW(),NOW())')->execute([$uid,$pageId,$block['type'],$block['source'],$globalId,json_encode($block['shared'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$block['visible']?1:0,$block['visible_from'],$block['visible_until'],$order]);
                    $blockId = (int) $this->db->lastInsertId();
                }
                $translation = $this->db->prepare('INSERT INTO content_block_translations (block_id,locale,data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)');
                foreach ($block['localized'] as $locale => $data) $translation->execute([$blockId,$locale,json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $managedTypes=array_values(array_filter(array_unique($managedTypes),'is_string'));
            if($managedTypes){$typeMarks=implode(',',array_fill(0,count($managedTypes),'?'));$parameters=[$pageId,...$managedTypes];$sql="UPDATE content_blocks SET visible=0,archived_at=NOW(),updated_at=NOW() WHERE page_id=? AND archived_at IS NULL AND type IN ({$typeMarks})";if($saved){$uidMarks=implode(',',array_fill(0,count($saved),'?'));$sql.=" AND uid NOT IN ({$uidMarks})";$parameters=array_merge($parameters,$saved);}$this->db->prepare($sql)->execute($parameters);}
            $next = $current + 1;
            $this->db->prepare('UPDATE page_builder_states SET version=?,updated_by=?,updated_at=NOW() WHERE page_id=?')->execute([$next,$userId,$pageId]);
            if(in_array((string)$pageRecord['status'],['published','scheduled','private'],true))$this->db->prepare("UPDATE pages SET workflow_state='approved',updated_at=NOW() WHERE id=?")->execute([$pageId]);
            else $this->db->prepare("UPDATE pages SET workflow_state='draft',reviewed_at=NULL,reviewed_by=NULL,updated_at=NOW() WHERE id=?")->execute([$pageId]);
            $this->db->prepare('INSERT INTO page_builder_revisions (page_id,version,snapshot,created_by,created_at) VALUES (?,?,?,?,NOW())')->execute([$pageId,$next,json_encode(['blocks'=>$blocks],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId]);
            $this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,"page.builder.updated","page",?,?,NOW())')->execute([$userId,$pageId,json_encode(['version'=>$next,'blocks'=>count($blocks),'theme'=>$theme], JSON_UNESCAPED_SLASHES)]);
            $this->db->commit(); $this->events->dispatch('page.builder.updated', ['page_id'=>$pageId,'version'=>$next]); return $next;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function registerMedia(array $media): int
    {
        $statement = $this->db->prepare('INSERT INTO media (path,mime_type,original_name,alt_text,caption,width,height,size_bytes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
        $statement->execute([$media['path'],$media['mime_type'],$media['original_name'],'','',$media['width'],$media['height'],$media['size_bytes']]);
        return (int) $this->db->lastInsertId();
    }

    public function mediaLibrary(string $query='',string $kind='all',int $page=1,int $perPage=24):array
    {
        $page=max(1,$page);$perPage=max(6,min(48,$perPage));$where=[];$parameters=[];
        if($query!==''){$where[]='original_name LIKE ?';$parameters[]='%'.$query.'%';}
        if(in_array($kind,['image','video'],true)){$where[]='mime_type LIKE ?';$parameters[]=$kind.'/%';}
        $whereSql=$where?'WHERE '.implode(' AND ',$where):'';$count=$this->db->prepare("SELECT COUNT(*) FROM media {$whereSql}");$count->execute($parameters);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        $statement=$this->db->prepare("SELECT id,path url,mime_type,original_name,width,height,size_bytes,created_at FROM media {$whereSql} ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?");$index=1;foreach($parameters as$value)$statement->bindValue($index++,$value);$statement->bindValue($index++,$perPage,\PDO::PARAM_INT);$statement->bindValue($index,($page-1)*$perPage,\PDO::PARAM_INT);$statement->execute();
        return['items'=>$statement->fetchAll(),'pagination'=>['page'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>$perPage]];
    }

    public function globalSections():array
    {
        $sections=$this->db->query("SELECT g.id,g.uid,g.name,g.type,g.source_theme,g.settings,g.version,g.updated_at,(SELECT COUNT(*) FROM content_blocks b WHERE b.global_section_id=g.id AND b.archived_at IS NULL) usage_count FROM global_sections g WHERE g.active=1 AND g.archived_at IS NULL ORDER BY g.updated_at DESC,g.id DESC")->fetchAll();
        if(!$sections)return[];$ids=array_column($sections,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$translations=$this->db->prepare("SELECT t.global_section_id,t.locale,t.data FROM global_section_translations t INNER JOIN languages l ON l.locale=t.locale AND l.enabled=1 WHERE t.global_section_id IN ({$marks}) ORDER BY l.sort_order,l.id");$translations->execute($ids);$localized=[];foreach($translations->fetchAll()as$row){$data=json_decode((string)$row['data'],true);$localized[(int)$row['global_section_id']][(string)$row['locale']]=is_array($data)?$data:[];}
        foreach($sections as&$section){$settings=json_decode((string)$section['settings'],true);$section['shared']=is_array($settings)?$settings:[];$section['localized']=$localized[(int)$section['id']]??[];unset($section['settings']);}unset($section);return$sections;
    }

    public function createGlobalSection(string $name,array $block,int $userId):array
    {
        $name=mb_substr(trim($name),0,120);if(mb_strlen($name)<2)throw new \RuntimeException('Shared section name must contain at least two characters.');$this->db->beginTransaction();
        try{$uid=$this->uuid();$this->db->prepare('INSERT INTO global_sections (uid,name,type,source_theme,settings,version,active,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,0,1,?,?,NOW(),NOW())')->execute([$uid,$name,$block['type'],$block['source'],json_encode($block['shared'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId,$userId]);$id=(int)$this->db->lastInsertId();$translation=$this->db->prepare('INSERT INTO global_section_translations (global_section_id,locale,data) VALUES (?,?,?)');foreach($block['localized']as$locale=>$data)$translation->execute([$id,$locale,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$this->audit($userId,'page.global-section.created','global_section',$id,['type'=>$block['type']]);$this->db->commit();return['id'=>$id,'uid'=>$uid,'name'=>$name,'type'=>$block['type'],'source_theme'=>$block['source'],'shared'=>$block['shared'],'localized'=>$block['localized'],'version'=>0,'usage_count'=>0,'updated_at'=>date('Y-m-d H:i:s')];}catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function archiveGlobalSection(int $id,int $userId):void
    {
        $usage=$this->db->prepare('SELECT COUNT(*) FROM content_blocks WHERE global_section_id=? AND archived_at IS NULL');$usage->execute([$id]);if((int)$usage->fetchColumn()>0)throw new \RuntimeException('Detach this shared section from every page before archiving it.');$statement=$this->db->prepare('UPDATE global_sections SET active=0,archived_at=NOW(),updated_by=?,updated_at=NOW() WHERE id=? AND active=1 AND archived_at IS NULL');$statement->execute([$userId,$id]);if(!$statement->rowCount())throw new \RuntimeException('The shared section is no longer available.');$this->audit($userId,'page.global-section.archived','global_section',$id,[]);
    }

    public function renameGlobalSection(int $id,string $name,int $userId):void
    {
        $name=mb_substr(trim($name),0,120);if(mb_strlen($name)<2)throw new \RuntimeException('Shared section name must contain at least two characters.');$statement=$this->db->prepare('UPDATE global_sections SET name=?,updated_by=?,updated_at=NOW() WHERE id=? AND active=1 AND archived_at IS NULL');$statement->execute([$name,$userId,$id]);if(!$statement->rowCount()&&!array_filter($this->globalSections(),static fn(array$section):bool=>(int)$section['id']===$id))throw new \RuntimeException('The shared section is no longer available.');$this->audit($userId,'page.global-section.renamed','global_section',$id,['name'=>$name]);
    }

    public function builderRevisions(int $pageId,int $limit=30):array
    {
        $statement=$this->db->prepare('SELECT r.id,r.version,r.created_at,COALESCE(u.name,"System") author FROM page_builder_revisions r LEFT JOIN users u ON u.id=r.created_by WHERE r.page_id=? ORDER BY r.version DESC LIMIT ?');$statement->bindValue(1,$pageId,\PDO::PARAM_INT);$statement->bindValue(2,max(1,min(100,$limit)),\PDO::PARAM_INT);$statement->execute();return$statement->fetchAll();
    }

    public function builderRevision(int $pageId,int $revisionId):?array
    {
        $statement=$this->db->prepare('SELECT id,version,snapshot FROM page_builder_revisions WHERE id=? AND page_id=? LIMIT 1');$statement->execute([$revisionId,$pageId]);$revision=$statement->fetch();if(!$revision)return null;$snapshot=json_decode((string)$revision['snapshot'],true);$revision['snapshot']=is_array($snapshot)?$snapshot:[];return$revision;
    }

    public function contactForm(string $uid,string $locale,string $fallback):?array
    {
        $statement=$this->db->prepare("SELECT b.id,b.uid,b.page_id,p.facility_id,COALESCE(g.settings,b.settings) settings,p.status,p.visibility,COALESCE(gt.data,gft.data,t.data,ft.data) data FROM content_blocks b INNER JOIN pages p ON p.id=b.page_id LEFT JOIN global_sections g ON g.id=b.global_section_id AND g.active=1 AND g.archived_at IS NULL LEFT JOIN content_block_translations t ON t.block_id=b.id AND t.locale=? LEFT JOIN content_block_translations ft ON ft.block_id=b.id AND ft.locale=? LEFT JOIN global_section_translations gt ON gt.global_section_id=g.id AND gt.locale=? LEFT JOIN global_section_translations gft ON gft.global_section_id=g.id AND gft.locale=? WHERE b.uid=? AND COALESCE(g.type,b.type)='contact-form' AND b.visible=1 AND b.archived_at IS NULL AND (b.visible_from IS NULL OR b.visible_from<=NOW()) AND (b.visible_until IS NULL OR b.visible_until>NOW()) AND p.status='published' AND p.visibility='public' LIMIT 1");
        $statement->execute([$locale,$fallback,$locale,$fallback,$uid]);$form=$statement->fetch();if(!$form)return null;
        $data=json_decode((string)$form['data'],true);$settings=json_decode((string)$form['settings'],true);$form['data']=is_array($data)?$data:[];$form['settings']=is_array($settings)?$settings:[];return$form;
    }

    public function saveFormSubmission(array $form,string $locale,array $payload,string $visitorHash):array
    {
        $uid=$this->uuid();$this->db->beginTransaction();
        try{
            $slug='builder-'.str_replace('-','',(string)$form['uid']);$schema=json_encode($form['data']['fields']??[],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$settings=json_encode($form['settings'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $upsert=$this->db->prepare('INSERT INTO forms (block_uid,slug,name,schema_json,active,recipient,settings,created_at,updated_at) VALUES (?,?,?,?,1,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),schema_json=VALUES(schema_json),active=1,recipient=VALUES(recipient),settings=VALUES(settings),updated_at=NOW()');
            $upsert->execute([$form['uid'],$slug,mb_substr((string)($form['data']['title']??'Contact form'),0,150),$schema,$form['settings']['recipient_email']??null,$settings]);
            $formId=(int)$this->db->lastInsertId();if(!$formId){$find=$this->db->prepare('SELECT id FROM forms WHERE block_uid=?');$find->execute([$form['uid']]);$formId=(int)$find->fetchColumn();}
            $insert=$this->db->prepare('INSERT INTO form_submissions (uid,form_id,page_id,facility_id,block_uid,locale,visitor_hash,payload,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?, ?,"new",NOW(),NOW())');
            $insert->execute([$uid,$formId,$form['page_id'],$form['facility_id'],$form['uid'],$locale,$visitorHash,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$id=(int)$this->db->lastInsertId();
            $this->db->commit();$this->events->dispatch('form.submitted',['submission_id'=>$id,'form_id'=>$formId]);return['id'=>$id,'uid'=>$uid,'form_id'=>$formId];
        }catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    public function markSubmissionNotified(int $id):void{$this->db->prepare('UPDATE form_submissions SET notified_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$id]);}
    public function recentFormRecipientCount(string $email):int{$s=$this->db->prepare("SELECT COUNT(*) FROM form_submissions WHERE created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.email')))=LOWER(?)");$s->execute([$email]);return(int)$s->fetchColumn();}
    public function recentFormSubmissionCount(string $blockUid,string $visitorHash,string $since):int{$statement=$this->db->prepare('SELECT COUNT(*) FROM form_submissions WHERE block_uid=? AND visitor_hash=? AND created_at>=? AND deleted_at IS NULL');$statement->execute([$blockUid,$visitorHash,$since]);return(int)$statement->fetchColumn();}
    public function formUnreadCount(?array$facilityIds=null):int{$where=["deleted_at IS NULL","status='new'"];$parameters=[];$this->facilityScope($where,$parameters,'facility_id',$facilityIds);$statement=$this->db->prepare('SELECT COUNT(*) FROM form_submissions WHERE '.implode(' AND ',$where));$statement->execute($parameters);return(int)$statement->fetchColumn();}

    public function formSubmissions(string $query='',string $status='all',int $page=1,int $perPage=20,?array$facilityIds=null):array
    {
        $where=['s.deleted_at IS NULL'];$parameters=[];
        $this->facilityScope($where,$parameters,'s.facility_id',$facilityIds);
        if(in_array($status,['new','read','handled','spam','archived'],true)){$where[]='s.status=?';$parameters[]=$status;}
        if($query!==''){$where[]='(f.name LIKE ? OR CAST(s.payload AS CHAR) LIKE ?)';$pattern='%'.strtr(mb_substr($query,0,100),['%'=>'\\%','_'=>'\\_']).'%';array_push($parameters,$pattern,$pattern);}
        $whereSql=implode(' AND ',$where);$count=$this->db->prepare("SELECT COUNT(*) FROM form_submissions s INNER JOIN forms f ON f.id=s.form_id WHERE {$whereSql}");$count->execute($parameters);$total=(int)$count->fetchColumn();$page=max(1,$page);$perPage=max(5,min(50,$perPage));$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
        $list=$this->db->prepare("SELECT s.id,s.uid,s.page_id,s.block_uid,s.locale,s.status,s.created_at,s.updated_at,s.handled_at,s.notified_at,s.payload,f.name form_name FROM form_submissions s INNER JOIN forms f ON f.id=s.form_id WHERE {$whereSql} ORDER BY s.created_at DESC LIMIT ? OFFSET ?");
        foreach([...$parameters,$perPage,$offset]as$index=>$value)$list->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);$list->execute();$items=[];
        foreach($list->fetchAll()as$row){$payload=json_decode((string)$row['payload'],true);$row['payload']=is_array($payload)?$payload:[];$row['sender']=$this->submissionSender($row['payload']);$row['preview']=$this->submissionPreview($row['payload']);$items[]=$row;}
        return['items'=>$items,'pagination'=>['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'total'=>$total],'counts'=>$this->formSubmissionCounts($facilityIds)];
    }

    public function formSubmission(int $id,int $userId=0):?array
    {
        $statement=$this->db->prepare('SELECT s.*,f.name form_name,f.recipient,f.schema_json FROM form_submissions s INNER JOIN forms f ON f.id=s.form_id WHERE s.id=? AND s.deleted_at IS NULL LIMIT 1');$statement->execute([$id]);$row=$statement->fetch();if(!$row)return null;$payload=json_decode((string)$row['payload'],true);$row['payload']=is_array($payload)?$payload:[];$schema=json_decode((string)($row['schema_json']??''),true);$row['field_labels']=[];foreach(is_array($schema)?$schema:[]as$field)if(is_array($field)&&isset($field['key']))$row['field_labels'][(string)$field['key']]=(string)($field['label']??$field['key']);unset($row['schema_json']);
        if($row['status']==='new'){$this->db->prepare("UPDATE form_submissions SET status='read',updated_at=NOW() WHERE id=? AND status='new'")->execute([$id]);$row['status']='read';$this->audit($userId,'form.submission.read','form_submission',$id,[]);}return$row;
    }

    public function updateFormSubmission(int $id,string $status,int $userId):bool
    {
        if(!in_array($status,['read','handled','spam','archived'],true))return false;$statement=$this->db->prepare('UPDATE form_submissions SET status=?,handled_at=IF(?="handled",NOW(),handled_at),updated_at=NOW() WHERE id=? AND deleted_at IS NULL');$statement->execute([$status,$status,$id]);if(!$statement->rowCount())return false;$this->audit($userId,'form.submission.status','form_submission',$id,['status'=>$status]);return true;
    }

    public function deleteFormSubmission(int $id,int $userId):bool
    {
        $statement=$this->db->prepare('UPDATE form_submissions SET payload=JSON_OBJECT(),visitor_hash=NULL,status="archived",deleted_at=NOW(),updated_at=NOW() WHERE id=? AND deleted_at IS NULL');$statement->execute([$id]);if(!$statement->rowCount())return false;$this->audit($userId,'form.submission.deleted','form_submission',$id,[]);return true;
    }

    public function formSubmissionExport(string $status='all',?array$facilityIds=null):array
    {
        $where=['s.deleted_at IS NULL'];$parameters=[];$this->facilityScope($where,$parameters,'s.facility_id',$facilityIds);if(in_array($status,['new','read','handled','spam','archived'],true)){$where[]='s.status=?';$parameters[]=$status;}$statement=$this->db->prepare('SELECT s.id,s.uid,s.locale,s.status,s.created_at,s.handled_at,s.payload,f.name form_name FROM form_submissions s INNER JOIN forms f ON f.id=s.form_id WHERE '.implode(' AND ',$where).' ORDER BY s.created_at DESC LIMIT 5000');$statement->execute($parameters);return array_map(static function(array$row):array{$payload=json_decode((string)$row['payload'],true);$row['payload']=is_array($payload)?$payload:[];return$row;},$statement->fetchAll());
    }

    private function formSubmissionCounts(?array$facilityIds=null):array{$where=['deleted_at IS NULL'];$parameters=[];$this->facilityScope($where,$parameters,'facility_id',$facilityIds);$statement=$this->db->prepare('SELECT status,COUNT(*) total FROM form_submissions WHERE '.implode(' AND ',$where).' GROUP BY status');$statement->execute($parameters);$rows=$statement->fetchAll();$counts=['all'=>0,'new'=>0,'read'=>0,'handled'=>0,'spam'=>0,'archived'=>0];foreach($rows as$row){$counts[$row['status']]=(int)$row['total'];$counts['all']+=(int)$row['total'];}return$counts;}
    private function submissionSender(array$payload):string{foreach(['name','full_name','email']as$key)if(trim((string)($payload[$key]??''))!=='')return mb_substr((string)$payload[$key],0,120);return'Website visitor';}
    private function submissionPreview(array$payload):string{foreach(['message','enquiry','question','email']as$key)if(trim((string)($payload[$key]??''))!=='')return mb_substr(preg_replace('/\s+/u',' ',(string)$payload[$key])??'',0,180);foreach($payload as$value)if(is_string($value)&&trim($value)!=='')return mb_substr($value,0,180);return'No preview available';}
    private function audit(int$userId,string$event,string$type,int$id,array$context):void{$this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId?:null,$event,$type,$id,json_encode($context,JSON_UNESCAPED_SLASHES)]);}
    private function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);}

    public function savePage(array $input, array $translations, int $userId): int
    {
        $pageId=(int)($input['id']??0);$parentId=(int)($input['parent_id']??0);$facilityId=(int)($input['facility_id']??0);if(!$facilityId)throw new \RuntimeException('Choose a facility for this page.');$this->assertPageParent($pageId,$parentId,$facilityId);$publicPath=PublicPagePath::validate($input['public_path']??($pageId?($this->pageAdmin($pageId)['public_path']??null):null));$publishedAt=$input['status']==='published'?($input['published_at']?:date('Y-m-d H:i:s')):($input['status']==='scheduled'?$input['published_at']:null);$workflow=in_array($input['status'],['published','scheduled','private'],true)?'approved':'draft';$this->db->beginTransaction();try{$page=['public_path'=>$publicPath,'parent_id'=>$parentId?:null,'facility_id'=>$facilityId,'assigned_user_id'=>(int)($input['assigned_user_id']??0)?:null,'workflow_state'=>$workflow,'editorial_note'=>mb_substr(trim((string)($input['editorial_note']??'')),0,1000)?:null,'template'=>$input['template']?:null,'status'=>$input['status'],'visibility'=>$input['visibility'],'published_at'=>$publishedAt];if($pageId){$statement=$this->db->prepare('UPDATE pages SET public_path=:public_path,parent_id=:parent_id,facility_id=:facility_id,assigned_user_id=:assigned_user_id,workflow_state=:workflow_state,editorial_note=:editorial_note,template=:template,status=:status,visibility=:visibility,published_at=:published_at,updated_at=NOW() WHERE id=:id');$statement->execute($page+['id'=>$pageId]);if(!$statement->rowCount()&&!$this->pageAdmin($pageId))throw new \RuntimeException('The page was not found.');}else{$this->db->prepare('INSERT INTO pages (public_path,parent_id,facility_id,owner_user_id,assigned_user_id,workflow_state,editorial_note,template,status,visibility,published_at,created_at,updated_at) VALUES (:public_path,:parent_id,:facility_id,:owner_user_id,:assigned_user_id,:workflow_state,:editorial_note,:template,:status,:visibility,:published_at,NOW(),NOW())')->execute($page+['owner_user_id'=>$userId]);$pageId=(int)$this->db->lastInsertId();}$upsert=$this->db->prepare('INSERT INTO page_translations (page_id,facility_id,locale,title,slug,excerpt,seo_title,seo_description) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE facility_id=VALUES(facility_id),title=VALUES(title),slug=VALUES(slug),excerpt=VALUES(excerpt),seo_title=VALUES(seo_title),seo_description=VALUES(seo_description)');foreach($translations as$locale=>$value)$upsert->execute([$pageId,$facilityId,$locale,$value['title'],$value['slug'],$value['excerpt'],$value['seo_title'],$value['seo_description']]);$this->audit($userId,$input['id']?'page.updated':'page.created','page',$pageId,['status'=>$input['status'],'workflow_state'=>$workflow,'facility_id'=>$facilityId]);$this->db->commit();$this->events->dispatch($input['id']?'page.updated':'page.created',['page_id'=>$pageId]);return$pageId;}catch(\Throwable$error){$this->db->rollBack();throw$error;}
    }

    private function statusCounts(string $table,?int$facilityId=null,?array$facilityIds=null): array
    {
        if(!in_array($table,['pages','posts'],true))return[];$where=[];$parameters=[];if($facilityId){$where[]='facility_id=?';$parameters[]=$facilityId;}elseif($facilityIds!==null)$this->facilityScope($where,$parameters,'facility_id',$facilityIds);$statement=$this->db->prepare("SELECT status,COUNT(*) total FROM {$table}".($where?' WHERE '.implode(' AND ',$where):'')." GROUP BY status");$statement->execute($parameters);$rows=$statement->fetchAll();$counts=['all'=>0,'draft'=>0,'published'=>0,'private'=>0,'scheduled'=>0,'archived'=>0];foreach($rows as$row){$counts[(string)$row['status']]=(int)$row['total'];$counts['all']+=(int)$row['total'];}return$counts;
    }

    private function facilityScope(array&$where,array&$parameters,string$column,?array$facilityIds):void
    {
        if($facilityIds===null)return;$ids=array_values(array_unique(array_filter(array_map('intval',$facilityIds))));if(!$ids){$where[]='1=0';return;}$where[]=$column.' IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($parameters,...$ids);
    }

    private function uniqueTranslationSlug(string $table, string $ownerColumn, int $facilityId, string $locale, string $candidate): string
    {
        if(!in_array($table,['page_translations','post_translations'],true)||!in_array($ownerColumn,['page_id','post_id'],true))throw new \InvalidArgumentException('Unsupported translation table.');$base=preg_replace('/[^a-z0-9-]+/','-',strtolower(trim($candidate)))?:'copy';$base=trim($base,'-');$slug=mb_substr($base,0,230);$check=$this->db->prepare("SELECT 1 FROM {$table} WHERE facility_id=? AND locale=? AND slug=? LIMIT 1");for($suffix=1;$suffix<1000;$suffix++){$check->execute([$facilityId,$locale,$slug]);if(!$check->fetchColumn())return$slug;$slug=mb_substr($base,0,220).'-'.$suffix;}throw new \RuntimeException('A unique URL slug could not be generated.');
    }

    private function categoryExists(int $id): bool
    {
        $statement=$this->db->prepare('SELECT 1 FROM categories WHERE id=?');$statement->execute([$id]);return(bool)$statement->fetchColumn();
    }

    private function activeCategoryExists(int $id): bool
    {
        $statement=$this->db->prepare('SELECT 1 FROM categories WHERE id=? AND archived_at IS NULL');$statement->execute([$id]);return(bool)$statement->fetchColumn();
    }

    private function assertPageParent(int $pageId,int $parentId,int$facilityId):void
    {
        if(!$parentId)return;$seen=[];$current=$parentId;$statement=$this->db->prepare("SELECT parent_id,status,facility_id FROM pages WHERE id=?");for($depth=0;$depth<100&&$current;$depth++){if($current===$pageId||isset($seen[$current]))throw new \RuntimeException('The selected parent would create a circular page hierarchy.');$seen[$current]=true;$statement->execute([$current]);$parent=$statement->fetch();if(!$parent||$parent['status']==='archived')throw new \RuntimeException('Choose an active parent page.');if((int)$parent['facility_id']!==$facilityId)throw new \RuntimeException('A parent page must belong to the same facility.');$current=(int)($parent['parent_id']??0);}if($current)throw new \RuntimeException('The page hierarchy is too deeply nested.');
    }

    public function activateTheme(string $slug): void
    {
        $this->db->beginTransaction();
        $this->db->exec('UPDATE installed_themes SET active = 0');
        $this->db->prepare('UPDATE installed_themes SET active = 1 WHERE slug = :slug')->execute(compact('slug'));
        $this->db->prepare("INSERT INTO settings (`key`, value) VALUES ('active_theme', :value) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute(['value' => json_encode($slug)]);
        $this->db->commit();
        $this->events->dispatch('theme.activated', compact('slug'));
    }
}
