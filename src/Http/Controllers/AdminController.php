<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Guards;
use App\Auth\Session;
use App\Repositories\DestinationRepository;
use App\Services\GraphMailer;
use App\Services\MailTemplate;
use App\Services\SimulationService;
use App\Repositories\ServiceRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Support\Csrf;
use App\Support\Roles;
use App\Support\View;

final class AdminController
{
    private const USERS_PER_PAGE = 50;

    public static function users(): void
    {
        $admin = Guards::requireAdmin();
        $roleCodes = array_column(UserRepository::allRoles(), 'code');

        $q = trim((string) ($_GET['q'] ?? ''));
        $role = (string) ($_GET['role'] ?? '');
        $service = (string) ($_GET['service'] ?? '');
        $statut = (string) ($_GET['statut'] ?? 'tous');
        $tri = (string) ($_GET['tri'] ?? 'nom');
        $filters = [
            'q' => mb_substr($q, 0, 100),
            'role' => in_array($role, array_merge($roleCodes, ['_none', '_admin']), true) ? $role : '',
            'service' => $service === '_none' || ctype_digit($service) ? $service : '',
            'statut' => in_array($statut, ['tous', 'actifs', 'inactifs'], true) ? $statut : 'tous',
            'tri' => array_key_exists($tri, UserRepository::SORTS) ? $tri : 'nom',
        ];

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = UserRepository::search($filters, $page, self::USERS_PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::USERS_PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = UserRepository::search($filters, $page, self::USERS_PER_PAGE);
        }

        View::render('admin/users', [
            'users' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::USERS_PER_PAGE,
            'filters' => $filters,
            'stats' => UserRepository::stats(),
            'roles' => UserRepository::allRoles(),
            'services' => ServiceRepository::all(),
            'currentAdminId' => (int) $admin['id'],
        ]);
    }

    public static function updateRoles(array $params): void
    {
        $admin = Guards::requireAdmin();
        Csrf::verifyRequest();

        $userId = (int) $params['id'];
        $isSelf = $userId === (int) $admin['id'];
        $roles = $_POST['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        $valid = array_column(UserRepository::allRoles(), 'code');
        $roles = array_values(array_intersect($roles, $valid));

        UserRepository::setRoles($userId, $roles);
        // Un administrateur ne peut pas se retirer ses propres droits ni se désactiver.
        UserRepository::setAdmin($userId, $isSelf || !empty($_POST['is_admin']));
        if (array_key_exists('service_id', $_POST)) {
            $serviceId = (int) $_POST['service_id'];
            UserRepository::setService($userId, $serviceId && ServiceRepository::find($serviceId) ? $serviceId : null);
        }
        if (array_key_exists('has_active', $_POST)) {
            UserRepository::setActive($userId, $isSelf || !empty($_POST['is_active']));
        }

        $user = UserRepository::findById($userId);
        View::flash('success', 'Compte de ' . ($user['display_name'] ?? '?') . ' mis à jour.');
        self::backToUsers();
    }

    public static function toggleActive(array $params): void
    {
        $admin = Guards::requireAdmin();
        Csrf::verifyRequest();

        $userId = (int) $params['id'];
        if ($userId !== (int) $admin['id']) {
            UserRepository::setActive($userId, !empty($_POST['actif']));
            View::flash('success', 'Statut du compte mis à jour.');
        }
        self::backToUsers();
    }

    public static function bulkUsers(): void
    {
        $admin = Guards::requireAdmin();
        Csrf::verifyRequest();

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
        $action = (string) ($_POST['action'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $validRoles = array_column(UserRepository::allRoles(), 'code');

        if (!$ids) {
            View::flash('error', 'Aucun compte sélectionné.');
            self::backToUsers();
        }

        switch ($action) {
            case 'add_role':
            case 'remove_role':
                if (!in_array($role, $validRoles, true)) {
                    View::flash('error', 'Choisissez un rôle.');
                    self::backToUsers();
                }
                $action === 'add_role' ? UserRepository::bulkAddRole($ids, $role) : UserRepository::bulkRemoveRole($ids, $role);
                $message = 'Rôle « ' . Roles::label($role) . ' » ' . ($action === 'add_role' ? 'ajouté à' : 'retiré de');
                break;
            case 'set_service':
                $serviceId = (int) ($_POST['service_id'] ?? 0);
                UserRepository::bulkSetService($ids, $serviceId && ServiceRepository::find($serviceId) ? $serviceId : null);
                $message = 'Service modifié pour';
                break;
            case 'activate':
            case 'deactivate':
                // Jamais soi-même : on ne peut pas se couper l'accès.
                $ids = array_values(array_diff($ids, [(int) $admin['id']]));
                if ($ids) {
                    UserRepository::bulkSetActive($ids, $action === 'activate');
                }
                $message = ($action === 'activate' ? 'Réactivation de' : 'Désactivation de');
                break;
            default:
                View::flash('error', 'Action inconnue.');
                self::backToUsers();
        }

        View::flash('success', $message . ' ' . count($ids) . ' compte' . (count($ids) > 1 ? 's' : '') . '.');
        self::backToUsers();
    }

    /** Retour à la liste en conservant recherche, filtres et page. */
    private static function backToUsers(): void
    {
        $return = (string) ($_POST['return'] ?? '');
        $qs = str_starts_with($return, '?') ? $return : '';
        header('Location: /admin/utilisateurs' . preg_replace('/[\r\n]/', '', $qs));
        exit;
    }

    // --- Services --------------------------------------------------------

    public static function services(): void
    {
        Guards::requireAdmin();
        View::render('admin/services', [
            'services' => ServiceRepository::all(),
            'responsables' => UserRepository::findActiveByRole('responsable_service'),
        ]);
    }

    public static function createService(): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            View::flash('error', 'Le nom du service est obligatoire.');
        } else {
            ServiceRepository::create($name, self::responsablesFromPost());
            View::flash('success', 'Service « ' . $name . ' » ajouté.');
        }
        header('Location: /admin/services');
        exit;
    }

    public static function updateService(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || !ServiceRepository::find($id)) {
            View::flash('error', 'Service introuvable ou nom vide.');
        } else {
            ServiceRepository::update($id, $name, self::responsablesFromPost());
            View::flash('success', 'Service mis à jour.');
        }
        header('Location: /admin/services');
        exit;
    }

    public static function deleteService(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        ServiceRepository::delete((int) $params['id']);
        View::flash('success', 'Service supprimé. Ses membres devront choisir un nouveau service à leur prochaine visite.');
        header('Location: /admin/services');
        exit;
    }

    /** @return int[] ids cochés, limités aux utilisateurs ayant le rôle Responsable de service */
    private static function responsablesFromPost(): array
    {
        $posted = array_map('intval', (array) ($_POST['responsables'] ?? []));
        $allowed = array_map(static fn($u) => (int) $u['id'], UserRepository::findActiveByRole('responsable_service'));
        return array_values(array_intersect($posted, $allowed));
    }

    // --- Simulation -------------------------------------------------------

    public static function simulation(?array $report = null, array $form = []): void
    {
        $admin = Guards::requireAdmin();
        $users = array_values(array_filter(UserRepository::all(), static fn($u) => (bool) $u['is_active']));

        $defaultDemandeur = $admin['id'];
        foreach ($users as $u) {
            if (in_array('demandeur', $u['roles'], true) && !empty($u['service_id'])) {
                $defaultDemandeur = $u['id'];
                break;
            }
        }

        View::render('admin/simulation', [
            'preflight' => SimulationService::preflight(),
            'users' => $users,
            'responsables' => UserRepository::findActiveByRole('responsable_service'),
            'scenarios' => SimulationService::SCENARIOS,
            'report' => $report,
            'form' => $form + ['demandeur_id' => (int) $defaultDemandeur, 'scenario' => 'complet', 'responsable_id' => 0],
        ]);
    }

    public static function runSimulation(): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $form = [
            'demandeur_id' => (int) ($_POST['demandeur_id'] ?? 0),
            'scenario' => array_key_exists($_POST['scenario'] ?? '', SimulationService::SCENARIOS) ? $_POST['scenario'] : 'complet',
            'responsable_id' => (int) ($_POST['responsable_id'] ?? 0),
        ];
        $report = SimulationService::run($form['demandeur_id'], $form['scenario'], $form['responsable_id'] ?: null);
        self::simulation($report, $form);
    }

    public static function sendTestMail(): void
    {
        $admin = Guards::requireAdmin();
        Csrf::verifyRequest();

        $html = MailTemplate::render([
            'tone' => 'primary',
            'eyebrow' => 'Test',
            'title' => 'L’envoi des e-mails fonctionne',
            'intro' => MailTemplate::hello($admin['display_name'])
                . 'Si vous lisez ce message, l’application peut envoyer des e-mails via Microsoft Graph en votre nom. '
                . 'Voici l’apparence des notifications reçues par le personnel.',
            'commande' => [
                'id' => 0,
                'numero_commande' => 'BC-TEST-0000',
                'demandeur_nom' => $admin['display_name'],
                'service_nom' => $admin['service_nom'] ?? 'Service de test',
                'lignes' => [['prix_ttc' => '37.50', 'fournisseur' => 'Fournisseur test']],
                'validations' => [],
            ],
            'cta' => ['label' => 'Ouvrir l’application', 'url' => \App\Support\Url::to('/')],
        ]);

        $sent = GraphMailer::send(Session::accessToken(), [$admin['email']], 'Test d’envoi — Commandes St Marc', $html);
        View::flash($sent ? 'success' : 'error', $sent
            ? 'E-mail de test envoyé à ' . $admin['email'] . '. Vérifiez votre boîte de réception.'
            : 'Échec de l’envoi via Microsoft Graph. Vérifiez la permission Mail.Send dans Entra (détail dans le journal PHP).');
        header('Location: /admin/simulation');
        exit;
    }

    // --- Destinations -----------------------------------------------------

    public static function destinations(): void
    {
        Guards::requireAdmin();
        View::render('admin/destinations', ['tree' => DestinationRepository::tree()]);
    }

    public static function createDestination(): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        if ($name === '') {
            View::flash('error', 'Le nom est obligatoire.');
        } else {
            DestinationRepository::create($name, $parentId);
            View::flash('success', ($parentId ? 'Lieu' : 'Pôle') . ' « ' . $name . ' » ajouté.');
        }
        header('Location: /admin/destinations');
        exit;
    }

    /** Glisser-déposer : appelé en fetch, répond en JSON. */
    public static function reorderDestinations(): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        header('Content-Type: application/json; charset=utf-8');
        $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        try {
            DestinationRepository::reorder($parentId, $ids);
            echo json_encode(['ok' => true, 'message' => 'Ordre enregistré.']);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    public static function renameDestination(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            DestinationRepository::rename((int) $params['id'], $name);
            View::flash('success', 'Destination renommée.');
        }
        header('Location: /admin/destinations');
        exit;
    }

    public static function toggleDestination(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        DestinationRepository::setActive((int) $params['id'], !empty($_POST['actif']));
        header('Location: /admin/destinations');
        exit;
    }

    public static function deleteDestination(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        DestinationRepository::delete((int) $params['id']);
        View::flash('success', 'Destination supprimée.');
        header('Location: /admin/destinations');
        exit;
    }

    // --- Fournisseurs -----------------------------------------------------

    public static function suppliers(): void
    {
        Guards::requireAdmin();
        View::render('admin/suppliers', [
            'suppliers' => SupplierRepository::all(),
            'executeurs' => self::eligibleExecuteurs(),
            'hasComptabilite' => (bool) UserRepository::findActiveByRole('comptabilite'),
        ]);
    }

    public static function createSupplier(): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            SupplierRepository::create($name, self::executeurFromPost());
            View::flash('success', 'Fournisseur ajouté.');
        }
        header('Location: /admin/fournisseurs');
        exit;
    }

    /** Personnes pouvant être désignées comme exécuteur d'un fournisseur. */
    private static function eligibleExecuteurs(): array
    {
        $list = [];
        foreach (['executeur', 'comptabilite'] as $role) {
            foreach (UserRepository::findActiveByRole($role) as $u) {
                $list[(int) $u['id']] = $u;
            }
        }
        uasort($list, static fn($a, $b) => strnatcasecmp($a['display_name'], $b['display_name']));
        return array_values($list);
    }

    private static function executeurFromPost(): ?int
    {
        $id = (int) ($_POST['executeur_id'] ?? 0);
        $allowed = array_map(static fn($u) => (int) $u['id'], self::eligibleExecuteurs());
        return in_array($id, $allowed, true) ? $id : null;
    }

    public static function toggleSupplierActive(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        SupplierRepository::setActive((int) $params['id'], !empty($_POST['actif']));
        header('Location: /admin/fournisseurs');
        exit;
    }

    public static function renameSupplier(array $params): void
    {
        Guards::requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name !== '') {
            SupplierRepository::update((int) $params['id'], $name, self::executeurFromPost());
            View::flash('success', 'Fournisseur mis à jour.');
        }
        header('Location: /admin/fournisseurs');
        exit;
    }
}
