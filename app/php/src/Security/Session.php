<?php
declare(strict_types=1);

namespace DigiOps\Security;

use DigiOps\Support\JsonResponse;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('DIGIOPSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
        if (!isset($_SESSION['createdAt'])) {
            session_regenerate_id(true);
            $_SESSION['createdAt'] = time();
        }
        if (time() - (int)($_SESSION['lastSeen'] ?? time()) > 3600) {
            self::logout();
            session_start();
        }
        $_SESSION['lastSeen'] = time();
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['createdAt'] = time();
        $_SESSION['lastSeen'] = time();
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        self::start();
        return is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : null;
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
        return (string)$_SESSION['csrf'];
    }

    public static function requireRole(array $roles = ['admin','operator','viewer']): array
    {
        $user = self::user();
        if (!$user) JsonResponse::send(['error' => 'AUTH_REQUIRED'], 401);
        if (!in_array((string)($user['role'] ?? ''), $roles, true)) JsonResponse::send(['error' => 'FORBIDDEN'], 403);
        return $user;
    }

    public static function assertCsrf(): void
    {
        self::start();
        $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($provided === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $provided)) {
            JsonResponse::send(['error' => 'CSRF_FAILED'], 419);
        }
    }
}
