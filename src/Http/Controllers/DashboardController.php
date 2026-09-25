<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Guards;
use App\Repositories\CommandeRepository;
use App\Support\Roles;
use App\Support\View;

final class DashboardController
{
    public static function index(): void
    {
        $user = Guards::requireLogin();
        $data = [
            'user' => $user,
            'mesBrouillons' => [],
            'mesEnCours' => [],
            'mesRecentes' => [],
            'aValider' => [],
            'aFinaliser' => [],
            'validees' => [],
            'nbValidees' => 0,
        ];

        if (in_array('demandeur', $user['roles'], true)) {
            $mine = CommandeRepository::listForDemandeur((int) $user['id']);
            $data['mesBrouillons'] = array_values(array_filter($mine, static fn($c) => $c['statut'] === 'brouillon'));
            $data['mesEnCours'] = array_values(array_filter($mine, static fn($c) => in_array($c['statut'], ['en_attente', 'en_validation', 'valide'], true)));
            $data['mesRecentes'] = array_slice($mine, 0, 6);
        }
        if (Roles::isValidator($user)) {
            $data['aValider'] = CommandeRepository::listAwaitingValidateur($user);
        }
        if (Roles::isExecutor($user)) {
            $data['aFinaliser'] = CommandeRepository::listAwaitingExecution($user);
        }
        if (in_array('lecteur', $user['roles'], true)) {
            $validees = CommandeRepository::listByStatuses(['valide', 'finalise']);
            $data['nbValidees'] = count($validees);
            $data['validees'] = array_slice($validees, 0, 6);
        }

        View::render('dashboard/index', $data);
    }

    /** Guide d'utilisation (docs/), affiché dans le navigateur. */
    public static function tutorial(): void
    {
        Guards::requireLogin();
        $file = dirname(__DIR__, 3) . '/docs/Guide-Commandes-St-Marc.pdf';
        if (!is_file($file)) {
            http_response_code(404);
            View::render('errors/404', []);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="Guide-Commandes-St-Marc.pdf"');
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }
}
