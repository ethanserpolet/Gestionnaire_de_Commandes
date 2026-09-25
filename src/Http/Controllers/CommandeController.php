<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Session;
use App\Http\Guards;
use App\Repositories\CommandeRepository;
use App\Repositories\DestinationRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\SupplierRepository;
use App\Services\CommandeService;
use App\Services\UploadService;
use App\Support\Csrf;
use App\Support\Roles;
use App\Support\View;
use RuntimeException;

final class CommandeController
{
    public static function mine(): void
    {
        $user = Guards::requireRole('demandeur');
        View::render('commandes/mine', [
            'commandes' => CommandeRepository::listForDemandeur((int) $user['id']),
        ]);
    }

    public static function toValidate(): void
    {
        $user = Guards::requireAnyRole(Roles::VALIDATORS);
        View::render('commandes/to_validate', [
            'aValider' => CommandeRepository::listAwaitingValidateur($user),
            'historique' => CommandeRepository::listHistoryForValidateur((int) $user['id']),
        ]);
    }

    public static function toFinalize(): void
    {
        $user = Guards::requireAnyRole(Roles::EXECUTORS);
        View::render('commandes/to_finalize', [
            'commandes' => CommandeRepository::listAwaitingExecution($user),
            'finalisees' => array_slice(CommandeRepository::listByStatuses(['finalise']), 0, 20),
        ]);
    }

    public static function validated(): void
    {
        Guards::requireRole('lecteur');
        View::render('commandes/validated', [
            'commandes' => CommandeRepository::listByStatuses(['valide', 'finalise']),
        ]);
    }

    public static function store(): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $id = CommandeRepository::create((int) $user['id']);
        CommandeRepository::logEvent($id, (int) $user['id'], 'creation', 'Brouillon créé.');
        header("Location: /commandes/$id");
        exit;
    }

    public static function show(array $params): void
    {
        $user = Guards::requireLogin();
        $commande = CommandeRepository::find((int) $params['id']);
        if (!$commande || !self::canView($user, $commande)) {
            Guards::forbidden();
            return;
        }

        $isOwner = (int) $commande['demandeur_id'] === (int) $user['id'];
        $editable = $isOwner && $commande['statut'] === 'brouillon';

        // Responsables proposés au demandeur pour l'étape 1 (vide s'il est lui-même responsable).
        $responsablesChoix = [];
        $demandeurEstResponsable = false;
        if ($editable && !empty($user['service_id'])) {
            $responsables = ServiceRepository::responsablesOf((int) $user['service_id']);
            $demandeurEstResponsable = in_array((int) $user['id'], array_map(static fn($r) => (int) $r['id'], $responsables), true);
            $responsablesChoix = $demandeurEstResponsable ? [] : $responsables;
        }

        View::render('commandes/show', [
            'commande' => $commande,
            'suppliers' => $editable ? SupplierRepository::allActive() : [],
            'destinationOptions' => $editable ? DestinationRepository::selectOptions() : [],
            'isOwner' => $isOwner,
            'actionableSlots' => CommandeRepository::actionableSlotsFor((int) $commande['id'], $user),
            'actionableExecutions' => CommandeRepository::actionableExecutionsFor((int) $commande['id'], $user),
            'responsablesChoix' => $responsablesChoix,
            'demandeurEstResponsable' => $demandeurEstResponsable,
        ]);
    }

    public static function printable(array $params): void
    {
        $user = Guards::requireLogin();
        $commande = CommandeRepository::find((int) $params['id']);
        if (!$commande || !self::canView($user, $commande)) {
            Guards::forbidden();
            return;
        }
        if ($commande['statut'] === 'brouillon') {
            View::flash('warning', 'Le PDF est disponible une fois la commande envoyée pour validation.');
            header("Location: /commandes/{$commande['id']}");
            exit;
        }

        View::renderBare('commandes/print', ['commande' => $commande, 'user' => $user]);
    }

    public static function addLigne(array $params): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $commande = self::ownedDraft($user, (int) $params['id']);

        $destination = DestinationRepository::selectablePath((int) ($_POST['destination_id'] ?? 0));
        $fournisseur = trim((string) ($_POST['fournisseur'] ?? ''));
        $quantite = max(1, (int) ($_POST['quantite'] ?? 1));
        $prixUnitaire = max(0, (float) str_replace(',', '.', (string) ($_POST['prix_unitaire'] ?? 0)));
        $prixTtc = (float) str_replace(',', '.', (string) ($_POST['prix_ttc'] ?? 0));
        if ($prixTtc <= 0) {
            $prixTtc = round($quantite * $prixUnitaire, 2);
        }

        if ($fournisseur === '' || $destination === null) {
            View::flash('error', 'Fournisseur et destination sont obligatoires.');
            header("Location: /commandes/{$commande['id']}");
            exit;
        }

        $ligneId = CommandeRepository::addLigne((int) $commande['id'], [
            'fournisseur' => $fournisseur,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'destination' => $destination,
            'motif' => trim((string) ($_POST['motif'] ?? '')),
            'quantite' => $quantite,
            'prix_unitaire' => $prixUnitaire,
            'prix_ttc' => $prixTtc,
        ]);

        if (!empty($_FILES['fichiers']['name'][0] ?? '')) {
            self::storeUploadedFiles($_FILES['fichiers'], $ligneId, (int) $commande['id'], (int) $user['id']);
        }

        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    public static function deleteLigne(array $params): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $commande = self::ownedDraft($user, (int) $params['id']);
        $ligneId = (int) $params['ligneId'];

        if (CommandeRepository::ligneBelongsTo($ligneId, (int) $commande['id'])) {
            foreach (CommandeRepository::find((int) $commande['id'])['lignes'] as $l) {
                if ((int) $l['id'] === $ligneId) {
                    foreach ($l['fichiers'] as $f) {
                        UploadService::delete((int) $commande['id'], $f['nom_stocke']);
                    }
                }
            }
            CommandeRepository::deleteLigne($ligneId, (int) $commande['id']);
        }

        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    public static function uploadFichier(array $params): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $commande = self::ownedDraft($user, (int) $params['id']);
        $ligneId = (int) $params['ligneId'];

        if (!CommandeRepository::ligneBelongsTo($ligneId, (int) $commande['id'])) {
            Guards::forbidden();
            return;
        }

        if (!empty($_FILES['fichiers']['name'][0] ?? '')) {
            self::storeUploadedFiles($_FILES['fichiers'], $ligneId, (int) $commande['id'], (int) $user['id']);
        }

        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    public static function deleteFichier(array $params): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $fichier = CommandeRepository::findFichier((int) $params['fichierId']);
        if (!$fichier) {
            Guards::forbidden();
            return;
        }
        $commande = self::ownedDraft($user, (int) $fichier['commande_id']);

        UploadService::delete((int) $commande['id'], $fichier['nom_stocke']);
        CommandeRepository::deleteFichier((int) $fichier['id']);

        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    public static function downloadFichier(array $params): void
    {
        $user = Guards::requireLogin();
        $fichier = CommandeRepository::findFichier((int) $params['fichierId']);
        if (!$fichier) {
            Guards::forbidden();
            return;
        }
        $commande = CommandeRepository::find((int) $fichier['commande_id']);
        if (!$commande || !self::canView($user, $commande)) {
            Guards::forbidden();
            return;
        }

        $path = UploadService::absolutePath((int) $commande['id'], $fichier['nom_stocke']);
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Fichier introuvable.';
            exit;
        }

        header('Content-Type: ' . $fichier['mime_type']);
        header('Content-Disposition: inline; filename="' . addslashes($fichier['nom_original']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public static function submit(array $params): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $commande = self::ownedDraft($user, (int) $params['id']);
        try {
            $responsableChoisi = (int) ($_POST['responsable_id'] ?? 0) ?: null;
            CommandeService::submit($commande, $user, $responsableChoisi, Session::accessToken());
            View::flash('success', 'Commande envoyée pour validation.');
        } catch (RuntimeException $e) {
            View::flash('error', $e->getMessage());
        }
        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    public static function delete(array $params): void
    {
        $user = Guards::requireRole('demandeur');
        Csrf::verifyRequest();

        $commande = self::ownedDraft($user, (int) $params['id']);
        foreach ($commande['lignes'] as $l) {
            foreach ($l['fichiers'] as $f) {
                UploadService::delete((int) $commande['id'], $f['nom_stocke']);
            }
        }
        CommandeRepository::delete((int) $commande['id']);
        View::flash('success', 'Brouillon supprimé.');
        header('Location: /commandes/mes');
        exit;
    }

    public static function decide(array $params): void
    {
        $user = Guards::requireLogin();
        Csrf::verifyRequest();

        $commande = CommandeRepository::find((int) $params['id']);
        $slots = $commande ? CommandeRepository::actionableSlotsFor((int) $commande['id'], $user) : [];
        if (!$commande || !$slots) {
            View::flash('error', 'Aucune validation n’est attendue de votre part sur cette commande.');
            header('Location: ' . ($commande ? "/commandes/{$commande['id']}" : '/'));
            exit;
        }

        $decision = $_POST['decision'] ?? '';
        if (!in_array($decision, ['valide', 'refuse'], true)) {
            Guards::forbidden();
            return;
        }
        $commentaire = trim((string) ($_POST['commentaire'] ?? ''));
        if ($decision === 'refuse' && $commentaire === '') {
            View::flash('error', 'Merci de préciser un motif de refus.');
            header("Location: /commandes/{$commande['id']}");
            exit;
        }

        try {
            CommandeService::decide($commande, $user, $slots, $decision, $commentaire ?: null, Session::accessToken());
            View::flash('success', $decision === 'valide' ? 'Commande validée.' : 'Commande refusée.');
        } catch (RuntimeException $e) {
            View::flash('error', $e->getMessage());
        }
        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    public static function finalize(array $params): void
    {
        $user = Guards::requireLogin();
        Csrf::verifyRequest();

        $commande = CommandeRepository::find((int) $params['id']);
        if (!$commande) {
            Guards::forbidden();
            return;
        }

        // Une part précise (execution_id) ou, à défaut, toutes celles confiées à l'utilisateur.
        $executions = CommandeRepository::actionableExecutionsFor((int) $commande['id'], $user);
        $cible = (int) ($_POST['execution_id'] ?? 0);
        if ($cible) {
            $executions = array_values(array_filter($executions, static fn($e) => (int) $e['id'] === $cible));
        }

        try {
            CommandeService::markExecuted($commande, $user, $executions, Session::accessToken());
            $apres = CommandeRepository::find((int) $commande['id']);
            View::flash('success', $apres['statut'] === 'finalise'
                ? 'Commande entièrement passée : elle est finalisée et le demandeur est prévenu.'
                : 'Part enregistrée comme passée (' . implode(', ', array_column($executions, 'fournisseur')) . ').');
        } catch (RuntimeException $e) {
            View::flash('error', $e->getMessage());
        }
        header("Location: /commandes/{$commande['id']}");
        exit;
    }

    // --- Helpers -----------------------------------------------------

    private static function ownedDraft(array $user, int $commandeId): array
    {
        $commande = CommandeRepository::find($commandeId);
        if (!$commande || (int) $commande['demandeur_id'] !== (int) $user['id']) {
            Guards::forbidden();
        }
        if ($commande['statut'] !== 'brouillon') {
            View::flash('error', 'Cette commande n\'est plus modifiable.');
            header("Location: /commandes/{$commande['id']}");
            exit;
        }
        return $commande;
    }

    private static function canView(array $user, array $commande): bool
    {
        if ($user['is_admin'] || (int) $commande['demandeur_id'] === (int) $user['id']) {
            return true;
        }
        $roles = $user['roles'];
        if ($commande['statut'] === 'brouillon') {
            return false;
        }
        if (Roles::isValidator($user)) {
            return true;
        }
        if (array_intersect(['executeur', 'lecteur'], $roles)) {
            return in_array($commande['statut'], ['valide', 'finalise'], true);
        }
        return false;
    }

    private static function storeUploadedFiles(array $filesArray, int $ligneId, int $commandeId, int $userId): void
    {
        $count = count($filesArray['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($filesArray['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $single = [
                'name' => $filesArray['name'][$i],
                'type' => $filesArray['type'][$i],
                'tmp_name' => $filesArray['tmp_name'][$i],
                'error' => $filesArray['error'][$i],
                'size' => $filesArray['size'][$i],
            ];
            try {
                $stored = UploadService::store($single, $commandeId);
                CommandeRepository::addFichier(
                    $ligneId,
                    $userId,
                    $stored['nom_original'],
                    $stored['nom_stocke'],
                    $stored['mime'],
                    $stored['taille']
                );
            } catch (RuntimeException $e) {
                View::flash('error', $e->getMessage());
            }
        }
    }
}
