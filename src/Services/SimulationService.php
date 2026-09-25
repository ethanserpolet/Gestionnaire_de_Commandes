<?php
declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;
use App\Repositories\CommandeRepository;
use App\Repositories\DestinationRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UserRepository;
use App\Support\Roles;
use App\Support\View;

/**
 * Rejoue le circuit complet d'une commande avec la configuration réelle (services, responsables,
 * comptabilité, chef d'établissement, exécuteurs) en agissant tour à tour au nom de chacun.
 * Tout se déroule dans une transaction annulée à la fin et les e-mails sont interceptés :
 * aucune donnée n'est conservée et personne n'est réellement notifié.
 */
final class SimulationService
{
    public const SCENARIOS = [
        'complet' => 'Validation complète puis finalisation',
        'refus_responsable_service' => 'Refus par le responsable de service',
        'refus_comptabilite' => 'Refus par la comptabilité',
        'refus_chef_etablissement' => 'Refus par le chef d’établissement',
    ];

    private const TOKEN = 'simulation';
    private const MOTIF_REFUS = '[SIMULATION] Budget insuffisant pour ce trimestre.';

    private array $steps = [];
    private array $lastMails = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;

    public static function run(int $demandeurId, string $scenario, ?int $responsableChoisi): array
    {
        $sim = new self();
        $pdo = Database::connection();
        $start = microtime(true);

        GraphMailer::startCapture();
        $pdo->beginTransaction();
        try {
            $sim->scenario($demandeurId, $scenario, $responsableChoisi);
        } catch (\Throwable $e) {
            if (!$sim->steps) {
                $sim->step('Erreur', null, 'alert');
            }
            $sim->fail('Exception inattendue : ' . get_class($e) . ' — ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        } finally {
            $sim->collectMails();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            GraphMailer::stopCapture();
        }

        $sim->step('Nettoyage', null, 'history');
        $sim->ok('Transaction annulée : la commande de test, ses validations et son historique ont été effacés.');
        $sim->ok('Aucun e-mail n’a été réellement envoyé : ils ont tous été interceptés et sont affichés ci-dessus.');

        return [
            'scenario' => self::SCENARIOS[$scenario] ?? $scenario,
            'steps' => $sim->steps,
            'passed' => $sim->passed,
            'failed' => $sim->failed,
            'warnings' => $sim->warnings,
            'duration' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    // --- Scénario --------------------------------------------------------

    private function scenario(int $demandeurId, string $scenario, ?int $responsableChoisi): void
    {
        $refusRole = str_starts_with($scenario, 'refus_') ? substr($scenario, 6) : null;

        // 1. Préparation ----------------------------------------------------
        $this->step('Préparation du scénario', null, 'shield');
        $demandeur = UserRepository::findById($demandeurId);
        if (!$demandeur) {
            $this->fail('Demandeur introuvable.');
            return;
        }
        $this->info('Scénario : ' . (self::SCENARIOS[$scenario] ?? $scenario));
        $this->info('Demandeur simulé : ' . $this->who($demandeur));
        if (!in_array('demandeur', $demandeur['roles'], true)) {
            $this->warn('Cette personne n’a pas le rôle Demandeur : en réalité elle ne pourrait pas créer de commande (simulé quand même).');
        }

        $responsables = [];
        if (!empty($demandeur['service_id'])) {
            $this->info('Service : ' . $demandeur['service_nom']);
            $responsables = ServiceRepository::responsablesOf((int) $demandeur['service_id']);
            $this->info('Responsable(s) du service : ' . ($responsables ? $this->names($responsables) : 'aucun'));
        } else {
            $this->warn('Le demandeur n’a pas de service : l’étape « responsable de service » sera sautée.');
        }

        $respIds = array_map(static fn($u) => (int) $u['id'], $responsables);
        $choisi = null;
        $attendu = null;
        if ($respIds && in_array((int) $demandeur['id'], $respIds, true)) {
            $this->info('Le demandeur est lui-même responsable de son service : l’étape 1 doit être sautée.');
        } elseif (count($respIds) === 1) {
            $attendu = $respIds[0];
        } elseif (count($respIds) > 1) {
            $choisi = $responsableChoisi && in_array($responsableChoisi, $respIds, true) ? $responsableChoisi : $respIds[0];
            $attendu = $choisi;
            $this->info('Plusieurs responsables : le demandeur désigne ' . $this->nameOf($choisi)
                . ($responsableChoisi === $choisi ? '.' : ' (choisi automatiquement).'));
        }

        $parRole = [];
        foreach (['comptabilite', 'chef_etablissement', 'validateur', 'executeur', 'lecteur'] as $role) {
            $parRole[$role] = UserRepository::findActiveByRole($role);
            $label = Roles::label($role);
            if ($parRole[$role]) {
                $this->info("$label : " . $this->names($parRole[$role]));
            } elseif ($role === 'executeur') {
                $this->info("Aucun $label : les commandes seront passées par la comptabilité.");
            } elseif (in_array($role, ['comptabilite', 'chef_etablissement'], true)) {
                $this->warn("Aucun utilisateur « $label » : cette validation sera sautée.");
            } else {
                $this->info("$label : aucun");
            }
        }

        // 2. Brouillon ------------------------------------------------------
        $this->step('Création du brouillon', $demandeur, 'edit');
        $options = [];
        foreach (DestinationRepository::selectOptions() as $group) {
            $options = array_merge($options, $group['options']);
        }
        $dest1 = $options[0]['path'] ?? null;
        $dest2 = $options[1]['path'] ?? $dest1;
        if ($dest1 === null) {
            $this->warn('Aucune destination configurée : les demandeurs ne pourraient pas ajouter d’article. Une destination fictive est utilisée.');
            $dest1 = $dest2 = 'Destination de test';
        } else {
            $this->ok(count($options) . ' destination(s) sélectionnable(s) — utilisées : « ' . $dest1 . ' » et « ' . $dest2 . ' »');
        }
        $suppliers = SupplierRepository::allActive();
        $f1 = $suppliers[0]['name'] ?? 'Fournisseur test A';
        $f2 = $suppliers[1]['name'] ?? 'Fournisseur test B';

        $id = CommandeRepository::create((int) $demandeur['id']);
        CommandeRepository::logEvent($id, (int) $demandeur['id'], 'creation', 'Brouillon de simulation.');
        CommandeRepository::addLigne($id, [
            'fournisseur' => $f1, 'description' => '[SIMULATION] Câble HDMI 2 m', 'destination' => $dest1,
            'motif' => 'Remplacement', 'quantite' => 3, 'prix_unitaire' => 12.50, 'prix_ttc' => 37.50,
        ]);
        CommandeRepository::addLigne($id, [
            'fournisseur' => $f2, 'description' => '[SIMULATION] Écran 24 pouces', 'destination' => $dest2,
            'motif' => 'Nouvelle acquisition', 'quantite' => 1, 'prix_unitaire' => 189.00, 'prix_ttc' => 189.00,
        ]);
        $c = CommandeRepository::find($id);
        $total = array_sum(array_map(static fn($l) => (float) $l['prix_ttc'], $c['lignes']));
        $this->check($c['statut'] === 'brouillon', 'Statut « Brouillon »', 'Statut inattendu : ' . $c['statut']);
        $this->check(count($c['lignes']) === 2, '2 articles enregistrés (' . $f1 . ', ' . $f2 . ')', count($c['lignes']) . ' article(s) enregistré(s) au lieu de 2');
        $this->check(abs($total - 226.50) < 0.01, 'Total TTC correct : ' . View::money($total), 'Total TTC incorrect : ' . View::money($total));

        // 3. Envoi ----------------------------------------------------------
        $this->step('Envoi pour validation', $demandeur, 'send');
        CommandeService::submit($c, $demandeur, $choisi, self::TOKEN);
        $this->collectMails();
        $c = CommandeRepository::find($id);
        $this->check((bool) $c['numero_commande'], 'Numéro attribué : ' . $c['numero_commande'], 'Aucun numéro de commande attribué');
        $this->check($c['submitted_at'] !== null, 'Signature électronique du demandeur horodatée', 'Date d’envoi manquante');

        $prevus = ($attendu ? 1 : 0)
            + ($parRole['comptabilite'] ? 1 : 0)
            + ($parRole['chef_etablissement'] ? 1 : 0)
            + count($parRole['validateur']);
        $this->check(
            count($c['validations']) === $prevus,
            'Circuit conforme : ' . $prevus . ' validation(s) prévue(s)',
            'Circuit inattendu : ' . count($c['validations']) . ' validation(s) au lieu de ' . $prevus
        );
        foreach ($c['validations'] as $v) {
            $this->info(sprintf(
                'Étape %d · %s · %s',
                $v['etape'],
                Roles::label($v['role']),
                $v['assigned_nom'] ? 'désigné : ' . $v['assigned_nom'] : 'ouvert à tous les membres du rôle'
            ));
        }
        if ($attendu) {
            $slot1 = array_values(array_filter($c['validations'], static fn($v) => (int) $v['etape'] === 1))[0] ?? null;
            $this->check(
                $slot1 && (int) $slot1['assigned_to'] === $attendu,
                'Étape 1 confiée à ' . $this->nameOf($attendu),
                'L’étape 1 n’est pas confiée au responsable attendu (' . $this->nameOf($attendu) . ')'
            );
        }

        if (!$c['validations']) {
            $this->check($c['statut'] === 'valide', 'Aucun validateur configuré : commande validée automatiquement', 'Statut inattendu : ' . $c['statut']);
        } else {
            $this->check($c['statut'] === 'en_attente', 'Statut « En attente »', 'Statut inattendu : ' . $c['statut']);
            $this->expectStageMails($c, $demandeur, false);
            $this->checkStageLock($c);
        }

        // 4. Décisions successives ------------------------------------------
        $refusApplique = false;
        for ($guard = 0; $guard < 20 && in_array($c['statut'], ['en_attente', 'en_validation'], true); $guard++) {
            $stage = $this->currentStage($c);
            if ($stage === null) {
                $this->step('Incohérence', null, 'alert');
                $this->fail('Statut « ' . $c['statut'] . ' » alors qu’aucune validation n’est en attente.');
                return;
            }
            $slot = $this->pendingSlots($c, $stage)[0];
            $label = Roles::label($slot['role']);
            $actor = $this->actorFor($slot, $demandeur);
            $refuse = $refusRole === $slot['role'];

            $this->step(($refuse ? 'Refus' : 'Validation') . " — $label (étape $stage)", $actor, $refuse ? 'x-circle' : 'check-circle');
            if (!$actor) {
                $this->fail("Aucun utilisateur actif ne peut valider « $label » : la commande resterait bloquée.");
                return;
            }

            $slots = CommandeRepository::actionableSlotsFor($id, $actor);
            $this->check((bool) $slots, $actor['display_name'] . ' voit la commande dans « À valider » et peut décider', $actor['display_name'] . ' ne peut pas agir sur cette commande');
            if (!$slots) {
                return;
            }
            if (count($slots) > 1) {
                $this->info('Cette personne cumule plusieurs rôles : sa décision couvre '
                    . implode(', ', array_map(static fn($s) => Roles::label($s['role']), $slots)) . '.');
            }

            CommandeService::decide($c, $actor, $slots, $refuse ? 'refuse' : 'valide', $refuse ? self::MOTIF_REFUS : null, self::TOKEN);
            $this->collectMails();
            $c = CommandeRepository::find($id);

            $ids = array_map(static fn($s) => (int) $s['id'], $slots);
            $enregistrees = array_filter($c['validations'], static fn($v) => in_array((int) $v['id'], $ids, true)
                && $v['decision'] === ($refuse ? 'refuse' : 'valide')
                && (int) $v['validateur_id'] === (int) $actor['id']);
            $this->check(count($enregistrees) === count($slots), 'Décision enregistrée et signée par ' . $actor['display_name'], 'Décision non enregistrée correctement');

            if ($refuse) {
                $refusApplique = true;
                $this->check($c['statut'] === 'refuse', 'Statut « Refusé »', 'Statut inattendu : ' . $c['statut']);
                $this->check($c['motif_refus'] === self::MOTIF_REFUS, 'Motif du refus enregistré', 'Motif du refus absent');
                $this->expectMailTo($demandeur, $actor, 'e-mail « commande refusée » au demandeur');
                foreach ($this->pendingSlots($c, null) as $restant) {
                    $autre = $this->actorFor($restant, $demandeur);
                    if ($autre) {
                        $this->check(
                            !CommandeRepository::actionableSlotsFor($id, $autre),
                            $autre['display_name'] . ' (' . Roles::label($restant['role']) . ') ne peut plus valider',
                            $autre['display_name'] . ' peut encore valider une commande refusée'
                        );
                    }
                }
                break;
            }

            if (!$this->pendingSlots($c, null)) {
                $this->check($c['statut'] === 'valide', 'Toutes les validations obtenues : statut « Validé »', 'Statut inattendu : ' . $c['statut']);
                $this->checkExecutionPlan($c, $actor);
                $this->expectMailTo($demandeur, $actor, 'e-mail « commande validée » au demandeur');
            } else {
                $this->check($c['statut'] === 'en_validation', 'Statut « En validation »', 'Statut inattendu : ' . $c['statut']);
                $nouvelle = $this->currentStage($c);
                if ($nouvelle !== $stage) {
                    $this->ok("Étape $stage terminée : ouverture de l’étape $nouvelle");
                    $this->expectStageMails($c, $actor, false);
                    if ($stage === 1) {
                        $this->expectMailTo($demandeur, $actor, 'e-mail « validée par votre responsable » au demandeur');
                    }
                } else {
                    $this->info('Encore attendu à cette étape : '
                        . implode(', ', array_map(static fn($s) => Roles::label($s['role']), $this->pendingSlots($c, $stage))) . '.');
                    $this->expectStageMails($c, $actor, true);
                }
            }
        }

        if ($refusRole && !$refusApplique) {
            $this->step('Refus demandé par le scénario', null, 'alert');
            $this->warn('Le scénario prévoyait un refus par « ' . Roles::label($refusRole)
                . ' » mais cette étape n’existe pas dans le circuit actuel : la commande a suivi le circuit normal.');
        }
        if ($c['statut'] === 'refuse') {
            return;
        }

        // 5. Passage auprès des fournisseurs, part par part -------------------
        if ($c['statut'] !== 'valide') {
            $this->step('Passage des commandes', null, 'package');
            $this->fail('La commande n’a pas atteint le statut « Validé » (statut : ' . $c['statut'] . ').');
            return;
        }
        if (!$c['validations']) {
            // Validée dès l'envoi : le plan d'exécution n'a pas encore été contrôlé.
            $this->checkExecutionPlan($c, $demandeur);
        }

        for ($guard = 0; $guard < 20 && $c['statut'] === 'valide'; $guard++) {
            $part = array_values(array_filter($c['executions'], static fn($e) => $e['done_at'] === null))[0] ?? null;
            if (!$part) {
                $this->step('Passage des commandes', null, 'alert');
                $this->fail('Toutes les parts sont passées mais la commande n’est pas finalisée.');
                return;
            }
            $actor = $this->executionActor($part, $demandeur);
            $this->step('Passage — ' . $part['fournisseur'], $actor, 'package');
            if (!$actor) {
                $this->fail('Personne ne peut passer la part « ' . $part['fournisseur'] . ' » : la commande resterait bloquée.');
                return;
            }
            $mesParts = CommandeRepository::actionableExecutionsFor($id, $actor);
            $this->check((bool) $mesParts, $actor['display_name'] . ' voit la commande dans « À passer »', $actor['display_name'] . ' ne peut pas marquer cette part');
            if (!$mesParts) {
                return;
            }
            if (count($mesParts) > 1) {
                $this->info('Cette personne est chargée de plusieurs fournisseurs : ' . implode(', ', array_column($mesParts, 'fournisseur')) . ' (tout marqué en une fois).');
            }

            CommandeService::markExecuted($c, $actor, $mesParts, self::TOKEN);
            $this->collectMails();
            $c = CommandeRepository::find($id);
            $ids = array_map(static fn($e) => (int) $e['id'], $mesParts);
            $ok = array_filter($c['executions'], static fn($e) => in_array((int) $e['id'], $ids, true) && (int) $e['done_by'] === (int) $actor['id']);
            $this->check(count($ok) === count($mesParts), 'Part(s) enregistrée(s) comme passée(s) par ' . $actor['display_name'], 'Part non enregistrée');

            $restantes = array_filter($c['executions'], static fn($e) => $e['done_at'] === null);
            if ($restantes) {
                $this->check($c['statut'] === 'valide', 'Encore ' . count($restantes) . ' part(s) à passer : la commande reste « Validée »', 'Statut inattendu : ' . $c['statut']);
            } else {
                $this->check($c['statut'] === 'finalise', 'Toutes les parts passées : statut « Finalisé »', 'Statut inattendu : ' . $c['statut']);
                $this->expectMailTo($demandeur, $actor, 'e-mail « commande passée » au demandeur');
            }
        }
        $this->info(count($c['events']) . ' événement(s) enregistré(s) dans l’historique de la commande.');
    }

    /** Vérifie la répartition par fournisseur et les e-mails « à passer ». */
    private function checkExecutionPlan(array $c, array $actor): void
    {
        $fournisseurs = array_unique(array_map(static fn($l) => trim((string) $l['fournisseur']), $c['lignes']));
        $this->check(
            count($c['executions']) === count($fournisseurs),
            'Exécution répartie : ' . count($c['executions']) . ' part(s), une par fournisseur',
            count($c['executions']) . ' part(s) créée(s) pour ' . count($fournisseurs) . ' fournisseur(s)'
        );
        foreach ($c['executions'] as $e) {
            $this->info('« ' . $e['fournisseur'] . ' » → ' . ($e['assigned_nom']
                ? $e['assigned_nom'] . ' (exécuteur attitré)'
                : Roles::label($e['role']) . ' (fournisseur sans exécuteur attitré)'));
        }
        foreach (CommandeService::executionRecipients($c) as $r) {
            $this->expectMailTo($r['user'], $actor, 'e-mail « à passer » (' . implode(', ', $r['fournisseurs']) . ') pour ' . $r['user']['display_name']);
        }
    }

    private function executionActor(array $part, array $demandeur): ?array
    {
        if ($part['assigned_to']) {
            $u = UserRepository::findById((int) $part['assigned_to']);
            return $u && $u['is_active'] ? $u : null;
        }
        $users = UserRepository::findActiveByRole($part['role']);
        usort($users, static fn($a, $b) => ((int) $a['id'] === (int) $demandeur['id']) <=> ((int) $b['id'] === (int) $demandeur['id']));
        return $users ? UserRepository::findById((int) $users[0]['id']) : null;
    }

    // --- Vérifications de configuration (sans rien exécuter) --------------

    /** @return array<int,array{type:string,text:string}> */
    public static function preflight(): array
    {
        $items = [];
        $add = static function (string $type, string $text) use (&$items): void {
            $items[] = ['type' => $type, 'text' => $text];
        };

        $add('info', 'PHP ' . PHP_VERSION);
        foreach (['pdo_mysql', 'curl', 'fileinfo', 'mbstring'] as $ext) {
            extension_loaded($ext) ? $add('ok', "Extension $ext chargée") : $add('fail', "Extension PHP $ext manquante");
        }
        foreach (['ENTRA_APP_ID_CLIENT', 'ENTRA_APP_SECRET_VALUE', 'ENTRA_APP_ID_LOCATAIRE', 'ENTRA_REDIRECT_URI'] as $key) {
            Config::get($key) ? $add('ok', "$key renseigné") : $add('fail', "$key manquant dans .env");
        }
        if (Config::get('APP_DEBUG') === '1') {
            $add('warn', 'APP_DEBUG=1 : les détails techniques des erreurs sont affichés. À désactiver en production.');
        }

        $storage = UploadService::storageRoot();
        $writable = is_dir($storage) ? is_writable($storage) : is_writable(dirname($storage));
        $writable ? $add('ok', 'Dossier des pièces jointes accessible en écriture') : $add('fail', "Dossier $storage non accessible en écriture : les pièces jointes échoueront");

        $services = ServiceRepository::all();
        $add($services ? 'ok' : 'warn', count($services) . ' service(s) configuré(s)');
        foreach ($services as $s) {
            if (!$s['responsable_ids']) {
                $add('warn', 'Service « ' . $s['name'] . ' » sans responsable : ses commandes sautent l’étape 1');
            }
        }

        $users = array_filter(UserRepository::all(), static fn($u) => (bool) $u['is_active']);
        $sansService = array_filter($users, static fn($u) => empty($u['service_id']));
        $sansRole = array_filter($users, static fn($u) => !$u['roles'] && !$u['is_admin']);
        $add('info', count($users) . ' compte(s) actif(s)');
        if ($sansService && $services) {
            $add('warn', count($sansService) . ' compte(s) sans service (ils devront le choisir à leur prochaine visite)');
        }
        if ($sansRole) {
            $add('warn', count($sansRole) . ' compte(s) sans aucun rôle : ' . implode(', ', array_map(static fn($u) => $u['display_name'], $sansRole)));
        }

        $responsablesAffectes = [];
        foreach ($services as $s) {
            $responsablesAffectes = array_merge($responsablesAffectes, $s['responsable_ids']);
        }
        foreach (['demandeur', 'responsable_service', 'comptabilite', 'chef_etablissement', 'executeur', 'lecteur'] as $role) {
            $list = UserRepository::findActiveByRole($role);
            $label = Roles::label($role);
            if (!$list) {
                $type = $role === 'demandeur' ? 'fail' : (in_array($role, ['comptabilite', 'chef_etablissement'], true) ? 'warn' : 'info');
                $add($type, "Aucun utilisateur « $label »");
                continue;
            }
            $add('ok', "$label : " . implode(', ', array_map(static fn($u) => $u['display_name'], $list)));
            if ($role === 'responsable_service') {
                foreach ($list as $u) {
                    if (!in_array((int) $u['id'], $responsablesAffectes, true)) {
                        $add('warn', $u['display_name'] . ' a le rôle Responsable de service mais n’est rattaché à aucun service');
                    }
                }
            }
        }

        $nbDest = 0;
        foreach (DestinationRepository::selectOptions() as $group) {
            $nbDest += count($group['options']);
        }
        $add($nbDest ? 'ok' : 'fail', $nbDest ? "$nbDest destination(s) sélectionnable(s)" : 'Aucune destination : impossible d’ajouter un article');
        $fournisseurs = SupplierRepository::all();
        $avecExec = array_filter($fournisseurs, static fn($s) => $s['executeur_id'] && $s['executeur_actif']);
        $add($fournisseurs ? 'ok' : 'info', count($fournisseurs) . ' fournisseur(s), dont ' . count($avecExec) . ' avec un exécuteur attitré');
        foreach ($fournisseurs as $s) {
            if ($s['executeur_id'] && !$s['executeur_actif']) {
                $add('warn', 'L’exécuteur attitré de « ' . $s['name'] . ' » est désactivé : la comptabilité prend le relais');
            }
        }
        if (count($avecExec) < count($fournisseurs) && !UserRepository::findActiveByRole('comptabilite') && !UserRepository::findActiveByRole('executeur')) {
            $add('fail', 'Ni comptabilité ni exécuteur : les fournisseurs sans exécuteur attitré ne pourront pas être passés');
        }
        if (Config::list('APP_BLOCKED_GROUPS')) {
            $add('ok', 'Connexion refusée aux membres de : ' . implode(', ', Config::list('APP_BLOCKED_GROUPS')));
        } else {
            $add('warn', 'APP_BLOCKED_GROUPS vide : aucun groupe (ex. élèves) n’est bloqué à la connexion');
        }

        return $items;
    }

    // --- Outils ----------------------------------------------------------

    private function currentStage(array $c): ?int
    {
        $stages = array_map(static fn($v) => (int) $v['etape'], $this->pendingSlots($c, null));
        return $stages ? min($stages) : null;
    }

    private function pendingSlots(array $c, ?int $stage): array
    {
        return array_values(array_filter(
            $c['validations'],
            static fn($v) => $v['decision'] === 'en_attente' && ($stage === null || (int) $v['etape'] === $stage)
        ));
    }

    /** People who can act on a slot (assigned person, or every active member of the role). */
    private function slotUsers(array $slot): array
    {
        if ($slot['assigned_to']) {
            $u = UserRepository::findById((int) $slot['assigned_to']);
            return $u && $u['is_active'] ? [$u] : [];
        }
        return UserRepository::findActiveByRole($slot['role']);
    }

    private function actorFor(array $slot, array $demandeur): ?array
    {
        $users = $this->slotUsers($slot);
        usort($users, static fn($a, $b) => ((int) $a['id'] === (int) $demandeur['id']) <=> ((int) $b['id'] === (int) $demandeur['id']));
        return $users ? UserRepository::findById((int) $users[0]['id']) : null;
    }

    private function checkStageLock(array $c): void
    {
        $stage2 = array_values(array_filter($c['validations'], static fn($v) => (int) $v['etape'] === 2));
        $hasStage1 = (bool) array_filter($c['validations'], static fn($v) => (int) $v['etape'] === 1);
        if (!$hasStage1 || !$stage2) {
            return;
        }
        $users = $this->slotUsers($stage2[0]);
        if ($users) {
            $u = UserRepository::findById((int) $users[0]['id']);
            $this->check(
                !CommandeRepository::actionableSlotsFor((int) $c['id'], $u),
                'Étape 2 verrouillée : ' . $u['display_name'] . ' ne peut pas encore valider',
                $u['display_name'] . ' peut valider avant le responsable de service'
            );
        }
    }

    private function expectStageMails(array $c, array $actor, bool $reminder): void
    {
        $stage = $this->currentStage($c);
        foreach ($this->pendingSlots($c, $stage) as $slot) {
            foreach ($this->slotUsers($slot) as $u) {
                $this->expectMailTo($u, $actor, ($reminder ? 'rappel' : 'e-mail « à valider »') . ' pour ' . $u['display_name'] . ' (' . Roles::label($slot['role']) . ')');
            }
        }
    }

    private function expectMailTo(array $user, array $actor, string $what): void
    {
        if (strcasecmp($user['email'], $actor['email']) === 0) {
            $this->info(ucfirst($what) . ' : non envoyé car c’est la personne qui agit (normal)');
            return;
        }
        $sent = false;
        foreach ($this->lastMails as $mail) {
            foreach ($mail['to'] as $to) {
                if (strcasecmp($to, $user['email']) === 0) {
                    $sent = true;
                }
            }
        }
        $this->check($sent, ucfirst($what) . ' → ' . $user['email'], ucfirst($what) . ' : aucun e-mail pour ' . $user['email']);
    }

    private function who(array $u): string
    {
        return $u['display_name'] . ' <' . $u['email'] . '>'
            . ($u['roles'] ? ' — ' . implode(', ', array_map([Roles::class, 'label'], $u['roles'])) : '');
    }

    private function names(array $users): string
    {
        return implode(', ', array_map(static fn($u) => $u['display_name'], $users));
    }

    private function nameOf(int $id): string
    {
        return UserRepository::findById($id)['display_name'] ?? "#$id";
    }

    private function step(string $title, ?array $actor = null, string $icon = 'circle'): void
    {
        $this->steps[] = [
            'title' => $title,
            'actor' => $actor ? $actor['display_name'] . ' (' . ($actor['roles'] ? implode(', ', array_map([Roles::class, 'label'], $actor['roles'])) : 'aucun rôle') . ')' : null,
            'icon' => $icon,
            'lines' => [],
            'mails' => [],
        ];
    }

    private function collectMails(): void
    {
        $this->lastMails = GraphMailer::drainCaptured();
        if ($this->steps && $this->lastMails) {
            $i = array_key_last($this->steps);
            $this->steps[$i]['mails'] = array_merge($this->steps[$i]['mails'], $this->lastMails);
        }
    }

    private function line(string $type, string $text): void
    {
        if (!$this->steps) {
            $this->step('Simulation');
        }
        $this->steps[array_key_last($this->steps)]['lines'][] = ['type' => $type, 'text' => $text];
    }

    private function check(bool $condition, string $ok, string $fail): void
    {
        $condition ? $this->ok($ok) : $this->fail($fail);
    }

    private function ok(string $text): void
    {
        $this->passed++;
        $this->line('ok', $text);
    }

    private function fail(string $text): void
    {
        $this->failed++;
        $this->line('fail', $text);
    }

    private function warn(string $text): void
    {
        $this->warnings++;
        $this->line('warn', $text);
    }

    private function info(string $text): void
    {
        $this->line('info', $text);
    }
}
