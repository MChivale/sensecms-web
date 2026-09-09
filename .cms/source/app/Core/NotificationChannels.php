<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class NotificationChannels
{
    private Secrets $secrets;

    public function __construct(private readonly PDO $db, private readonly string $root, string $secret)
    {
        $this->secrets = new Secrets($secret);
    }

    public function catalog(bool $includeValues = false): array
    {
        $statement = $this->db->query("SELECT slug,install_path FROM extension_packages WHERE type='plugin' AND active=1 AND install_path IS NOT NULL ORDER BY name,slug");
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $slug = (string) $row['slug'];
            $path = $this->path((string) $row['install_path']);
            if (!$path) continue;
            $manifestFile = $path . '/plugin.json';
            $manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
            $integration = is_array($manifest['notification_integration'] ?? null) ? $manifest['notification_integration'] : null;
            if (!$integration || !in_array($integration['kind'] ?? '', ['notification'], true)) continue;
            $setting = $this->setting($slug);
            $fields = [];
            foreach ((array) ($integration['fields'] ?? []) as $field) {
                if (!is_array($field) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string) ($field['name'] ?? ''))) continue;
                $name = (string) $field['name'];
                $fields[] = [
                    'name' => $name,
                    'label' => mb_substr((string) ($field['label'] ?? $name), 0, 100),
                    'type' => in_array($field['type'] ?? '', ['text', 'password', 'url', 'email'], true) ? $field['type'] : 'text',
                    'required' => !empty($field['required']),
                    'value' => $includeValues && empty($field['secret']) ? (string) ($setting['values'][$name] ?? '') : '',
                    'configured' => array_key_exists($name, $setting['values']) && (string) $setting['values'][$name] !== '',
                ];
            }
            $delivery=in_array($integration['delivery']??'', ['central'],true)?(string)$integration['delivery']:'provider';
            $result[] = ['slug' => $slug, 'name' => (string) ($manifest['name'] ?? $slug), 'description' => (string) ($manifest['description'] ?? ''), 'kind' => $integration['kind'], 'delivery'=>$delivery, 'icon' => (string) ($manifest['icon'] ?? 'plug'), 'enabled' => $setting['enabled'], 'verified_at' => $setting['verified_at'], 'last_error' => $setting['last_error'], 'consent_confirmed' => !empty($setting['values']['consent_confirmed']), 'fields' => $fields];
        }
        return $result;
    }

    public function save(string $slug, array $input): array
    {
        [$manifest, $path] = $this->manifest($slug);
        $integration = (array) $manifest['notification_integration'];
        $current = $this->setting($slug)['values'];
        $enabled = !empty($input['enabled']);
        $values = array_intersect_key($current, ['waba_id' => true, 'business_id' => true]);
        $values['consent_confirmed'] = !empty($input['consent_confirmed']);
        foreach ((array) ($integration['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) continue;
            $value = trim((string) ($input[$name] ?? ''));
            if ($value === '' && !empty($field['secret']) && isset($current[$name])) $value = (string) $current[$name];
            if ($enabled && !empty($field['required']) && $value === '') throw new RuntimeException((string) ($field['label'] ?? $name) . ' is required.');
            if (strlen($value) > 4096 || str_contains($value, "\0")) throw new RuntimeException('An integration setting is invalid.');
            if (!empty($field['secret']) && preg_match('/[\r\n]/',$value)) throw new RuntimeException('Credentials must not contain line breaks.');
            if (($field['type'] ?? '') === 'url' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($value), 'https://'))) throw new RuntimeException((string) ($field['label'] ?? $name) . ' must use a valid HTTPS URL.');
            if (($field['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new RuntimeException((string) ($field['label'] ?? $name) . ' must be a valid email address.');
            $values[$name] = $value;
        }
        if ($enabled && empty($input['consent_confirmed'])) throw new RuntimeException('Confirm that every recipient has opted in to system notifications.');
        if ($enabled && $integration['kind'] === 'notification' && ($integration['delivery']??'provider') !== 'central') {
            $recipients = json_decode((string) ($values['recipient_map'] ?? ''), true);
            if (!is_array($recipients) || !$recipients || count($recipients)>100) throw new RuntimeException('Provide a JSON map of user IDs to their consented notification recipients (maximum 100).');
            $check = $this->db->prepare('SELECT 1 FROM users WHERE id=? AND active=1 AND is_demo=0');
            foreach ($recipients as $userId => $recipient) {
                if (!ctype_digit((string)$userId) || !is_string($recipient) || !preg_match($slug==='telegram-notifications' ? '/^[1-9][0-9]{4,19}$/D' : '/^[1-9][0-9]{6,14}$/D', $recipient)) throw new RuntimeException('Recipient map contains an invalid user or private recipient identifier.');
                $check->execute([(int)$userId]); if (!$check->fetchColumn()) throw new RuntimeException('Recipient map contains an unavailable user.');
            }
        }
        $verified = null; $error = null;
        if ($enabled) {
            try {
                if (($integration['delivery']??'provider') === 'central') {
                    if ($slug!=='telegram-notifications') throw new RuntimeException('Unsupported central notification channel.');
                    $runtime=new Runtime($this->root);
                    (new TelegramConnectionClient($runtime->license(),['base_url'=>'https://www.sensecms.com/api/telegram/v1'],$runtime->baseUrl()))->recipients();
                } else { $provider = $this->provider($manifest, $path); if (method_exists($provider, 'verify')) $provider->verify($values); }
                $verified = gmdate('Y-m-d H:i:s');
            }
            catch (Throwable $exception) { throw new RuntimeException('Integration verification failed. Check the provider credentials, permissions and destination.'); }
        }
        $encrypted = $this->secrets->encrypt(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $statement = $this->db->prepare('INSERT INTO notification_channel_settings (plugin_slug,subject_type,subject_id,encrypted_settings,enabled,last_verified_at,last_error,updated_at) VALUES (?,"installation",0,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE encrypted_settings=VALUES(encrypted_settings),enabled=VALUES(enabled),last_verified_at=VALUES(last_verified_at),last_error=VALUES(last_error),updated_at=UTC_TIMESTAMP()');
        $statement->execute([$slug, $encrypted, $enabled ? 1 : 0, $verified, $error]);
        return ['slug' => $slug, 'enabled' => $enabled, 'verified_at' => $verified];
    }

    public function provisionWhatsApp(array $credentials): void
    {
        $slug = 'whatsapp-notifications'; $this->manifest($slug); $token = (string) ($credentials['access_token'] ?? ''); $phone = (string) ($credentials['phone_number_id'] ?? ''); $waba = (string) ($credentials['waba_id'] ?? ''); $business = (string) ($credentials['business_id'] ?? '');
        if (strlen($token) < 20 || strlen($token) > 4096 || preg_match('/[\r\n]/', $token) || !preg_match('/^[0-9]{5,30}$/D', $phone) || !preg_match('/^[0-9]{5,30}$/D', $waba) || ($business !== '' && !preg_match('/^[0-9]{5,30}$/D', $business)) || (string) ($credentials['api_version'] ?? '') !== 'v26.0') throw new RuntimeException('The WhatsApp credentials are invalid.');
        $values = $this->setting($slug)['values']; $values['access_token'] = $token; $values['phone_number_id'] = $phone; $values['waba_id'] = $waba; $values['business_id'] = $business; $values['api_version'] = 'v26.0';
        $encrypted = $this->secrets->encrypt(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $statement = $this->db->prepare('INSERT INTO notification_channel_settings (plugin_slug,subject_type,subject_id,encrypted_settings,enabled,last_verified_at,last_error,updated_at) VALUES (? ,"installation",0,?,0,NULL,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE encrypted_settings=VALUES(encrypted_settings),enabled=0,last_verified_at=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP()');
        $statement->execute([$slug, $encrypted]);
    }

    public function telegramProfilePhoto(): ?array
    {
        [$provider, $settings] = $this->telegramProvider();
        if (!method_exists($provider, 'profilePhoto')) throw new RuntimeException('The installed Telegram integration does not support bot profile management.');
        return $provider->profilePhoto($settings);
    }

    public function setTelegramProfilePhoto(array $file): void
    {
        [$provider, $settings] = $this->telegramProvider();
        if (!method_exists($provider, 'setProfilePhoto')) throw new RuntimeException('The installed Telegram integration does not support bot profile management.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? '')) || (int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 2_000_000) throw new RuntimeException('Upload a valid PNG, JPG or WebP image smaller than 2 MB.');
        $sourceFile = (string) $file['tmp_name'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourceFile);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) throw new RuntimeException('Only PNG, JPG or WebP bot profile images are accepted.');
        $size = @getimagesize($sourceFile);
        if (!$size || min((int) $size[0], (int) $size[1]) < 128 || max((int) $size[0], (int) $size[1]) > 4096 || !function_exists('imagecreatefromstring')) throw new RuntimeException('Use an image between 128 and 4096 pixels on each side.');
        $source = @imagecreatefromstring((string) file_get_contents($sourceFile));
        if (!$source) throw new RuntimeException('The bot profile image could not be processed.');
        $edge = min(imagesx($source), imagesy($source)); $x = (imagesx($source) - $edge) / 2; $y = (imagesy($source) - $edge) / 2;
        $avatar = imagecreatetruecolor(640, 640); $white = imagecolorallocate($avatar, 255, 255, 255); imagefill($avatar, 0, 0, $white); imagecopyresampled($avatar, $source, 0, 0, (int) $x, (int) $y, 640, 640, $edge, $edge);
        $directory = $this->root . '/storage/tmp';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) { imagedestroy($avatar); imagedestroy($source); throw new RuntimeException('Temporary image storage is unavailable.'); }
        $temporary = tempnam($directory, 'telegram-profile-');
        if (!is_string($temporary)) { imagedestroy($avatar); imagedestroy($source); throw new RuntimeException('The bot profile image could not be prepared.'); }
        try {
            if (!imagejpeg($avatar, $temporary, 90)) throw new RuntimeException('The bot profile image could not be prepared.');
            @chmod($temporary, 0600); $provider->setProfilePhoto($settings, $temporary);
        } finally { imagedestroy($avatar); imagedestroy($source); @unlink($temporary); }
    }

    public function removeTelegramProfilePhoto(): void
    {
        [$provider, $settings] = $this->telegramProvider();
        if (!method_exists($provider, 'removeProfilePhoto')) throw new RuntimeException('The installed Telegram integration does not support bot profile management.');
        $provider->removeProfilePhoto($settings);
    }

    public function active(?string $kind = null): array
    {
        $result = [];
        foreach ($this->catalog() as $item) {
            if (!$item['enabled'] || !$item['verified_at'] || $item['last_error'] || ($kind !== null && $item['kind'] !== $kind)) continue;
            [$manifest, $path] = $this->manifest($item['slug']);
            $setting=$this->setting($item['slug']);
            if (!$setting['enabled'] || !$setting['verified_at'] || $setting['last_error']) continue;
            $result[] = ['slug' => $item['slug'], 'kind' => $item['kind'], 'delivery'=>$item['delivery'], 'provider' => $item['delivery']==='central'?null:$this->provider($manifest, $path), 'settings' => $setting['values'], 'since' => $setting['updated_at'], 'revision' => hash('sha256', $setting['updated_at'].json_encode($setting['values'], JSON_THROW_ON_ERROR))];
        }
        return $result;
    }

    private function setting(string $slug): array
    {
        $statement = $this->db->prepare('SELECT encrypted_settings,enabled,last_verified_at,last_error,updated_at FROM notification_channel_settings WHERE plugin_slug=? AND subject_type="installation" AND subject_id=0');
        $statement->execute([$slug]); $row = $statement->fetch(); $values = [];
        if ($row && (string) $row['encrypted_settings'] !== '') {
            try { $decoded = json_decode($this->secrets->decrypt((string) $row['encrypted_settings']), true, 32, JSON_THROW_ON_ERROR); if (is_array($decoded)) $values = $decoded; }
            catch (Throwable) { $row['last_error'] = 'Stored settings cannot be decrypted.'; }
        }
        return ['updated_at' => $row['updated_at'] ?? gmdate('Y-m-d H:i:s'), 'values' => $values, 'enabled' => (bool) ($row['enabled'] ?? false), 'verified_at' => $row['last_verified_at'] ?? null, 'last_error' => $row['last_error'] ?? null];
    }

    private function manifest(string $slug): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) throw new RuntimeException('The integration identifier is invalid.');
        $statement = $this->db->prepare("SELECT install_path FROM extension_packages WHERE type='plugin' AND slug=? AND active=1 LIMIT 1");
        $statement->execute([$slug]); $installPath = $statement->fetchColumn(); $path = is_string($installPath) ? $this->path($installPath) : null;
        if (!$path || !is_file($path . '/plugin.json')) throw new RuntimeException('The notification channel is unavailable.', 404);
        $manifest = json_decode((string) file_get_contents($path . '/plugin.json'), true);
        if (!is_array($manifest) || !is_array($manifest['notification_integration'] ?? null)) throw new RuntimeException('The notification channel manifest is invalid.');
        return [$manifest, $path];
    }

    private function provider(array $manifest, string $path): object
    {
        $relative = str_replace('\\', '/', (string) ($manifest['notification_integration']['handler'] ?? ''));
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || !str_ends_with($relative, '.php')) throw new RuntimeException('The integration handler is invalid.');
        $file = realpath($path . '/' . $relative);
        if (!$file || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $path) . '/')) throw new RuntimeException('The integration handler is unavailable.');
        $provider = require $file;
        if (!is_object($provider)) throw new RuntimeException('The integration handler did not return a provider.');
        return $provider;
    }

    public function canManageTelegramProfile(): bool
    {
        return (new Runtime($this->root))->baseUrl()==='https://www.sensecms.com'&&is_file($this->root.'/website/TelegramBotProfile.php')&&is_file($this->root.'/storage/private/telegram/config.json');
    }

    private function telegramProvider(): array
    {
        if ($this->canManageTelegramProfile()) {
            require_once $this->root.'/website/TelegramBotProfile.php';
            return [new \SenseCMS\Website\TelegramBotProfile($this->root.'/storage/private/telegram',$this->root.'/website/telegram-default.png'),[]];
        }
        [$manifest, $path] = $this->manifest('telegram-notifications'); $settings = $this->setting('telegram-notifications')['values'];
        if (empty($settings['bot_token'])) throw new RuntimeException('Configure and verify the Telegram bot token first.');
        return [$this->provider($manifest, $path), $settings];
    }

    private function path(string $installPath): ?string
    {
        if (!preg_match('#^plugins/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $installPath)) return null;
        $base = realpath($this->root . '/plugins'); $path = realpath($this->root . '/' . $installPath);
        if (!$base || !$path) return null;
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/'; $path = str_replace('\\', '/', $path);
        return str_starts_with($path . '/', $base) ? $path : null;
    }
}
