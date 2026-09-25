<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config;
use App\Repositories\UserRepository;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('commandes_sid');
        session_start();
    }

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function currentUser(): ?array
    {
        if (!self::isAuthenticated()) {
            return null;
        }
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = UserRepository::findById((int) $_SESSION['user_id']);
        return $cache;
    }

    public static function hasRole(string $role): bool
    {
        $user = self::currentUser();
        return $user !== null && in_array($role, $user['roles'], true);
    }

    public static function isAdmin(): bool
    {
        $user = self::currentUser();
        return $user !== null && $user['is_admin'];
    }

    public static function login(int $userId, string $accessToken, string $refreshToken, int $expiresIn): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['oauth'] = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => time() + $expiresIn - 60,
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /**
     * Returns a valid Graph access token for the current user, refreshing it if needed.
     */
    public static function accessToken(): string
    {
        $oauth = $_SESSION['oauth'] ?? null;
        if (!$oauth) {
            throw new \RuntimeException('Utilisateur non authentifié.');
        }

        if (time() < $oauth['expires_at']) {
            return $oauth['access_token'];
        }

        $fresh = EntraAuth::refreshToken($oauth['refresh_token']);
        $_SESSION['oauth'] = [
            'access_token' => $fresh['access_token'],
            'refresh_token' => $fresh['refresh_token'] ?? $oauth['refresh_token'],
            'expires_at' => time() + $fresh['expires_in'] - 60,
        ];
        return $_SESSION['oauth']['access_token'];
    }
}
