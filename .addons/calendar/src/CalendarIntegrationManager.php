<?php

declare(strict_types=1);

namespace SenseCMS\Calendar;

use App\Core\Secrets;
use PDO;
use RuntimeException;
use Throwable;

final class CalendarIntegrationManager
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
            $integration = is_array($manifest['calendar_integration'] ?? null) ? $manifest['calendar_integration'] : null;
            if (!$integration || !in_array($integration['kind'] ?? '', ['calendar', 'notification'], true)) continue;
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
            $result[] = ['slug' => $slug, 'name' => (string) ($manifest['name'] ?? $slug), 'description' => (string) ($manifest['description'] ?? ''), 'kind' => $integration['kind'], 'icon' => (string) ($manifest['icon'] ?? 'plug'), 'enabled' => $setting['enabled'], 'verified_at' => $setting['verified_at'], 'last_error' => $setting['last_error'], 'fields' => $fields];
        }
        return $result;
    }

    public function save(string $slug, array $input): array
    {
        [$manifest, $path] = $this->manifest($slug);
        $integration = (array) $manifest['calendar_integration'];
        $current = $this->setting($slug)['values'];
        $enabled = !empty($input['enabled']);
        $values = [];
        foreach ((array) ($integration['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) continue;
            $value = trim((string) ($input[$name] ?? ''));
            if ($value === '' && !empty($field['secret']) && isset($current[$name])) $value = (string) $current[$name];
            if ($enabled && !empty($field['required']) && $value === '') throw new RuntimeException((string) ($field['label'] ?? $name) . ' is required.');
            if (strlen($value) > 4096 || str_contains($value, "\0")) throw new RuntimeException('An integration setting is invalid.');
            if (($field['type'] ?? '') === 'url' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($value), 'https://'))) throw new RuntimeException((string) ($field['label'] ?? $name) . ' must use a valid HTTPS URL.');
            if (($field['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new RuntimeException((string) ($field['label'] ?? $name) . ' must be a valid email address.');
            $values[$name] = $value;
        }
        if ($enabled && $integration['kind'] === 'notification') {
            $recipients = json_decode((string) ($values['recipient_map'] ?? ''), true);
            if (!is_array($recipients) || !$recipients || count($recipients)>100) throw new RuntimeException('Provide a JSON map of user IDs to their consented notification recipients (maximum 100).');
            $check = $this->db->prepare('SELECT 1 FROM users WHERE id=? AND active=1');
            foreach ($recipients as $userId => $recipient) {
                if (!ctype_digit((string)$userId) || !is_string($recipient) || !preg_match($slug==='telegram-notifications' ? '/^[1-9][0-9]{4,19}$/D' : '/^[1-9][0-9]{6,14}$/D', $recipient)) throw new RuntimeException('Recipient map contains an invalid user or private recipient identifier.');
                $check->execute([(int)$userId]); if (!$check->fetchColumn()) throw new RuntimeException('Recipient map contains an unavailable user.');
            }
        }
        $verified = null; $error = null;
        if ($enabled) {
            try { $provider = $this->provider($manifest, $path); if (method_exists($provider, 'verify')) $provider->verify($values); $verified = date('Y-m-d H:i:s'); }
            catch (Throwable $exception) { throw new RuntimeException('Integration verification failed. Check the provider credentials, permissions and destination.'); }
        }
        $encrypted = $this->secrets->encrypt(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $statement = $this->db->prepare('INSERT INTO calendar_integration_settings (plugin_slug,subject_type,subject_id,encrypted_settings,enabled,last_verified_at,last_error,updated_at) VALUES (?,"installation",0,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE encrypted_settings=VALUES(encrypted_settings),enabled=VALUES(enabled),last_verified_at=VALUES(last_verified_at),last_error=VALUES(last_error),updated_at=NOW()');
        $statement->execute([$slug, $encrypted, $enabled ? 1 : 0, $verified, $error]);
        return ['slug' => $slug, 'enabled' => $enabled, 'verified_at' => $verified];
    }

    public function active(?string $kind = null): array
    {
        $result = [];
        foreach ($this->catalog() as $item) {
            if (!$item['enabled'] || !$item['verified_at'] || $item['last_error'] || ($kind !== null && $item['kind'] !== $kind)) continue;
            [$manifest, $path] = $this->manifest($item['slug']);
            $result[] = ['slug' => $item['slug'], 'kind' => $item['kind'], 'provider' => $this->provider($manifest, $path), 'settings' => $this->setting($item['slug'])['values']];
        }
        return $result;
    }

    private function setting(string $slug): array
    {
        $statement = $this->db->prepare('SELECT encrypted_settings,enabled,last_verified_at,last_error FROM calendar_integration_settings WHERE plugin_slug=? AND subject_type="installation" AND subject_id=0');
        $statement->execute([$slug]); $row = $statement->fetch(); $values = [];
        if ($row && (string) $row['encrypted_settings'] !== '') {
            try { $decoded = json_decode($this->secrets->decrypt((string) $row['encrypted_settings']), true, 32, JSON_THROW_ON_ERROR); if (is_array($decoded)) $values = $decoded; }
            catch (Throwable) { $row['last_error'] = 'Stored settings cannot be decrypted.'; }
        }
        return ['values' => $values, 'enabled' => (bool) ($row['enabled'] ?? false), 'verified_at' => $row['last_verified_at'] ?? null, 'last_error' => $row['last_error'] ?? null];
    }

    private function manifest(string $slug): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) throw new RuntimeException('The integration identifier is invalid.');
        $statement = $this->db->prepare("SELECT install_path FROM extension_packages WHERE type='plugin' AND slug=? AND active=1 LIMIT 1");
        $statement->execute([$slug]); $installPath = $statement->fetchColumn(); $path = is_string($installPath) ? $this->path($installPath) : null;
        if (!$path || !is_file($path . '/plugin.json')) throw new RuntimeException('The calendar integration is unavailable.', 404);
        $manifest = json_decode((string) file_get_contents($path . '/plugin.json'), true);
        if (!is_array($manifest) || !is_array($manifest['calendar_integration'] ?? null)) throw new RuntimeException('The calendar integration manifest is invalid.');
        return [$manifest, $path];
    }

    private function provider(array $manifest, string $path): object
    {
        $relative = str_replace('\\', '/', (string) ($manifest['calendar_integration']['handler'] ?? ''));
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || !str_ends_with($relative, '.php')) throw new RuntimeException('The integration handler is invalid.');
        $file = realpath($path . '/' . $relative);
        if (!$file || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $path) . '/')) throw new RuntimeException('The integration handler is unavailable.');
        $provider = require $file;
        if (!is_object($provider)) throw new RuntimeException('The integration handler did not return a provider.');
        return $provider;
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
