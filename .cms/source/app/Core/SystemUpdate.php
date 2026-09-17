<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Signed Stable release checks; archive installation remains operator-managed. */
final class SystemUpdate
{
    private const UNAVAILABLE = 'Automatic Core updates are not available for this development release. Use a tested operator deployment with backups; the web installer is only for new empty installations.';

    // Keep the existing constructor and request API for panel and operator callers.
    public function __construct(PDO $db, private readonly string $root, array $config, private readonly ?\Closure $fetch = null) {}

    public function version(): string
    {
        $product = require $this->root . '/config/product.php';
        $version = $product['core_version'] ?? null;
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) throw new RuntimeException('The installed Core version is invalid.');
        return $version;
    }

    public function status(): array
    {
        $catalog = []; $error = null;
        $dir = $this->root . '/storage/system-updates';
        $path = $dir . '/catalog.json';
        // Existing verified extension offers remain separate from Core installation.
        try {
            if (is_link($dir) || is_link($path)) throw new RuntimeException('Unsafe catalog path.');
            if (is_file($path)) {
                if (filesize($path) > 5242880) throw new RuntimeException('Catalog exceeds its limit.');
                $catalog = OfficialCatalog::verify((string)file_get_contents($path));
            }
        } catch (Throwable) { $error = 'The saved official catalog could not be verified. No release is offered from it.'; }
        $latest = []; $verified = false; $state = []; $releases = []; $configured = false;
        try {
            $runtime = new Runtime($this->root);
            $key = (string)($runtime->read('update-trust')['public_key'] ?? '');
            $configured = strlen((string)base64_decode($key, true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
            $state = $runtime->read('core-update-state');
            $saved = $runtime->read('core-update-catalog');
            if ($saved) {
                $data = CoreReleases::verify(json_encode($saved, JSON_THROW_ON_ERROR), $key);
                $releases = $data['releases']; $latest = $releases[0] ?? [];
                $verified = empty($state['error']);
            }
            if (!empty($state['error'])) $error = 'The latest release check failed. Retry later; the installation is not certified up to date.';
        } catch (Throwable) { $error = 'The saved release catalogue is invalid or expired. Check again before relying on release status.'; }
        if (!$configured) $error = 'The official release verification key must be provisioned by your installation operator.';
        return ['version'=>$this->version(), 'supported'=>$configured, 'install_supported'=>false, 'reason'=>self::UNAVAILABLE,
            'latest'=>$latest, 'releases'=>$releases, 'available'=>$verified && $latest && version_compare($latest['version'], $this->version(), '>'),
            'compatible'=>!$latest || version_compare(PHP_VERSION, $latest['php_min'], '>='),
            'verified'=>$verified, 'checked_at'=>$state['checked_at'] ?? null, 'attempted_at'=>$state['attempted_at'] ?? null,
            'error'=>$error, 'job'=>[], 'products'=>$catalog['products'] ?? [], 'worker_at'=>$state['worker_at'] ?? null];
    }

    public function requestCheck(): void
    {
        $runtime = new Runtime($this->root);
        $path = $this->root . '/storage/core-update.lock';
        if (is_link($path)) throw new RuntimeException('Unsafe release check lock.', 503);
        $lock = fopen($path, 'c');
        if (!$lock) throw new RuntimeException('Release check storage is unavailable.', 503);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('A release check is already running.', 429); }
        try {
            $state = $runtime->read('core-update-state');
            if (($state['attempted_at'] ?? 0) > time() - 60) {
                if (!empty($state['error'])) throw new RuntimeException('The last check failed. Retry in one minute.', 503);
                return;
            }
            $state['attempted_at'] = time();
            try {
                $key = (string)($runtime->read('update-trust')['public_key'] ?? '');
                if (strlen((string)base64_decode($key, true)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new RuntimeException('Missing release trust.');
                $raw = $this->fetch ? ($this->fetch)() : CoreReleases::download();
                $data = CoreReleases::verify($raw, $key);
                $old = $runtime->read('core-update-catalog');
                if ($old) {
                    // Reject rollback of signed metadata even if the former envelope expired.
                    $previous = json_decode((string)base64_decode((string)($old['signed_payload'] ?? ''), true), true);
                    if (($previous['issued_at'] ?? 0) > $data['issued_at']) throw new RuntimeException('Release metadata rollback refused.');
                }
                $runtime->write('core-update-catalog', json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
                $state['checked_at'] = time(); $state['error'] = false;
                $runtime->write('core-update-state', $state);
            } catch (Throwable) {
                $state['error'] = true; $runtime->write('core-update-state', $state);
                throw new RuntimeException('Release check failed. No update has been installed; check service availability and publisher trust.', 503);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function checkIfDue(): void
    {
        try {
            $runtime = new Runtime($this->root); $state = $runtime->read('core-update-state');
            if (($state['attempted_at'] ?? 0) <= time() - (!empty($state['error']) ? 3600 : 21600)) $this->requestCheck();
        } catch (Throwable) { /* Persisted check failures remain visible on the Update screen. */ }
    }
    public function requestInstall(string $version, int $userId): void { throw new RuntimeException(self::UNAVAILABLE, 503); }

    // Fail closed for old scripts and persisted queued jobs: never run legacy DDL.
    public function run(): array { throw new RuntimeException(self::UNAVAILABLE, 503); }
    public function verifyArchive(string $archive, array $release): array { throw new RuntimeException(self::UNAVAILABLE, 503); }
    public static function allowed(string $path): bool { return false; }
}
