<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    public static function tokenFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }
        return isset($_COOKIE['forum_token']) && is_string($_COOKIE['forum_token']) ? $_COOKIE['forum_token'] : null;
    }

    public static function usesCookieToken(): bool
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^Bearer\s+(.+)$/i', $header) !== 1 &&
            isset($_COOKIE['forum_token']) && is_string($_COOKIE['forum_token']) && $_COOKIE['forum_token'] !== '';
    }

    public static function user(bool $required = false): ?array
    {
        $token = self::tokenFromRequest();
        if ($token === null || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            if ($required) {
                Http::json(['error' => 'authentication_required'], 401);
            }
            return null;
        }
        $hash = hash('sha256', $token);
        $statement = Database::connection()->prepare(
            'SELECT u.id, u.username, u.role, u.points, u.level, u.vip_until, u.daily_downloads, u.download_date
             FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.status = "active"'
        );
        $statement->execute([$hash]);
        $user = $statement->fetch();
        if (!$user && $required) {
            Http::json(['error' => 'invalid_token'], 401);
        }
        return $user ?: null;
    }

    public static function login(string $username, string $password): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $statement = $db->prepare('SELECT * FROM users WHERE username = ? AND status = "active" LIMIT 1 FOR UPDATE');
            $statement->execute([$username]);
            $user = $statement->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $db->rollBack();
                Http::json(['error' => 'invalid_credentials'], 401);
            }
            $token = bin2hex(random_bytes(32));
            $db->prepare(
                'INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
            )->execute([$user['id'], hash('sha256', $token)]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        self::setTokenCookie($token, time() + 2592000);
        return ['token' => $token, 'expires_in' => 2592000, 'user' => self::publicUser($user)];
    }

    public static function logout(): void
    {
        $token = self::tokenFromRequest();
        if ($token !== null) {
            Database::connection()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([hash('sha256', $token)]);
        }
        self::setTokenCookie('', time() - 3600);
    }

    public static function register(string $username, string $password): array
    {
        if (!preg_match('/^[\p{L}\p{N}_-]{3,24}$/u', $username)) {
            Http::json(['error' => 'invalid_username'], 422);
        }
        if (strlen($password) < 10 || strlen($password) > 72) {
            Http::json(['error' => 'invalid_password'], 422);
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO users (username, password_hash, points, level) VALUES (?, ?, 20, 1)'
            )->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (\PDOException $error) {
            if ((int) $error->errorInfo[1] === 1062) {
                Http::json(['error' => 'username_taken'], 409);
            }
            throw $error;
        }
        return self::login($username, $password);
    }

    public static function isVip(array $user): bool
    {
        return $user['role'] === 'admin' || ($user['vip_until'] !== null && strtotime($user['vip_until']) > time());
    }

    public static function changePassword(array $user, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 10 || strlen($newPassword) > 72 || $newPassword === $currentPassword) {
            Http::json(['error' => 'invalid_password'], 422);
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT password_hash FROM users WHERE id = ? AND status = "active" FOR UPDATE');
            $query->execute([$user['id']]);
            $hash = $query->fetchColumn();
            if (!$hash || !password_verify($currentPassword, $hash)) {
                $db->rollBack();
                Http::json(['error' => 'invalid_credentials'], 403);
            }
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            $db->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$user['id']]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        return self::login($user['username'], $newPassword);
    }

    public static function admin(): array
    {
        $user = self::user(true);
        if ($user['role'] !== 'admin') {
            Http::json(['error' => 'admin_required'], 403);
        }
        return $user;
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'points' => (int) $user['points'],
            'level' => (int) $user['level'],
            'vip_until' => $user['vip_until'],
        ];
    }

    private static function setTokenCookie(string $token, int $expires): void
    {
        setcookie('forum_token', $token, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Http::isSecure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
