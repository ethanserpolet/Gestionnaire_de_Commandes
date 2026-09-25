<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\EntraAuth;
use App\Config;
use App\Auth\Session;
use App\Repositories\UserRepository;
use App\Support\Csrf;
use App\Support\View;

final class AuthController
{
    public static function showLogin(): void
    {
        if (Session::isAuthenticated()) {
            header('Location: /');
            exit;
        }
        View::render('auth/login', []);
    }

    public static function start(): void
    {
        if (Session::isAuthenticated()) {
            header('Location: /');
            exit;
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        header('Location: ' . EntraAuth::authorizeUrl($state));
        exit;
    }

    public static function callback(): void
    {
        $state = $_GET['state'] ?? '';
        $expected = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        if (!$expected || !hash_equals($expected, $state)) {
            View::flash('error', 'Session de connexion invalide, veuillez réessayer.');
            header('Location: /login');
            exit;
        }

        if (!empty($_GET['error'])) {
            $notAssigned = str_contains((string) ($_GET['error_description'] ?? ''), 'AADSTS50105');
            View::flash('error', $notAssigned
                ? 'Votre compte n’est pas autorisé à accéder à cette application. Contactez le service informatique.'
                : 'Connexion Microsoft annulée ou refusée.');
            header('Location: /login');
            exit;
        }

        $code = $_GET['code'] ?? '';
        if ($code === '') {
            header('Location: /login');
            exit;
        }

        try {
            $token = EntraAuth::exchangeCode($code);
            $profile = EntraAuth::fetchProfile($token['access_token']);
        } catch (\Throwable $e) {
            error_log('OAuth callback error: ' . $e->getMessage());
            View::flash('error', "Échec de la connexion Microsoft : " . $e->getMessage());
            header('Location: /login');
            exit;
        }

        if (!str_ends_with($profile['email'], '@st-marc.eu')) {
            View::flash('error', 'Seuls les comptes @st-marc.eu sont autorisés.');
            header('Location: /login');
            exit;
        }

        // Élèves (GP_eleves…) : refusés avant toute création de compte.
        $isBootstrapAdmin = in_array(strtolower($profile['email']), Config::list('APP_ADMIN_EMAILS'), true);
        $groupWarning = null;
        try {
            $blockedGroups = EntraAuth::blockedGroupsOf($token['access_token']);
        } catch (\Throwable $e) {
            error_log('Group check failed for ' . $profile['email'] . ': ' . $e->getMessage());
            if (!$isBootstrapAdmin) {
                View::flash('error', 'Impossible de vérifier votre accès pour le moment. Contactez le service informatique.');
                header('Location: /login');
                exit;
            }
            // Les administrateurs passent quand même, pour pouvoir corriger la configuration.
            $blockedGroups = [];
            $groupWarning = 'Contrôle des groupes impossible : ' . $e->getMessage();
        }
        if ($blockedGroups) {
            error_log('Login refused (blocked group ' . implode(', ', $blockedGroups) . '): ' . $profile['email']);
            View::flash('error', 'Cette application est réservée au personnel du lycée.');
            header('Location: /login');
            exit;
        }

        $userId = UserRepository::upsertFromEntra($profile['id'], $profile['email'], $profile['displayName']);
        Session::login($userId, $token['access_token'], $token['refresh_token'] ?? '', (int) $token['expires_in']);
        if ($groupWarning) {
            View::flash('warning', $groupWarning);
        }

        $user = UserRepository::findById($userId);
        if (empty($user['roles']) && !$user['is_admin']) {
            View::flash('warning', 'Votre compte a été créé mais aucun rôle ne vous a encore été attribué. Contactez un administrateur.');
        }

        header('Location: /');
        exit;
    }

    public static function logout(): void
    {
        Csrf::verifyRequest();
        Session::logout();
        header('Location: /login');
        exit;
    }
}
