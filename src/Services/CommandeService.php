<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommandeRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Support\Roles;
use App\Support\Url;
use App\Support\View;
use RuntimeException;

/**
 * Validation circuit:
 *   étape 1 — responsable du service du demandeur ;
 *   étape 2 — comptabilité + chef d'établissement (s'ils existent) + validateurs individuels.
 * Every mail is sent through Graph as the user performing the action.
 */
final class CommandeService
{
    public static function submit(array $commande, array $demandeur, ?int $responsableChoisi, string $accessToken): void
    {
        if ($commande['statut'] !== 'brouillon') {
            throw new RuntimeException('Cette commande a déjà été envoyée.');
        }
        if (empty($commande['lignes'])) {
            throw new RuntimeException('Ajoutez au moins un article avant d’envoyer la commande.');
        }

        $id = (int) $commande['id'];
        $serviceId = !empty($demandeur['service_id']) ? (int) $demandeur['service_id'] : null;
        // Validé avant tout enregistrement : une erreur ici laisse la commande en brouillon.
        [$responsableId, $demandeurEstResponsable] = self::chooseResponsable($demandeur, $serviceId, $responsableChoisi);

        $numero = CommandeRepository::numeroFor($id);
        CommandeRepository::markSubmitted($id, $numero, $serviceId);
        CommandeRepository::logEvent($id, (int) $demandeur['id'], 'soumission', "Commande $numero envoyée.");
        if ($demandeurEstResponsable) {
            CommandeRepository::logEvent($id, null, 'info', 'Validation du responsable non requise : le demandeur est responsable du service.');
        }

        $slots = self::buildSlots($responsableId);
        $commande = CommandeRepository::find($id);

        if (!$slots) {
            CommandeRepository::setStatut($id, 'valide');
            CommandeRepository::logEvent($id, null, 'info', 'Aucun validateur configuré : commande validée automatiquement.');
            self::notifyValidated(CommandeRepository::find($id), $demandeur, $accessToken);
            return;
        }

        CommandeRepository::createSlots($id, $slots);
        self::notifyStageStart(CommandeRepository::find($id), $demandeur, $accessToken);
    }

    /** @param array $slots actionable slots of $user (all in the current stage) */
    public static function decide(array $commande, array $user, array $slots, string $decision, ?string $commentaire, string $accessToken): void
    {
        $id = (int) $commande['id'];
        if (!in_array($commande['statut'], ['en_attente', 'en_validation'], true)) {
            throw new RuntimeException('Cette commande n’est plus en attente de validation.');
        }
        if (!$slots) {
            throw new RuntimeException('Aucune validation n’est attendue de votre part sur cette commande.');
        }

        foreach ($slots as $slot) {
            CommandeRepository::recordDecision((int) $slot['id'], (int) $user['id'], $decision, $commentaire);
        }
        $roleLabels = implode(', ', array_unique(array_map(static fn($s) => Roles::label($s['role']), $slots)));
        CommandeRepository::logEvent(
            $id,
            (int) $user['id'],
            $decision === 'valide' ? 'validation' : 'refus',
            $roleLabels . ($commentaire ? ' — ' . $commentaire : '')
        );

        $demandeur = UserRepository::findById((int) $commande['demandeur_id']);

        if ($decision === 'refuse') {
            CommandeRepository::markRefused($id, $commentaire ?: 'Aucun motif précisé.');
            $commande = CommandeRepository::find($id);
            self::send($accessToken, [$demandeur], $user, 'Commande refusée — ' . $commande['numero_commande'], [
                'tone' => 'danger',
                'eyebrow' => 'Commande refusée',
                'title' => 'Votre demande d’achat a été refusée',
                'intro' => MailTemplate::hello($demandeur['display_name'])
                    . '<strong>' . View::e($user['display_name']) . '</strong> (' . View::e($roleLabels) . ') a refusé votre commande '
                    . '<strong>' . View::e($commande['numero_commande']) . '</strong>.',
                'note' => ['label' => 'Motif du refus', 'text' => $commentaire ?: 'Aucun motif précisé.'],
                'commande' => $commande,
                'cta' => ['label' => 'Voir la commande', 'url' => Url::to("/commandes/$id")],
            ]);
            return;
        }

        $commande = CommandeRepository::find($id);
        $pending = array_filter($commande['validations'], static fn($v) => $v['decision'] === 'en_attente');

        if (!$pending) {
            CommandeRepository::setStatut($id, 'valide');
            self::notifyValidated(CommandeRepository::find($id), $user, $accessToken);
            return;
        }

        CommandeRepository::setStatut($id, 'en_validation');
        $commande = CommandeRepository::find($id);
        $stage = (int) $slots[0]['etape'];
        $stillPending = array_filter($pending, static fn($v) => (int) $v['etape'] === $stage);

        if ($stillPending) {
            self::remindStage($commande, $user, $roleLabels, $accessToken);
            return;
        }

        // The stage is complete: the next one starts.
        self::notifyStageStart($commande, $user, $accessToken);
        if ($stage === 1 && (int) $demandeur['id'] !== (int) $user['id']) {
            self::send($accessToken, [$demandeur], $user, 'Validée par votre responsable — ' . $commande['numero_commande'], [
                'tone' => 'success',
                'eyebrow' => 'Étape validée',
                'title' => 'Votre responsable a validé votre commande',
                'intro' => MailTemplate::hello($demandeur['display_name'])
                    . '<strong>' . View::e($user['display_name']) . '</strong> a validé la commande '
                    . '<strong>' . View::e($commande['numero_commande']) . '</strong>. '
                    . 'Elle est maintenant transmise à la comptabilité et à la direction pour validation.',
                'commande' => $commande,
                'progress' => true,
                'cta' => ['label' => 'Suivre ma commande', 'url' => Url::to("/commandes/$id")],
            ]);
        }
    }

    /**
     * Marque comme passées les parts (fournisseurs) indiquées. La commande devient « Finalisée »
     * quand toutes ses parts le sont.
     *
     * @param array $executions parts que $executeur a le droit de marquer
     */
    public static function markExecuted(array $commande, array $executeur, array $executions, string $accessToken): void
    {
        if ($commande['statut'] !== 'valide') {
            throw new RuntimeException('Seule une commande validée peut être passée auprès des fournisseurs.');
        }
        if (!$executions) {
            throw new RuntimeException('Aucune part de cette commande ne vous est confiée.');
        }
        $id = (int) $commande['id'];
        foreach ($executions as $e) {
            CommandeRepository::markExecutionDone((int) $e['id'], (int) $executeur['id']);
        }
        $fournisseurs = implode(', ', array_map(static fn($e) => $e['fournisseur'], $executions));
        CommandeRepository::logEvent($id, (int) $executeur['id'], 'execution', 'Passée auprès de : ' . $fournisseurs);

        $commande = CommandeRepository::find($id);
        if (array_filter($commande['executions'], static fn($e) => $e['done_at'] === null)) {
            return;
        }

        CommandeRepository::markFinalized($id, (int) $executeur['id']);
        CommandeRepository::logEvent($id, (int) $executeur['id'], 'finalisation');
        $commande = CommandeRepository::find($id);
        $demandeur = UserRepository::findById((int) $commande['demandeur_id']);
        $parQui = array_values(array_unique(array_filter(array_map(static fn($e) => $e['done_nom'], $commande['executions']))));
        self::send($accessToken, [$demandeur], $executeur, 'Commande passée — ' . $commande['numero_commande'], [
            'tone' => 'teal',
            'eyebrow' => 'Commande passée',
            'title' => 'Votre commande a été passée auprès des fournisseurs',
            'intro' => MailTemplate::hello($demandeur['display_name'])
                . 'La commande <strong>' . View::e($commande['numero_commande']) . '</strong> a été entièrement passée auprès '
                . (count($commande['executions']) > 1 ? 'de ses fournisseurs' : 'du fournisseur')
                . ' par <strong>' . View::e(implode(', ', $parQui)) . '</strong>.',
            'commande' => $commande,
            'cta' => ['label' => 'Voir la commande', 'url' => Url::to("/commandes/$id")],
        ]);
    }

    /**
     * Répartit l'exécution d'une commande validée : une part par fournisseur, confiée à l'exécuteur
     * attitré du fournisseur, sinon à la comptabilité (ou aux exécuteurs s'il n'y a pas de comptabilité).
     */
    public static function createExecutions(int $commandeId): void
    {
        $commande = CommandeRepository::find($commandeId);
        if (!$commande || $commande['executions']) {
            return;
        }
        $suppliers = [];
        foreach (SupplierRepository::all() as $s) {
            $suppliers[mb_strtolower(trim($s['name']))] = $s;
        }
        $fallbackRole = UserRepository::findActiveByRole('comptabilite') ? 'comptabilite' : 'executeur';

        $rows = [];
        foreach (array_unique(array_map(static fn($l) => trim((string) $l['fournisseur']), $commande['lignes'])) as $fournisseur) {
            $supplier = $suppliers[mb_strtolower($fournisseur)] ?? null;
            $exec = $supplier && $supplier['executeur_id'] ? UserRepository::findById((int) $supplier['executeur_id']) : null;
            $rows[] = $exec && $exec['is_active']
                ? ['fournisseur' => $fournisseur, 'assigned_to' => (int) $exec['id'], 'role' => 'executeur']
                : ['fournisseur' => $fournisseur, 'assigned_to' => null, 'role' => $fallbackRole];
        }
        CommandeRepository::createExecutions($commandeId, $rows);
    }

    // --- Circuit ---------------------------------------------------------

    /**
     * Responsable désigné pour l'étape 1 : l'unique responsable du service, ou celui choisi par
     * le demandeur s'il y en a plusieurs. Aucun si le service n'en a pas ou si le demandeur en fait partie.
     *
     * @return array{0:?int,1:bool} [responsable désigné, demandeur est responsable]
     */
    private static function chooseResponsable(array $demandeur, ?int $serviceId, ?int $choisi): array
    {
        if (!$serviceId) {
            return [null, false];
        }
        $ids = array_map(static fn($u) => (int) $u['id'], ServiceRepository::responsablesOf($serviceId));
        if (!$ids) {
            return [null, false];
        }
        if (in_array((int) $demandeur['id'], $ids, true)) {
            return [null, true];
        }
        if (count($ids) === 1) {
            return [$ids[0], false];
        }
        if ($choisi && in_array($choisi, $ids, true)) {
            return [$choisi, false];
        }
        throw new RuntimeException('Choisissez le responsable de service qui validera votre commande.');
    }

    private static function buildSlots(?int $responsableId): array
    {
        $slots = [];
        if ($responsableId) {
            $slots[] = ['etape' => 1, 'role' => 'responsable_service', 'assigned_to' => $responsableId];
        }

        foreach (['comptabilite', 'chef_etablissement'] as $role) {
            if (UserRepository::findActiveByRole($role)) {
                $slots[] = ['etape' => 2, 'role' => $role, 'assigned_to' => null];
            }
        }
        foreach (UserRepository::findActiveByRole('validateur') as $v) {
            $slots[] = ['etape' => 2, 'role' => 'validateur', 'assigned_to' => (int) $v['id']];
        }

        return $slots;
    }

    private static function currentStage(array $validations): ?int
    {
        $stages = array_map(
            static fn($v) => (int) $v['etape'],
            array_filter($validations, static fn($v) => $v['decision'] === 'en_attente')
        );
        return $stages ? min($stages) : null;
    }

    /**
     * People expected to act on the pending slots of the current stage.
     * @return array<string,array{user:array,roles:string[]}> keyed by e-mail
     */
    private static function stageRecipients(array $commande): array
    {
        $stage = self::currentStage($commande['validations']);
        $recipients = [];
        foreach ($commande['validations'] as $v) {
            if ($v['decision'] !== 'en_attente' || (int) $v['etape'] !== $stage) {
                continue;
            }
            $users = $v['assigned_to']
                ? array_filter([UserRepository::findById((int) $v['assigned_to'])])
                : UserRepository::findActiveByRole($v['role']);
            foreach ($users as $u) {
                if (empty($u['is_active'])) {
                    continue;
                }
                $recipients[$u['email']]['user'] = $u;
                $recipients[$u['email']]['roles'][] = Roles::label($v['role']);
            }
        }
        return $recipients;
    }

    private static function notifyStageStart(array $commande, array $actor, string $accessToken): void
    {
        $id = (int) $commande['id'];
        $stage = self::currentStage($commande['validations']);
        foreach (self::stageRecipients($commande) as $r) {
            $intro = MailTemplate::hello($r['user']['display_name'])
                . '<strong>' . View::e($commande['demandeur_nom']) . '</strong>'
                . ($commande['service_nom'] ? ' (' . View::e($commande['service_nom']) . ')' : '')
                . ' a envoyé la demande d’achat <strong>' . View::e($commande['numero_commande']) . '</strong>';
            $intro .= $stage === 2 && self::hasStage($commande, 1)
                ? ', validée par son responsable de service. '
                : '. ';
            $intro .= 'Votre validation est requise en tant que <strong>'
                . View::e(implode(' et ', array_unique($r['roles']))) . '</strong>.';

            self::send($accessToken, [$r['user']], $actor, 'Commande à valider — ' . $commande['numero_commande'], [
                'tone' => 'primary',
                'eyebrow' => 'Validation requise',
                'title' => 'Une demande d’achat attend votre validation',
                'intro' => $intro,
                'commande' => $commande,
                'progress' => true,
                'cta' => ['label' => 'Examiner la commande', 'url' => Url::to("/commandes/$id")],
            ]);
        }
    }

    private static function remindStage(array $commande, array $actor, string $actorRoles, string $accessToken): void
    {
        $id = (int) $commande['id'];
        foreach (self::stageRecipients($commande) as $r) {
            self::send($accessToken, [$r['user']], $actor, 'Rappel : validation attendue — ' . $commande['numero_commande'], [
                'tone' => 'warning',
                'eyebrow' => 'Rappel',
                'title' => 'Il ne manque plus que votre validation',
                'intro' => MailTemplate::hello($r['user']['display_name'])
                    . '<strong>' . View::e($actor['display_name']) . '</strong> (' . View::e($actorRoles) . ') a validé la commande '
                    . '<strong>' . View::e($commande['numero_commande']) . '</strong>. Votre validation en tant que <strong>'
                    . View::e(implode(' et ', array_unique($r['roles']))) . '</strong> est encore attendue.',
                'commande' => $commande,
                'progress' => true,
                'cta' => ['label' => 'Valider la commande', 'url' => Url::to("/commandes/$id")],
            ]);
        }
    }

    private static function notifyValidated(array $commande, array $actor, string $accessToken): void
    {
        $id = (int) $commande['id'];
        $numero = $commande['numero_commande'];
        $demandeur = UserRepository::findById((int) $commande['demandeur_id']);

        self::createExecutions($id);
        $commande = CommandeRepository::find($id);
        foreach (self::executionRecipients($commande) as $r) {
            $parts = $r['fournisseurs'];
            self::send($accessToken, [$r['user']], $actor, 'Commande validée à passer — ' . $numero, [
                'tone' => 'success',
                'eyebrow' => 'À passer',
                'title' => 'Une commande validée est prête à être passée',
                'intro' => MailTemplate::hello($r['user']['display_name'])
                    . 'La commande <strong>' . View::e($numero) . '</strong> de <strong>' . View::e($commande['demandeur_nom'])
                    . '</strong> a obtenu toutes les validations. Vous êtes chargé(e) de la passer auprès de '
                    . '<strong>' . View::e(implode(', ', $parts)) . '</strong>, puis de la marquer comme passée dans l’application.',
                'commande' => $commande,
                'progress' => true,
                'cta' => ['label' => 'Ouvrir la commande', 'url' => Url::to("/commandes/$id")],
            ]);
        }

        self::send($accessToken, [$demandeur], $actor, 'Commande validée — ' . $numero, [
            'tone' => 'success',
            'eyebrow' => 'Commande validée',
            'title' => 'Bonne nouvelle, votre commande est validée',
            'intro' => MailTemplate::hello($demandeur['display_name'])
                . 'Votre commande <strong>' . View::e($numero) . '</strong> a obtenu toutes les validations. '
                . 'Elle va maintenant être passée auprès des fournisseurs.',
            'commande' => $commande,
            'progress' => true,
            'cta' => ['label' => 'Suivre ma commande', 'url' => Url::to("/commandes/$id")],
        ]);
    }

    /**
     * Personnes chargées des parts encore à passer, avec leurs fournisseurs.
     * @return array<string,array{user:array,fournisseurs:string[]}> indexé par e-mail
     */
    public static function executionRecipients(array $commande): array
    {
        $recipients = [];
        foreach ($commande['executions'] as $e) {
            if ($e['done_at'] !== null) {
                continue;
            }
            $users = $e['assigned_to']
                ? array_filter([UserRepository::findById((int) $e['assigned_to'])])
                : UserRepository::findActiveByRole($e['role']);
            foreach ($users as $u) {
                if (!empty($u['is_active'])) {
                    $recipients[$u['email']]['user'] = $u;
                    $recipients[$u['email']]['fournisseurs'][] = $e['fournisseur'];
                }
            }
        }
        return $recipients;
    }

    private static function hasStage(array $commande, int $stage): bool
    {
        foreach ($commande['validations'] as $v) {
            if ((int) $v['etape'] === $stage) {
                return true;
            }
        }
        return false;
    }

    /** Sends one e-mail, skipping the person who triggered it and inactive accounts. */
    private static function send(string $accessToken, array $users, array $actor, string $subject, array $template): void
    {
        $emails = [];
        foreach ($users as $u) {
            if ($u && !empty($u['is_active']) && strcasecmp($u['email'], $actor['email']) !== 0) {
                $emails[] = $u['email'];
            }
        }
        if (!$emails) {
            return;
        }
        $template['sender'] = $actor['display_name'];
        GraphMailer::send($accessToken, $emails, $subject, MailTemplate::render($template));
    }
}
