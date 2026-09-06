<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/** Eduvixo's session/role model, without optional application dependencies. */
final class Auth
{
    private const DUMMY = '$argon2id$v=19$m=65536,t=4,p=1$LzcyNDdVUEE3RS9OMGpaZQ$Mlwh+rmoNRaLP7DKy8IwFLoDxrosl4CKKmBnTETEJ+A';
    public function __construct(private readonly PDO $db) {}

    public function attempt(string $email, #[\SensitiveParameter] string $password, string $ip): bool
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 190 || strlen($password) > 200) return false;
        $allowed = true;
        foreach (['email:' . $email => 10, 'ip:' . $ip => 60] as $subject => $limit) {
            $bucket = hash('sha256', $subject); $now = time();
            $stmt = $this->db->prepare('INSERT INTO login_attempts (bucket,attempts,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(expires_at<=?,1,attempts+1),expires_at=IF(expires_at<=?,VALUES(expires_at),expires_at)');
            $stmt->execute([$bucket, $now + 900, $now, $now]);
            $stmt = $this->db->prepare('SELECT attempts FROM login_attempts WHERE bucket=?'); $stmt->execute([$bucket]);
            if ((int) $stmt->fetchColumn() > $limit) $allowed = false;
        }
        $this->db->prepare('DELETE FROM login_attempts WHERE expires_at<? LIMIT 100')->execute([time()]);
        if (!$allowed) return false;
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email=? AND active=1'); $stmt->execute([$email]); $user = $stmt->fetch();
        if (!password_verify($password, $user['password'] ?? self::DUMMY) || !$user) return false;
        session_regenerate_id(true);
        $_SESSION = ['user_id' => (int) $user['id'], 'version' => (int) $user['session_version'], 'created' => time(), 'seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
        $this->db->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user['id']]);
        $this->db->prepare('DELETE FROM login_attempts WHERE bucket=?')->execute([hash('sha256', 'email:' . $email)]);
        $this->audit((int) $user['id'], 'auth.login');
        return true;
    }

    public function user(): array
    {
        if (empty($_SESSION['user_id']) || time() - ($_SESSION['seen'] ?? 0) > 1800 || time() - ($_SESSION['created'] ?? 0) > 28800) return [];
        $stmt = $this->db->prepare("SELECT u.id,u.name,u.email,u.session_version,EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug='owner') owner FROM users u WHERE u.id=? AND u.active=1");
        $stmt->execute([$_SESSION['user_id']]); $user = $stmt->fetch();
        if (!$user || (int) $user['session_version'] !== ($_SESSION['version'] ?? 0)) return [];
        $_SESSION['seen'] = time();
        return $user;
    }

    public function audit(int $id, string $event): void
    {
        $this->db->prepare('INSERT INTO activity_log (user_id,event,created_at) VALUES (?,?,UTC_TIMESTAMP())')->execute([$id, $event]);
    }

    public static function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public static function verifyCsrf(mixed $value): bool { return is_string($value) && $value !== '' && hash_equals(self::csrf(), $value); }
}
