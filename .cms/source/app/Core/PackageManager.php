<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class PackageManager
{
    private const TYPES = ['theme', 'plugin', 'addon'];
    private const MAX_ARCHIVE = 26214400;
    private const MAX_EXPANDED = 104857600;
    private const MAX_FILES = 2000;
    private array $themeMetadata = [];

    public function __construct(
        private readonly PDO $db,
        private readonly string $root,
        private readonly string $engineVersion,
        private readonly array $marketplace = []
    ) {}

    public function engineVersion(): string
    {
        return $this->engineVersion;
    }

    public function themeManager(): Packages\ThemeManager
    {
        return new Packages\ThemeManager(new Runtime($this->root));
    }

    public function trustedKeys(): array
    {
        $keys = [];
        foreach ($this->db->query('SELECT key_id,public_key FROM extension_publishers WHERE active=1')->fetchAll() as $publisher) {
            $key = base64_decode((string) $publisher['public_key'], true);
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) $keys[$publisher['key_id']] = $key;
        }
        return $keys;
    }

    public function syncBundled(array $themes, array $plugins, array $addons, array $installedThemes, array $installedPlugins): void
    {
        foreach ($themes as $slug => $manifest) {
            if (isset($manifest['_path']) && str_starts_with(str_replace('\\', '/', $manifest['_path']), str_replace('\\', '/', $this->root) . '/storage/themes/')) continue;
            $active = (bool) ($installedThemes[$slug]['active'] ?? false);
            $this->syncOne('theme', (string) $slug, $manifest, $active, 'themes/' . $slug);
        }
        foreach ($plugins as $slug => $manifest) {
            $active = (bool) ($installedPlugins[$slug]['active'] ?? false);
            $this->syncOne('plugin', (string) $slug, $manifest, $active, 'plugins/' . $slug);
        }
        foreach ($addons as $addon) {
            $slug = (string) ($addon['slug'] ?? '');
            if ($slug === '') continue;
            $manifest = [
                'name' => $addon['name'] ?? $slug,
                'slug' => $slug,
                'version' => $addon['version'] ?? '1.0.0',
                'author' => $addon['publisher'] ?? 'QUANT Software House',
                'description' => $addon['description'] ?? '',
                'config_url' => $addon['config_url'] ?? null,
            ];
            $this->syncOne('addon', $slug, $manifest, ($addon['status'] ?? '') === 'active', null);
        }
    }

    public function packages(?string $type = null): array
    {
        $sql = 'SELECT p.*,(SELECT COUNT(*) FROM extension_package_releases r WHERE r.package_id=p.id AND r.restored_at IS NULL) rollback_count FROM extension_packages p';
        $parameters = [];
        if ($type !== null) {
            $this->assertType($type);
            $sql .= ' WHERE p.type=?';
            $parameters[] = $type;
        }
        $sql .= ' ORDER BY p.type,p.name';
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        $rows = array_map([$this, 'decodePackage'], $statement->fetchAll());
        if ($type === null || $type === 'theme') {
            $themes = $this->privateThemes(); $hasPrivateActive = $this->themeManager()->active() !== null;
            foreach ($rows as $index => $row) {
                if ($row['type'] !== 'theme') continue;
                if (isset($themes[$row['slug']])) { $rows[$index] = $themes[$row['slug']]; unset($themes[$row['slug']]); }
                elseif ($hasPrivateActive) $rows[$index]['active'] = false;
            }
            $rows = array_merge($rows, array_values($themes));
        }
        return $rows;
    }

    public function navigation(array $permissions): array
    {
        $items = [];
        foreach ($this->packages() as $package) {
            if (empty($package['active']) || !in_array($package['type'], ['addon', 'plugin'], true)) continue;
            $entries = (array) ($package['manifest']['navigation'] ?? []);
            if (isset($entries['label'])) $entries = [$entries];
            foreach ($entries as $entry) {
                if (!is_array($entry)) continue;
                $label = mb_substr(trim((string) ($entry['label'] ?? '')), 0, 80);
                $url = (string) ($entry['url'] ?? '');
                $icon = (string) ($entry['icon'] ?? 'blocks');
                $permission = (string) ($entry['permission'] ?? 'console.access');
                if ($label === '' || !preg_match('#^/[a-zA-Z0-9_./?=&%+-]{1,240}$#D', $url) || !preg_match('/^[a-z0-9-]{2,60}$/D', $icon) || !preg_match('/^[a-z][a-z0-9.-]{1,119}$/D', $permission)) continue;
                if (!in_array('system.owner', $permissions, true) && !in_array($permission, $permissions, true)) continue;
                $items[] = ['label' => $label, 'url' => $url, 'icon' => $icon, 'permission' => $permission, 'identity' => $package['type'] . ':' . $package['slug']];
            }
        }
        return $items;
    }

    public function package(string $type, string $slug): ?array
    {
        $this->assertIdentity($type, $slug);
        if ($type === 'theme') foreach ($this->packages('theme') as $row) if ($row['slug'] === $slug) return $row;
        if ($type === 'theme') return null;
        $statement = $this->db->prepare('SELECT p.*,(SELECT COUNT(*) FROM extension_package_releases r WHERE r.package_id=p.id AND r.restored_at IS NULL) rollback_count FROM extension_packages p WHERE p.type=? AND p.slug=? LIMIT 1');
        $statement->execute([$type, $slug]);
        $row = $statement->fetch();
        return $row ? $this->decodePackage($row) : null;
    }

    public function auditHistory(int$limit=250):array
    {
        $limit=max(20,min(500,$limit));$statement=$this->db->prepare("SELECT a.event,a.subject_type,a.context,a.created_at,COALESCE(u.name,'System') actor FROM activity_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.subject_type IN ('package','theme') ORDER BY a.id DESC LIMIT ?");$statement->bindValue(1,$limit,PDO::PARAM_INT);$statement->execute();$result=[];
        foreach($statement->fetchAll()as$row){$context=json_decode((string)($row['context']??''),true);$context=is_array($context)?$context:[];$identity=(string)($context['subject']??'');if($row['subject_type']==='theme'&&isset($context['slug']))$identity='theme:'.$context['slug'];if(!preg_match('/^(theme|plugin|addon):[a-z0-9-]+$/',$identity))continue;$result[$identity]??=[];if(count($result[$identity])>=8)continue;$result[$identity][]=['event'=>(string)$row['event'],'actor'=>(string)$row['actor'],'created_at'=>(string)$row['created_at'],'context'=>$context];}
        return$result;
    }

    public function publishers(): array
    {
        return $this->db->query('SELECT id,key_id,name,website,fingerprint,active,created_at,updated_at FROM extension_publishers ORDER BY active DESC,name')->fetchAll();
    }

    public function trustPublisher(string $keyId, string $name, string $website, string $publicKey, int $userId): array
    {
        $keyId = strtolower(trim($keyId));
        $name = trim($name);
        $website = trim($website);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $keyId)) throw new RuntimeException('Publisher key ID must contain 3–100 lowercase letters, numbers, dots, underscores or hyphens.');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) throw new RuntimeException('Publisher name must contain 2–150 characters.');
        if ($website !== '' && !$this->validHttpsUrl($website)) throw new RuntimeException('Publisher website must use a valid HTTPS URL.');
        $binary = base64_decode(preg_replace('/\s+/', '', $publicKey) ?: '', true);
        if ($binary === false || strlen($binary) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('The Ed25519 public key must be a valid Base64-encoded 32-byte key.');
        $encoded = base64_encode($binary);
        $fingerprint = hash('sha256', $binary);
        $statement = $this->db->prepare('INSERT INTO extension_publishers (key_id,name,website,public_key,fingerprint,active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),website=VALUES(website),public_key=VALUES(public_key),fingerprint=VALUES(fingerprint),active=1,updated_at=NOW()');
        try{$statement->execute([$keyId, $name, $website ?: null, $encoded, $fingerprint, $userId ?: null]);}catch(\PDOException $error){throw new RuntimeException('This publisher key ID or fingerprint is already assigned to another trust record.',0,$error);}
        $this->themeMetadata = [];
        $this->audit($userId, 'publisher.trusted', 'publisher', $keyId, ['fingerprint' => $fingerprint]);
        return ['key_id' => $keyId, 'name' => $name, 'fingerprint' => $fingerprint, 'active' => true];
    }

    public function setPublisherActive(string $keyId, bool $active, int $userId): void
    {
        $statement = $this->db->prepare('UPDATE extension_publishers SET active=?,updated_at=NOW() WHERE key_id=?');
        $statement->execute([$active ? 1 : 0, $keyId]);
        if (!$statement->rowCount()) throw new RuntimeException('Trusted publisher was not found.');
        $this->themeMetadata = [];
        $this->audit($userId, $active ? 'publisher.enabled' : 'publisher.disabled', 'publisher', $keyId, []);
    }

    public function stageUpload(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) throw new RuntimeException('Choose a valid signed ZIP package.');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_ARCHIVE) throw new RuntimeException('The package must not exceed 25 MB.');
        return $this->stageFile((string) $file['tmp_name'], $userId, true);
    }

    public function preserveStage(string$token,int$userId):string
    {
        $stage=$this->stage($token,$userId);$directory=$this->privateDirectory('submissions');$target=$directory.'/'.bin2hex(random_bytes(18)).'.zip';if(!rename((string)$stage['path'],$target))throw new RuntimeException('The verified submission could not be preserved for moderation.');@chmod($target,0600);unset($_SESSION['sensecms_package_stages'][$token]);return$target;
    }

    public function stageCatalogRelease(string$url,string$checksum,string$type,string$slug,string$version,int$userId,array$headers=[]):array
    {
        $this->assertIdentity($type,$slug);if(!preg_match('/^[a-f0-9]{64}$/',$checksum))throw new RuntimeException('Catalog checksum is invalid.');$directory=$this->privateDirectory('staging');$path=$directory.'/'.bin2hex(random_bytes(18)).'.zip';$this->download($url,$path,self::MAX_ARCHIVE,$headers);try{if(!hash_equals($checksum,hash_file('sha256',$path)))throw new RuntimeException('Downloaded package does not match the signed catalog checksum.');$staged=$this->stageFile($path,$userId,false,true);if($staged['type']!==$type||$staged['slug']!==$slug||$staged['version']!==$version)throw new RuntimeException('Downloaded package identity does not match the catalog release.');return$staged;}finally{if(is_file($path))@unlink($path);}
    }

    public function stageOfficialRelease(array$product,#[\SensitiveParameter] string$licenseKey,int$userId):array
    {
        $licenseKey=trim($licenseKey);LicenseClient::assertKey($licenseKey);
        $type=(string)($product['type']??'');$slug=(string)($product['slug']??'');$version=(string)($product['version']??'');$url=(string)($product['package_url']??'');$checksum=(string)($product['package_checksum']??'');
        $this->assertIdentity($type,$slug);if(empty($product['installable'])||!$this->validVersion($version)||!preg_match('/^[a-f0-9]{64}$/D',$checksum))throw new RuntimeException('This official product is not installable in Base CMS.');
        $base=rtrim((string)($this->marketplace['base_url']??''),'/');$installation=(string)($this->marketplace['installation_url']??'');if(!$this->validHttpsUrl($base)||!$this->validHttpsUrl($url)||!str_starts_with($url,$base.'/package/?id=')||!$this->validHttpsUrl($installation))throw new RuntimeException('Official Marketplace configuration is invalid.');
        $cms = require $this->root . '/config/product.php';
        $headers = Packages\Entitlement::headers($product, $licenseKey, $installation, $cms['license']);
        return$this->stageCatalogRelease($url,$checksum,$type,$slug,$version,$userId,$headers);
    }

    public function stageApprovedSubmission(string$path,string$checksum,int$userId):array
    {
        $base=realpath($this->privateDirectory('submissions'));$real=realpath($path);if(!$base||!$real||!str_starts_with(str_replace('\\','/',$real),rtrim(str_replace('\\','/',$base),'/').'/')||!hash_equals($checksum,hash_file('sha256',$real)))throw new RuntimeException('Approved submission archive failed its integrity check.');return$this->stageFile($real,$userId,false);
    }

    public function stageLocalFile(string $path, int $userId): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Local package staging is available only to CLI verification.');
        return $this->stageFile($path, $userId, false);
    }

    public function stageUpdate(string $type, string $slug, int $userId): array
    {
        $package = $this->package($type, $slug);
        if (!$package || empty($package['available_package_url']) || empty($package['available_version'])) throw new RuntimeException('No verified update is currently available for this package.');
        $directory = $this->privateDirectory('staging');
        $path = $directory . '/' . bin2hex(random_bytes(18)) . '.zip';
        $this->download((string) $package['available_package_url'], $path, self::MAX_ARCHIVE);
        try {
            $staged = $this->stageFile($path, $userId, false, true);
            if ($staged['type'] !== $type || $staged['slug'] !== $slug || version_compare($staged['version'], (string) $package['version'], '<=')) throw new RuntimeException('Downloaded update identity or version does not match the installed package.');
            return $staged;
        } finally {
            if (is_file($path)) @unlink($path);
        }
    }

    public function install(string $token, int $userId): array
    {
        return $this->locked(fn(): array => $this->installLocked($token, $userId));
    }

    private function installLocked(string $token, int $userId): array
    {
        $stage = $this->stage($token, $userId);
        $inspection = $this->inspect((string) $stage['path']);
        if (!hash_equals((string) $stage['checksum'], (string) $inspection['checksum'])) throw new RuntimeException('The staged package changed after verification.');
        $manifest = $inspection['manifest'];
        $type = (string) $manifest['type'];
        $slug = (string) $manifest['slug'];
        $version = (string) $manifest['version'];
        $current = $this->package($type, $slug);
        if ($current && version_compare($version, (string) ($current['installed_version'] ?? $current['version']), '<=')) throw new RuntimeException('Install a package version newer than the latest installed release.');
        $this->assertDependencies((array) ($manifest['dependencies'] ?? []));
        $this->preserveSource((string) $stage['path'], $inspection['checksum']);
        if ($type === 'theme') {
            if (!empty($manifest['migrations'])) throw new RuntimeException('Presentation themes cannot run database migrations.');
            $release = $this->themeManager()->install((string) $stage['path'], $this->trustedKeys(), false);
            $this->audit($userId, 'theme.release.installed', 'theme', $slug, ['slug'=>$slug,'version'=>$version,'directory'=>$release['directory']]);
            @unlink((string) $stage['path']); unset($_SESSION['sensecms_package_stages'][$token]);
            return ['type'=>$type,'slug'=>$slug,'name'=>$manifest['name'],'version'=>$version,'updated'=>(bool)$current,'active'=>false,'directory'=>$release['directory']];
        }
        $previousSource = $current ? $this->sourceArchive($current) : null;
        $target = $this->target($type, $slug);
        $workRoot = $this->privateDirectory('work') . '/' . bin2hex(random_bytes(12));
        $newPath = $workRoot . '/' . $slug;
        if (!mkdir($newPath, 0750, true) && !is_dir($newPath)) throw new RuntimeException('Package workspace could not be created.');
        $this->extractPayload((string) $stage['path'], $newPath, (array) $manifest['files']);
        $this->validateRuntimeManifest($newPath, $manifest);
        $archive = null;
        $oldPath = null;
        $committed = false;
        $active = $current ? (bool) $current['active'] : $type !== 'theme';
        if (is_dir($target)) {
            if ($previousSource === null) throw new RuntimeException('Existing package files have no verified recovery archive.');
            $archive = $previousSource;
            $oldPath = $workRoot . '/previous';
            if (!rename($target, $oldPath)) throw new RuntimeException('The existing package could not be prepared for atomic replacement.');
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true)) throw new RuntimeException('Package destination could not be created.');
        if (!rename($newPath, $target)) {
            if ($oldPath && is_dir($oldPath)) rename($oldPath, $target);
            throw new RuntimeException('The verified package could not be activated on disk.');
        }
        try {
            $this->applyMigrations($type, $slug, $version, $target, (array) ($manifest['migrations'] ?? []));
            $this->db->beginTransaction();
            $packageId = $this->upsertPackage($manifest, $inspection['checksum'], $active, $this->relative($target));
            if ($archive && $current) $this->recordRelease($packageId, $current, $this->relative($archive));
            $this->syncRuntimeState($type, $slug, $version, $active);
            $this->audit($userId, $current ? 'package.updated' : 'package.installed', 'package', $type . ':' . $slug, ['version' => $version, 'publisher' => $manifest['publisher']['name']]);
            $this->db->commit();
            $committed = true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->removeTree($target);
            if ($oldPath && is_dir($oldPath) && !rename($oldPath, $target)) throw new RuntimeException('Package recovery requires operator attention; previous files were retained in private storage.', 0, $error);
            throw new RuntimeException('Installation failed; previous files restored and database migrations retained for recovery: ' . $error->getMessage(), 0, $error);
        } finally {
            if ($committed && $oldPath && is_dir($oldPath)) $this->removeTree($oldPath);
            if (!$oldPath || !is_dir($oldPath)) $this->removeTree($workRoot);
            @unlink((string) $stage['path']);
            unset($_SESSION['sensecms_package_stages'][$token]);
        }
        return ['type' => $type, 'slug' => $slug, 'name' => $manifest['name'], 'version' => $version, 'updated' => (bool) $current];
    }

    public function rollback(string $type, string $slug, int $userId): array
    {
        return $this->locked(fn(): array => $this->rollbackLocked($type, $slug, $userId));
    }

    private function rollbackLocked(string $type, string $slug, int $userId): array
    {
        if ($type === 'theme') throw new RuntimeException('Use the installed releases in Themes to restore a verified presentation.');
        $current = $this->package($type, $slug);
        if (!$current) throw new RuntimeException('Installed package was not found.');
        $statement = $this->db->prepare('SELECT * FROM extension_package_releases WHERE package_id=? AND restored_at IS NULL ORDER BY id DESC LIMIT 1');
        $statement->execute([(int) $current['id']]);
        $release = $statement->fetch();
        if (!$release) throw new RuntimeException('No rollback release is available for this package.');
        $archive = $this->absolute((string) $release['archive_path']);
        if (!is_file($archive)) throw new RuntimeException('The rollback archive is unavailable.');
        $verified = $this->inspect($archive);
        $previousManifest = $verified['manifest'];
        if (!hash_equals((string) ($release['package_checksum'] ?? ''), $verified['checksum']) || $previousManifest['type'] !== $type || $previousManifest['slug'] !== $slug || $previousManifest['version'] !== $release['version']) throw new RuntimeException('Rollback archive identity or checksum mismatch.');
        $pending = $this->db->prepare('SELECT migration_id FROM extension_migrations WHERE package_type=? AND package_slug=? AND rolled_back_at IS NULL');
        $pending->execute([$type, $slug]);
        $retained = array_column((array) ($previousManifest['migrations'] ?? []), 'id');
        if (array_diff($pending->fetchAll(PDO::FETCH_COLUMN), $retained)) throw new RuntimeException('This release changed the database. A reviewed data-preserving downgrade migration is required before rollback.');
        $currentArchive = $this->sourceArchive($current);
        $target = $this->target($type, $slug);
        $workRoot = $this->privateDirectory('work') . '/' . bin2hex(random_bytes(12));
        $restorePath = $workRoot . '/restore';
        mkdir($restorePath, 0750, true);
        $this->extractPayload($archive, $restorePath, $previousManifest['files']);
        $this->validateRuntimeManifest($restorePath, $previousManifest);
        $oldPath = $workRoot . '/current';
        $committed = false;
        if (!rename($target, $oldPath) || !rename($restorePath, $target)) {
            if (!is_dir($target) && is_dir($oldPath)) rename($oldPath, $target);
            throw new RuntimeException('Rollback could not atomically replace the package files.');
        }
        try {
            $this->db->beginTransaction();
            $this->recordRelease((int) $current['id'], $current, $this->relative($currentArchive));
            $this->db->prepare('UPDATE extension_package_releases SET restored_at=NOW() WHERE id=?')->execute([(int) $release['id']]);
            $this->upsertPackage($previousManifest, (string) ($release['package_checksum'] ?? ''), (bool) $current['active'], $this->relative($target));
            $this->syncRuntimeState($type, $slug, (string) $previousManifest['version'], (bool) $current['active']);
            $this->audit($userId, 'package.rolled_back', 'package', $type . ':' . $slug, ['from' => $current['version'], 'to' => $previousManifest['version']]);
            $this->db->commit();
            $committed = true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->removeTree($target);
            if (!rename($oldPath, $target)) throw new RuntimeException('Rollback recovery requires operator attention; previous files were retained in private storage.', 0, $error);
            throw new RuntimeException('Rollback failed and the current release was restored: ' . $error->getMessage(), 0, $error);
        } finally {
            if ($committed && is_dir($oldPath)) $this->removeTree($oldPath);
            if (!is_dir($oldPath)) $this->removeTree($workRoot);
        }
        return ['type' => $type, 'slug' => $slug, 'name' => $previousManifest['name'], 'version' => $previousManifest['version']];
    }

    public function uninstall(string$type,string$slug,int$userId):array
    {
        return $this->locked(fn(): array => $this->uninstallLocked($type, $slug, $userId));
    }

    private function uninstallLocked(string$type,string$slug,int$userId):array
    {
        if ($type === 'theme') foreach ($this->themeManager()->releases() as $release) if ($release['slug'] === $slug) throw new RuntimeException('Signed theme releases are retained for recovery and cannot be removed through the legacy package lifecycle.');
        $current=$this->package($type,$slug);if(!$current)throw new RuntimeException('Installed package was not found.');if(($current['source']??'')!=='package')throw new RuntimeException('Bundled Base CMS components cannot be uninstalled.');if($type==='theme'&&!empty($current['active']))throw new RuntimeException('Activate another presentation theme before uninstalling this one.');
        foreach($this->packages()as$dependent){if($dependent['type']===$type&&$dependent['slug']===$slug)continue;foreach((array)($dependent['manifest']['dependencies']??[])as$dependency)if(($dependency['type']??'')===$type&&($dependency['slug']??'')===$slug)throw new RuntimeException($dependent['name'].' depends on this package and must be uninstalled first.');}
        $target=$this->target($type,$slug);if(!is_dir($target))throw new RuntimeException('Installed package files are unavailable.');$archive=$this->sourceArchive($current);$workRoot=$this->privateDirectory('work').'/'.bin2hex(random_bytes(12));if(!mkdir($workRoot,0700,true))throw new RuntimeException('Package removal workspace could not be created.');$oldPath=$workRoot.'/current';if(!rename($target,$oldPath))throw new RuntimeException('Package files could not be prepared for removal.');
        $committed = false;
        try {
            $this->db->beginTransaction();
            $this->removeRuntimeState($type, $slug);
            $this->db->prepare('DELETE FROM extension_packages WHERE id=?')->execute([(int) $current['id']]);
            $this->audit($userId, 'package.uninstalled', 'package', $type . ':' . $slug, ['version'=>$current['version'],'recovery'=>$this->relative($archive),'data_retained'=>true]);
            $this->db->commit(); $committed = true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if (!rename($oldPath, $target)) throw new RuntimeException('Uninstall recovery requires operator attention; previous files were retained in private storage.', 0, $error);
            throw new RuntimeException('Uninstallation failed and the installed package was restored.', 0, $error);
        } finally {
            if ($committed && is_dir($oldPath)) $this->removeTree($oldPath);
            if (!is_dir($oldPath)) $this->removeTree($workRoot);
        }
        return['type'=>$type,'slug'=>$slug,'name'=>$current['name'],'version'=>$current['version'],'recovery'=>$this->relative($archive)];
    }

    public function setActive(string $type, string $slug, bool $active, int $userId): void
    {
        if ($type === 'theme') throw new RuntimeException('Themes are activated from the Themes workspace.');
        $package = $this->package($type, $slug);
        if (!$package) throw new RuntimeException('Installed package was not found.');
        $this->syncRuntimeState($type, $slug, (string) $package['version'], $active);
        $this->db->prepare('UPDATE extension_packages SET active=?,updated_at=NOW() WHERE type=? AND slug=?')->execute([$active ? 1 : 0, $type, $slug]);
        $this->audit($userId, $active ? 'package.enabled' : 'package.disabled', 'package', $type . ':' . $slug, []);
    }

    public function markThemeActive(string $slug): void
    {
        $this->db->exec("UPDATE extension_packages SET active=0,updated_at=NOW() WHERE type='theme'");
        $this->db->prepare("UPDATE extension_packages SET active=1,updated_at=NOW() WHERE type='theme' AND slug=?")->execute([$slug]);
    }

    public function checkUpdates(): array
    {
        $rows = $this->db->query("SELECT id,type,slug,version,update_url,manifest FROM extension_packages WHERE source='package' AND update_url IS NOT NULL AND update_url<>'' ORDER BY id")->fetchAll();
        $privateThemes = $this->privateThemes();
        $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['type'] !== 'theme' || !isset($privateThemes[$row['slug']])));
        $available = 0;
        foreach ($rows as $row) {
            try {
                $raw = $this->fetch((string) $row['update_url'], 262144);
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                $installed=json_decode((string)($row['manifest']??''),true);$channel=(string)($installed['release_channel']??'stable');$candidates=is_array($data['releases']??null)?$data['releases']:[$data];$candidate=null;foreach($candidates as$release){if(!is_array($release)||($release['type']??'')!==$row['type']||($release['slug']??'')!==$row['slug']||($release['release_channel']??'stable')!==$channel)continue;if(!$candidate||version_compare((string)($release['version']??'0'),(string)($candidate['version']??'0'),'>'))$candidate=$release;}if(!$candidate)throw new RuntimeException('No valid release is available in the selected channel.');$version=(string)($candidate['version']??'');$url=(string)($candidate['package_url']??'');
                if (!$this->validVersion($version) || !$this->validHttpsUrl($url)) throw new RuntimeException('Update metadata is invalid.');
                $isNew = version_compare($version, (string) $row['version'], '>');
                $this->db->prepare('UPDATE extension_packages SET update_checked_at=NOW(),available_version=?,available_package_url=?,last_error=NULL WHERE id=?')->execute([$isNew ? $version : null, $isNew ? $url : null, $row['id']]);
                if ($isNew) $available++;
            } catch (Throwable $error) {
                $this->db->prepare('UPDATE extension_packages SET update_checked_at=NOW(),available_version=NULL,available_package_url=NULL,last_error=? WHERE id=?')->execute([mb_substr($error->getMessage(), 0, 500), $row['id']]);
            }
        }
        return ['checked' => count($rows), 'available' => $available];
    }

    public function updateCount(): int
    {
        return count(array_filter($this->packages(), static fn(array $row): bool => !empty($row['available_version']) && version_compare($row['available_version'], $row['installed_version'] ?? $row['version'], '>')));
    }

    private function stageFile(string $source, int $userId, bool $uploaded, bool $sourceIsStaged = false): array
    {
        if (!is_file($source) || filesize($source) < 1 || filesize($source) > self::MAX_ARCHIVE) throw new RuntimeException('The package archive is empty or exceeds 25 MB.');
        $magic = file_get_contents($source, false, null, 0, 4);
        if (!is_string($magic) || !str_starts_with($magic, "PK")) throw new RuntimeException('Only genuine ZIP archives are accepted.');
        $directory = $this->privateDirectory('staging');
        $path = $directory . '/' . bin2hex(random_bytes(18)) . '.zip';
        $stored = $uploaded ? move_uploaded_file($source, $path) : copy($source, $path);
        if (!$stored) throw new RuntimeException('The package could not be staged securely.');
        @chmod($path, 0600);
        try {
            $inspection = $this->inspect($path);
        } catch (Throwable $error) {
            @unlink($path);
            throw $error;
        }
        $token = bin2hex(random_bytes(24));
        $_SESSION['sensecms_package_stages'] ??= [];
        foreach ($_SESSION['sensecms_package_stages'] as $oldToken => $old) {
            if ((int) ($old['expires'] ?? 0) >= time()) continue;
            if (is_file((string) ($old['path'] ?? ''))) @unlink((string) $old['path']);
            unset($_SESSION['sensecms_package_stages'][$oldToken]);
        }
        $_SESSION['sensecms_package_stages'][$token] = ['path' => $path, 'checksum' => $inspection['checksum'], 'user_id' => $userId, 'expires' => time() + 1800];
        $manifest = $inspection['manifest'];
        return [
            'token' => $token,
            'type' => $manifest['type'],
            'slug' => $manifest['slug'],
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'description' => $manifest['description'] ?? '',
            'publisher' => $manifest['publisher'],
            'engine' => $manifest['engine'],
            'permissions' => array_values((array) ($manifest['permissions'] ?? [])),
            'dependencies' => array_values((array) ($manifest['dependencies'] ?? [])),
            'migrations' => count((array) ($manifest['migrations'] ?? [])),
            'files' => count((array) $manifest['files']),
            'size' => filesize($path),
            'checksum' => $inspection['checksum'],
            'signature' => 'verified',
            'release_channel'=>(string)($manifest['release_channel']??'stable'),
            'expires_at' => date(DATE_ATOM, time() + 1800),
        ];
    }

    private function stage(string $token, int $userId): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new RuntimeException('Package stage token is invalid.');
        $stage = $_SESSION['sensecms_package_stages'][$token] ?? null;
        if (!is_array($stage) || (int) ($stage['user_id'] ?? 0) !== $userId || (int) ($stage['expires'] ?? 0) < time() || !is_file((string) ($stage['path'] ?? ''))) throw new RuntimeException('The verified package stage expired. Upload the package again.');
        return $stage;
    }

    private function inspect(string $path): array
    {
        $keys = $this->trustedKeys();
        $archive = $this->archiveManifest($path, $keys); $manifest = $archive['manifest'];
        $installed = [];
        foreach ($this->packages() as $package) $installed[$package['type'] . ':' . $package['slug']] = $package['version'];
        Packages\Manifest::compatible($archive['signed'], $this->engineVersion, PHP_VERSION, $installed);
        $this->validateManifest($manifest);
        foreach ($this->packages() as $dependent) {
            foreach ((array) ($dependent['manifest']['dependencies'] ?? []) as $dependency) {
                if (($dependency['type'] ?? '') === $manifest['type'] && ($dependency['slug'] ?? '') === $manifest['slug'] && !$this->compatible((string) ($dependency['version'] ?? ''), $manifest['version'])) throw new RuntimeException('This version is incompatible with installed dependant: ' . $dependent['name']);
            }
        }
        return ['manifest'=>$manifest,'checksum'=>hash_file('sha256', $path)];
    }

    private function archiveManifest(string $path, array $keys): array
    {
        $signed = Packages\Archive::verify($path, $keys);
        $this->assertIdentity($signed['type'], $signed['slug']);
        $publisher = $this->publisher($signed['publisher']['key_id']);
        if (!$publisher || !hash_equals((string) $publisher['name'], $signed['publisher']['name'])) throw new RuntimeException('The package publisher identity does not match the trusted key.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot reopen verified package.');
        try {
            $file = $signed['type'] . '.json';
            if (!isset($signed['files'][$file])) throw new RuntimeException('The package requires a signed runtime manifest: ' . $file);
            $raw = $zip->getFromName('payload/' . $file);
            if (!is_string($raw) || strlen($raw) > 262144 || !hash_equals($signed['files'][$file], hash('sha256', $raw))) throw new RuntimeException('Invalid runtime manifest.');
            $runtime = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($runtime)) throw new RuntimeException('Invalid runtime manifest.');
            foreach (['name','slug','version'] as $field) if (($runtime[$field] ?? null) !== $signed[$field]) throw new RuntimeException('Runtime manifest identity does not match the signed package.');
        } finally { $zip->close(); }
        $range = static fn(array $value): string => '>=' . $value['min'] . ' <' . $value['max_exclusive'];
        $dependencies = [];
        foreach ($signed['dependencies'] ?? [] as $id => $constraint) {
            [$type, $slug] = explode(':', $id, 2);
            $dependencies[] = ['type'=>$type,'slug'=>$slug,'version'=>$range($constraint)];
        }
        $manifest = array_intersect_key($runtime, array_flip(['description','permissions','migrations','release_channel','changelog','config_url','icon','group','category','contract_version','supported_blocks','configuration','regions','parent','features','tags','preview_url','navigation']));
        $manifest = array_replace($manifest, $signed, ['engine'=>$range($signed['requires']['core']), 'dependencies'=>$dependencies]);
        return ['signed'=>$signed,'manifest'=>$manifest];
    }

    /** Read-only projection: the private release pointer, not a duplicate DB flag, owns theme state. */
    private function privateThemes(): array
    {
        $manager = $this->themeManager(); $releases = $manager->releases();
        if (!$releases) return [];
        $active = $manager->active(); $latest = []; $keys = $this->trustedKeys(); $rows = [];
        foreach ($releases as $release) if (!isset($latest[$release['slug']]) || version_compare($release['version'], $latest[$release['slug']]['version'], '>')) $latest[$release['slug']] = $release;
        foreach ($latest as $slug => $newest) {
            $current = ($active['slug'] ?? '') === $slug ? $active : $newest;
            $cache = $current['directory'] . ':' . hash('sha256', serialize($keys));
            if (!isset($this->themeMetadata[$cache])) {
                try {
                    $archive = $this->root . '/storage/themes/' . $current['directory'] . '/archive.zip';
                    if (is_link($archive) || !is_file($archive) || !hash_equals($current['sha256'], hash_file('sha256', $archive))) throw new RuntimeException('Installed archive integrity check failed.');
                    $manifest = $this->archiveManifest($archive, $keys)['manifest'];
                    if ($manifest['type'] !== 'theme' || $manifest['slug'] !== $slug || $manifest['version'] !== $current['version']) throw new RuntimeException('Installed archive identity mismatch.');
                    $this->themeMetadata[$cache] = ['manifest'=>$manifest,'signature_status'=>'verified','last_error'=>null];
                } catch (Throwable) {
                    $this->themeMetadata[$cache] = ['manifest'=>[],'signature_status'=>'unverified','last_error'=>'The retained archive or publisher trust could not be verified. Review the release before activation.'];
                }
            }
            $metadata = $this->themeMetadata[$cache]; $manifest = $metadata['manifest'];
            $rows[$slug] = $metadata + ['id'=>null,'type'=>'theme','slug'=>$slug,'name'=>$manifest['name'] ?? $slug,'version'=>$current['version'],
                'installed_version'=>$newest['version'],'pending_version'=>version_compare($newest['version'], $current['version'], '>') ? $newest['version'] : null,
                'description'=>$manifest['description'] ?? '', 'publisher'=>$manifest['publisher']['name'] ?? '', 'publisher_key_id'=>$manifest['publisher']['key_id'] ?? '',
                'publisher_url'=>'','source'=>'package','managed_releases'=>true,'active'=>($active['directory'] ?? '') === $current['directory'],
                'package_checksum'=>$current['sha256'],'install_path'=>'storage/themes/' . $current['directory'] . '/payload',
                'available_version'=>null,'available_package_url'=>null,'update_url'=>null,'rollback_count'=>0,'installed_at'=>null];
        }
        return $rows;
    }

    private function locked(callable $operation): array
    {
        $key = 'sensecms:packages:' . substr(hash('sha256', (string) $this->db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        $lock = $this->db->prepare('SELECT GET_LOCK(?,10)'); $lock->execute([$key]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Another package operation is in progress.');
        try { return $operation(); }
        finally { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]); }
    }

    private function preserveSource(string $source, string $checksum): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $checksum)) throw new RuntimeException('Invalid package checksum.');
        $target = $this->privateDirectory('sources') . '/' . $checksum . '.zip';
        if (is_link($target)) throw new RuntimeException('Unsafe package source archive.');
        if (!is_file($target) && !copy($source, $target)) throw new RuntimeException('Cannot preserve signed recovery archive.');
        if (!hash_equals($checksum, hash_file('sha256', $target)) || !chmod($target, 0600)) throw new RuntimeException('Recovery archive integrity check failed.');
    }

    private function sourceArchive(array $package): string
    {
        $checksum = (string) ($package['package_checksum'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $checksum)) throw new RuntimeException('Package has no signed recovery archive; a controlled migration is required.');
        $path = $this->privateDirectory('sources') . '/' . $checksum . '.zip';
        $verified = $this->inspect($path);
        if (!hash_equals($checksum, $verified['checksum'])) throw new RuntimeException('Recovery archive checksum mismatch.');
        foreach (['type','slug','version'] as $field) if ($verified['manifest'][$field] !== $package[$field]) throw new RuntimeException('Recovery archive identity mismatch.');
        return $path;
    }

    private function validateManifest(array $manifest): void
    {
        foreach (['schema', 'type', 'slug', 'name', 'version', 'engine', 'publisher', 'files'] as $required) if (!array_key_exists($required, $manifest)) throw new RuntimeException('Package manifest is missing ' . $required . '.');
        if ((int) $manifest['schema'] !== 1) throw new RuntimeException('Unsupported SenseCMS package schema.');
        $this->assertIdentity((string) $manifest['type'], (string) $manifest['slug']);
        if (mb_strlen(trim((string) $manifest['name'])) < 2 || mb_strlen((string) $manifest['name']) > 150) throw new RuntimeException('Package name is invalid.');
        if (!$this->validVersion((string) $manifest['version'])) throw new RuntimeException('Package version must use semantic versioning.');
        if (!$this->compatible((string) $manifest['engine'], $this->engineVersion)) throw new RuntimeException('This package is not compatible with SenseCMS ' . $this->engineVersion . '.');
        if (!is_array($manifest['publisher']) || !preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', (string) ($manifest['publisher']['key_id'] ?? '')) || trim((string) ($manifest['publisher']['name'] ?? '')) === '') throw new RuntimeException('Package publisher metadata is invalid.');
        if (isset($manifest['publisher']['website']) && $manifest['publisher']['website'] !== '' && !$this->validHttpsUrl((string) $manifest['publisher']['website'])) throw new RuntimeException('Publisher website must use HTTPS.');
        if (!is_array($manifest['files']) || !$manifest['files'] || count($manifest['files']) > self::MAX_FILES) throw new RuntimeException('The signed package file list is invalid.');
        foreach (array_keys($manifest['files']) as $file) $this->assertPayloadPath((string) $file);
        foreach ((array) ($manifest['permissions'] ?? []) as $permission) if (!is_string($permission) || !preg_match('/^[a-z][a-z0-9.-]{1,119}$/', $permission)) throw new RuntimeException('A declared package permission is invalid.');
        foreach ((array) ($manifest['dependencies'] ?? []) as $dependency) {
            if (!is_array($dependency) || !in_array($dependency['type'] ?? '', self::TYPES, true) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) ($dependency['slug'] ?? '')) || trim((string) ($dependency['version'] ?? '')) === '') throw new RuntimeException('A package dependency declaration is invalid.');
        }
        $migrationIds = [];
        foreach ((array) ($manifest['migrations'] ?? []) as $migration) {
            if (!is_array($migration) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,119}$/', (string) ($migration['id'] ?? '')) || !isset($migration['up'], $migration['down'])) throw new RuntimeException('Every migration requires an ID and reversible up/down SQL files.');
            if (isset($migrationIds[$migration['id']])) throw new RuntimeException('Duplicate package migration ID.');
            $migrationIds[$migration['id']] = true;
            $this->assertPayloadPath((string) $migration['up']);
            $this->assertPayloadPath((string) $migration['down']);
            if (!isset($manifest['files'][$migration['up']], $manifest['files'][$migration['down']])) throw new RuntimeException('Migration SQL files must be part of the signed file list.');
        }
        if (isset($manifest['update_url']) && $manifest['update_url'] !== '' && !$this->validHttpsUrl((string) $manifest['update_url'])) throw new RuntimeException('Package update metadata URL must use HTTPS.');
        if(!in_array((string)($manifest['release_channel']??'stable'),['stable','beta','preview','development'],true))throw new RuntimeException('Package release channel is invalid.');
        if(isset($manifest['license'])&&!is_array($manifest['license']))throw new RuntimeException('Package license metadata is invalid.');
        foreach((array)($manifest['changelog']??[])as$release)if(!is_array($release)||!$this->validVersion((string)($release['version']??''))||mb_strlen(trim((string)($release['summary']??'')))<3)throw new RuntimeException('Package changelog metadata is invalid.');
    }

    private function validateRuntimeManifest(string $path, array $package): void
    {
        $name = ['theme' => 'theme.json', 'plugin' => 'plugin.json', 'addon' => 'addon.json'][(string) $package['type']];
        $file = $path . '/' . $name;
        if (!is_file($file)) throw new RuntimeException('The package payload is missing ' . $name . '.');
        $runtime = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        foreach (['slug', 'name', 'version'] as $field) if ((string) ($runtime[$field] ?? '') !== (string) $package[$field]) throw new RuntimeException('Runtime manifest ' . $field . ' does not match the signed package.');
        if((string)$package['type']==='theme'){$runtime['_path']=$path;ThemeContract::validateRuntime($runtime);$parent=(string)($runtime['parent']??'');if($parent!==''){$declared=false;foreach((array)($package['dependencies']??[])as$dependency)if(($dependency['type']??'')==='theme'&&($dependency['slug']??'')===$parent){$declared=true;break;}if(!$declared)throw new RuntimeException('A child theme must declare its parent theme as a signed package dependency.');}}
    }

    private function extractPayload(string $archive, string $destination, array $files): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new RuntimeException('The verified package cannot be reopened.');
        try {
            foreach ($files as $relative => $_hash) {
                $this->assertPayloadPath((string) $relative);
                $target = $destination . '/' . $relative;
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('Package directory could not be created.');
                $input = $zip->getStream('payload/' . $relative);
                $output = fopen($target, 'wb');
                if (!$input || !$output) throw new RuntimeException('Package file could not be extracted.');
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if (!hash_equals((string) $files[$relative], hash_file('sha256', $target))) throw new RuntimeException('Extracted package file integrity mismatch.');
                chmod($target, 0640);
            }
        } finally {
            $zip->close();
        }
    }



    private function upsertPackage(array $manifest, string $checksum, bool $active, string $installPath): int
    {
        $publisher = (array) $manifest['publisher'];
        $bundled=(int)($manifest['schema']??1)===0;$source=$bundled?'bundled':'package';$signature=$bundled?'distribution':'verified';$keyId=$bundled?null:($publisher['key_id']??null);
        $statement = $this->db->prepare("INSERT INTO extension_packages (type,slug,name,version,description,publisher,publisher_url,publisher_key_id,source,signature_status,active,manifest,package_checksum,install_path,update_url,installed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),version=VALUES(version),description=VALUES(description),publisher=VALUES(publisher),publisher_url=VALUES(publisher_url),publisher_key_id=VALUES(publisher_key_id),source=VALUES(source),signature_status=VALUES(signature_status),active=VALUES(active),manifest=VALUES(manifest),package_checksum=VALUES(package_checksum),install_path=VALUES(install_path),update_url=VALUES(update_url),available_version=NULL,available_package_url=NULL,last_error=NULL,updated_at=NOW()");
        $updateUrl = $manifest['update_url'] ?? $this->officialUpdateUrl((string) $manifest['type'], (string) $manifest['slug'], (string) $keyId);
        $statement->execute([$manifest['type'], $manifest['slug'], $manifest['name'], $manifest['version'], $manifest['description'] ?? '', $publisher['name'], $publisher['website'] ?? null, $keyId, $source, $signature, $active ? 1 : 0, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $checksum ?: null, $installPath, $updateUrl]);
        $find = $this->db->prepare('SELECT id FROM extension_packages WHERE type=? AND slug=?');
        $find->execute([$manifest['type'], $manifest['slug']]);
        return (int) $find->fetchColumn();
    }

    private function recordRelease(int $packageId, array $package, string $archive): void
    {
        $manifest = is_array($package['manifest'] ?? null) ? $package['manifest'] : [];
        $this->db->prepare('INSERT INTO extension_package_releases (package_id,version,manifest,archive_path,package_checksum,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$packageId, $package['version'], json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $archive, $package['package_checksum'] ?? null]);
    }

    private function applyMigrations(string $type, string $slug, string $version, string $path, array $migrations): void
    {
        foreach ($migrations as $migration) {
            $upHash = hash_file('sha256', $path . '/' . $migration['up']);
            $downHash = hash_file('sha256', $path . '/' . $migration['down']);
            $check = $this->db->prepare('SELECT rolled_back_at,up_checksum,down_checksum FROM extension_migrations WHERE package_type=? AND package_slug=? AND migration_id=?');
            $check->execute([$type, $slug, $migration['id']]);
            $row = $check->fetch();
            if ($row && (!hash_equals((string) $row['up_checksum'], $upHash) || !hash_equals((string) $row['down_checksum'], $downHash))) throw new RuntimeException('Applied migration changed or has no verified checksum: ' . $migration['id']);
            if ($row && $row['rolled_back_at'] === null) continue;
            $this->executeSql($path . '/' . $migration['up']);
            $statement = $this->db->prepare('INSERT INTO extension_migrations (package_type,package_slug,migration_id,package_version,down_file,up_checksum,down_checksum,applied_at,rolled_back_at) VALUES (?,?,?,?,?,?,?,NOW(),NULL) ON DUPLICATE KEY UPDATE package_version=VALUES(package_version),down_file=VALUES(down_file),up_checksum=VALUES(up_checksum),down_checksum=VALUES(down_checksum),applied_at=NOW(),rolled_back_at=NULL');
            $statement->execute([$type, $slug, $migration['id'], $version, $migration['down'], $upHash, $downHash]);
        }
    }


    private function executeSql(string $file): void
    {
        if (!is_file($file) || filesize($file) > 2097152) throw new RuntimeException('Signed migration SQL is missing or too large.');
        $sql = (string) file_get_contents($file);
        if (preg_match('/\b(?:GRANT|REVOKE|CREATE\s+USER|ALTER\s+USER|DROP\s+USER|LOAD_FILE|INTO\s+OUTFILE|INTO\s+DUMPFILE|SHUTDOWN)\b/i', $sql)) throw new RuntimeException('Migration contains a prohibited server-level SQL operation.');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $sql) ?: [])) as $statement) $this->db->exec($statement);
    }

    private function syncRuntimeState(string $type, string $slug, string $version, bool $active): void
    {
        if ($type === 'plugin') {
            $this->db->prepare('INSERT INTO installed_plugins (slug,version,active,settings,installed_at) VALUES (?,?,?,JSON_OBJECT(),NOW()) ON DUPLICATE KEY UPDATE version=VALUES(version),active=VALUES(active)')->execute([$slug, $version, $active ? 1 : 0]);
        } elseif ($type === 'theme') {
            $this->db->prepare('INSERT INTO installed_themes (slug,version,active,settings,installed_at) VALUES (?,?,?,JSON_OBJECT(),NOW()) ON DUPLICATE KEY UPDATE version=VALUES(version),active=VALUES(active)')->execute([$slug, $version, $active ? 1 : 0]);
        } else {
            $statement = $this->db->prepare("SELECT value FROM settings WHERE `key`='extension_states' LIMIT 1");
            $statement->execute();
            $states = json_decode((string) ($statement->fetchColumn() ?: '{}'), true);
            if (!is_array($states)) $states = [];
            $states[$slug] = $active;
            $this->db->prepare("INSERT INTO settings (`key`,value) VALUES ('extension_states',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([json_encode($states, JSON_UNESCAPED_SLASHES)]);
        }
    }

    private function removeRuntimeState(string$type,string$slug):void
    {
        if($type==='plugin')$this->db->prepare('DELETE FROM installed_plugins WHERE slug=?')->execute([$slug]);
        elseif($type==='theme')$this->db->prepare('DELETE FROM installed_themes WHERE slug=? AND active=0')->execute([$slug]);
        else{$statement=$this->db->prepare("SELECT value FROM settings WHERE `key`='extension_states' LIMIT 1");$statement->execute();$states=json_decode((string)($statement->fetchColumn()?:'{}'),true);if(!is_array($states))$states=[];unset($states[$slug]);$this->db->prepare("INSERT INTO settings (`key`,value) VALUES ('extension_states',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([json_encode($states,JSON_UNESCAPED_SLASHES)]);}
    }

    private function syncOne(string $type, string $slug, array $manifest, bool $active, ?string $installPath): void
    {
        $existing = $this->package($type, $slug);
        if ($existing && $existing['source'] === 'package') return;
        $name = (string) ($manifest['name'] ?? $slug);
        $version = (string) ($manifest['version'] ?? '1.0.0');
        $author = (string) ($manifest['author'] ?? $manifest['publisher'] ?? 'QUANT Software House');
        $description = (string) ($manifest['description'] ?? '');
        $stored = $manifest + ['schema' => 0, 'type' => $type, 'slug' => $slug, 'name' => $name, 'version' => $version, 'description' => $description, 'engine' => '*'];
        $stored['publisher'] = ['name' => $author, 'key_id' => 'distribution'];
        $stored['files'] = [];
        $updateUrl = $this->officialUpdateUrl($type, $slug, 'distribution');
        $statement = $this->db->prepare("INSERT INTO extension_packages (type,slug,name,version,description,publisher,source,signature_status,active,manifest,install_path,update_url,installed_at,updated_at) VALUES (?,?,?,?,?,?,'bundled','distribution',?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),version=VALUES(version),description=VALUES(description),publisher=VALUES(publisher),active=VALUES(active),manifest=VALUES(manifest),install_path=VALUES(install_path),update_url=COALESCE(NULLIF(update_url,''),VALUES(update_url)),updated_at=NOW()");
        $statement->execute([$type, $slug, $name, $version, $description, $author, $active ? 1 : 0, json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $installPath, $updateUrl]);
    }

    private function assertDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $installed = $this->package((string) $dependency['type'], (string) $dependency['slug']);
            if (!$installed || !$this->compatible((string) $dependency['version'], (string) $installed['version'])) throw new RuntimeException('Missing compatible dependency: ' . $dependency['type'] . ':' . $dependency['slug'] . ' ' . $dependency['version'] . '.');
        }
    }

    private function compatible(string $constraint, string $version): bool
    {
        $constraint = trim($constraint);
        if (preg_match('/^>=(\d+\.\d+\.\d+) <(\d+\.\d+\.\d+)$/D', $constraint, $range)) return version_compare($version, $range[1], '>=') && version_compare($version, $range[2], '<');
        if ($constraint === '' || $constraint === '*') return true;
        if (str_starts_with($constraint, '^')) {
            $base = substr($constraint, 1);
            if (!$this->validVersion($base)) return false;
            $parts = array_map('intval', explode('.', $base));
            $upper = $parts[0] > 0 ? ($parts[0] + 1) . '.0.0' : '0.' . (($parts[1] ?? 0) + 1) . '.0';
            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }
        if (str_starts_with($constraint, '~')) {
            $base = substr($constraint, 1);
            if (!$this->validVersion($base)) return false;
            $parts = array_map('intval', explode('.', $base));
            $upper = $parts[0] . '.' . (($parts[1] ?? 0) + 1) . '.0';
            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }
        if (preg_match('/^(>=|<=|>|<|=)?\s*(\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?)$/', $constraint, $match)) return version_compare($version, $match[2], $match[1] ?: '=');
        return version_compare($version, $constraint, '=');
    }

    private function fetch(string $url, int $limit): string
    {
        $buffer = '';
        $this->curl($url, static function (string $chunk) use (&$buffer, $limit): int {
            if (strlen($buffer) + strlen($chunk) > $limit) return 0;
            $buffer .= $chunk;
            return strlen($chunk);
        });
        return $buffer;
    }

    private function download(string $url, string $path, int $limit,array$headers=[]): void
    {
        $handle = fopen($path, 'wb');
        if (!$handle) throw new RuntimeException('Update package staging file could not be opened.');
        $size = 0;
        try {
            $this->curl($url, static function (string $chunk) use ($handle, &$size, $limit): int {
                $size += strlen($chunk);
                if ($size > $limit) return 0;
                return fwrite($handle, $chunk);
            },$headers);
        } finally {
            fclose($handle);
        }
        if ($size < 1 || $size > $limit) { @unlink($path); throw new RuntimeException('Downloaded update package is empty or too large.'); }
        chmod($path, 0600);
    }

    private function curl(string $url, callable $writer,array$headers=[]): void
    {
        if (!$this->validHttpsUrl($url)) throw new RuntimeException('Remote package URLs must use HTTPS.');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A);
        if (!$records) throw new RuntimeException('Update host has no IPv4 address.');
        $addresses = array_values(array_unique(array_filter(array_column($records, 'ip'))));
        foreach ($addresses as $address) if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('Update host resolves to a private or reserved address.');
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $curl = curl_init($url);
        $options = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'SenseCMS/' . $this->engineVersion, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $addresses[0]], CURLOPT_WRITEFUNCTION => static fn($handle, string $chunk): int => $writer($chunk)];
        if($headers)$options[CURLOPT_HTTPHEADER]=array_values(array_map('strval',$headers));elseif ($this->marketplaceRequest($url) && !empty($this->marketplace['headers'])) $options[CURLOPT_HTTPHEADER] = array_values(array_map('strval', (array) $this->marketplace['headers']));
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($ok === false || $status < 200 || $status >= 300) throw new RuntimeException($error !== '' ? 'Update request failed: ' . $error : 'Update endpoint returned HTTP ' . $status . '.');
    }

    private function officialUpdateUrl(string $type, string $slug, string $keyId): ?string
    {
        $base = rtrim((string) ($this->marketplace['base_url'] ?? ''), '/');
        if ($base === '' || $type !== 'theme' || $slug !== 'sensecms' || !in_array($keyId, ['distribution', 'sensecms.official'], true)) return null;
        return $base . '/catalog/?type=theme&slug=sensecms';
    }

    private function marketplaceRequest(string $url): bool
    {
        $base = (string) ($this->marketplace['base_url'] ?? '');
        if (!$this->validHttpsUrl($base) || !$this->validHttpsUrl($url)) return false;
        $origin = static fn(string $value): string => strtolower((string) parse_url($value, PHP_URL_SCHEME)) . '://' . strtolower((string) parse_url($value, PHP_URL_HOST)) . ':' . (int) (parse_url($value, PHP_URL_PORT) ?: 443);
        return hash_equals($origin($base), $origin($url));
    }

    private function publisher(string $keyId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM extension_publishers WHERE key_id=? LIMIT 1');
        $statement->execute([$keyId]);
        return $statement->fetch() ?: null;
    }

    private function decodePackage(array $row): array
    {
        $row['active'] = (bool) $row['active'];
        $row['rollback_count'] = (int) ($row['rollback_count'] ?? 0);
        $row['manifest'] = json_decode((string) ($row['manifest'] ?? '{}'), true) ?: [];
        return $row;
    }

    private function target(string $type, string $slug): string
    {
        $this->assertIdentity($type, $slug);
        return $this->root . '/' . ['theme' => 'themes', 'plugin' => 'plugins', 'addon' => 'addons'][$type] . '/' . $slug;
    }


    private function privateDirectory(string $name): string
    {
        if (!in_array($name, ['staging', 'work', 'backups', 'sources'], true)) throw new RuntimeException('Invalid package storage directory.');
        $path = $this->root . '/storage/packages/' . $name;
        if (!is_dir($path) && !mkdir($path, 0700, true)) throw new RuntimeException('Private package storage is unavailable.');
        return $path;
    }

    private function relative(string $path): string
    {
        $root = str_replace('\\', '/', rtrim($this->root, '/\\')) . '/';
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, $root)) throw new RuntimeException('Package path is outside the application root.');
        return substr($normalized, strlen($root));
    }

    private function absolute(string $path): string
    {
        if (str_contains($path, '..') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) throw new RuntimeException('Stored package path is invalid.');
        return $this->root . '/' . str_replace('\\', '/', $path);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $resolved = realpath($path);
        if (!$resolved) return;
        $normalized = str_replace('\\', '/', $resolved) . '/';
        $allowed = false;
        foreach ([$this->root . '/storage/packages/work', $this->root . '/themes', $this->root . '/plugins', $this->root . '/addons'] as $base) {
            $base = realpath($base) ?: $base;
            $prefix = str_replace('\\', '/', rtrim($base, '/\\')) . '/';
            if ($normalized !== $prefix && str_starts_with($normalized, $prefix)) { $allowed = true; break; }
        }
        if (!$allowed) throw new RuntimeException('Refusing to remove a directory outside managed package storage.');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($resolved);
    }

    private function assertArchivePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) || in_array('..', explode('/', $path), true) || str_contains($path, '//')) throw new RuntimeException('The package contains an unsafe archive path.');
    }

    private function assertPayloadPath(string $path): void
    {
        $this->assertArchivePath($path);
        if (str_ends_with($path, '/') || preg_match('#(^|/)(?:\.htaccess|\.user\.ini|\.env|web\.config)$#i', $path) || preg_match('/\.(?:phar|exe|dll|so|dylib|sh|bat|cmd|ps1)$/i', $path)) throw new RuntimeException('The package contains a prohibited payload file: ' . $path . '.');
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) throw new RuntimeException('Unsupported package type.');
    }

    private function assertIdentity(string $type, string $slug): void
    {
        $this->assertType($type);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 100) throw new RuntimeException('Package slug is invalid.');
    }

    private function validVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $version);
    }

    private function validHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null;
    }

    private function audit(int $userId, string $event, string $type, string $subject, array $context): void
    {
        $subjectId = ctype_digit($subject) ? (int) $subject : null;
        $context['subject'] = $subject;
        $this->db->prepare('INSERT INTO activity_log (user_id,event,subject_type,subject_id,context,created_at) VALUES (?,?,?,?,?,NOW())')->execute([$userId ?: null, $event, $type, $subjectId, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}
