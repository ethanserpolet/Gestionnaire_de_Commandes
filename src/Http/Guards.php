<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\Session;
use App\Repositories\ServiceRepository;

final class Guards
{
    /** Pages reachable before the user has picked their service. */
    private const ONBOARDING_EXEMPT = ['/profil/service', '/logout', '/admin/'];

    public static function requireLogin(): array
    {
        if (!Session::isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $user = Session::currentUser();
        if (!$user || !$user['is_active']) {
            Session::logout();
            header('Location: /login?desactive=1');
            exit;
        }

        if (empty($user['service_id']) && !self::isOnboardingExempt() && ServiceRepository::count() > 0) {
            header('Location: /profil/service');
            exit;
        }

        return $user;
    }

    public static function requireRole(string $role): array
    {
        return self::requireAnyRole([$role]);
    }

    /** @param string[] $roles */
    public static function requireAnyRole(array $roles): array
    {
        $user = self::requireLogin();
        if (!array_intersect($roles, $user['roles'])) {
            self::forbidden();
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (!$user['is_admin']) {
            self::forbidden();
        }
        return $user;
    }

    public static function forbidden(): void
    {
        http_response_code(403);
        \App\Support\View::render('errors/403', []);
        exit;
    }

    private static function isOnboardingExempt(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (self::ONBOARDING_EXEMPT as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
