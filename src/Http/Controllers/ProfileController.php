<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Guards;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;
use App\Support\Csrf;
use App\Support\View;

final class ProfileController
{
    public static function service(): void
    {
        $user = Guards::requireLogin();
        View::render('profile/service', [
            'user' => $user,
            'services' => ServiceRepository::all(),
            'firstTime' => empty($user['service_id']),
        ]);
    }

    public static function saveService(): void
    {
        $user = Guards::requireLogin();
        Csrf::verifyRequest();

        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if (!$serviceId || !ServiceRepository::find($serviceId)) {
            View::flash('error', 'Choisissez votre service dans la liste.');
            header('Location: /profil/service');
            exit;
        }

        $firstTime = empty($user['service_id']);
        UserRepository::setService((int) $user['id'], $serviceId);
        View::flash('success', $firstTime ? 'Merci, votre service est enregistré.' : 'Votre service a été mis à jour.');
        header('Location: /');
        exit;
    }
}
