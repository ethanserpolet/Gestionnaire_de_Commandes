<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CommandeRepository
{
    public static function create(int $demandeurId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO commandes (demandeur_id, statut, date_demande) VALUES (?, ?, CURDATE())'
        );
        $stmt->execute([$demandeurId, 'brouillon']);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT c.*, u.display_name AS demandeur_nom, u.email AS demandeur_email,
                    f.display_name AS finalise_par_nom, s.name AS service_nom
             FROM commandes c
             JOIN users u ON u.id = c.demandeur_id
             LEFT JOIN users f ON f.id = c.finalized_by
             LEFT JOIN services s ON s.id = c.service_id
             WHERE c.id = ?'
        );
        $stmt->execute([$id]);
        $commande = $stmt->fetch();
        if (!$commande) {
            return null;
        }

        $commande['lignes'] = self::lignesFor($id);
        $commande['validations'] = self::validationsFor($id);
        $commande['executions'] = self::executionsFor($id);
        $commande['events'] = self::eventsFor($id);
        return $commande;
    }

    // --- Exécution par fournisseur ------------------------------------------

    public static function executionsFor(int $commandeId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT e.*, a.display_name AS assigned_nom, a.email AS assigned_email, d.display_name AS done_nom
             FROM commande_executions e
             LEFT JOIN users a ON a.id = e.assigned_to
             LEFT JOIN users d ON d.id = e.done_by
             WHERE e.commande_id = ? ORDER BY e.fournisseur, e.id'
        );
        $stmt->execute([$commandeId]);
        return $stmt->fetchAll();
    }

    /** @param array<int,array{fournisseur:string,assigned_to:?int,role:string}> $rows */
    public static function createExecutions(int $commandeId, array $rows): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO commande_executions (commande_id, fournisseur, assigned_to, role) VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $stmt->execute([$commandeId, $row['fournisseur'], $row['assigned_to'], $row['role']]);
        }
    }

    public static function markExecutionDone(int $executionId, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE commande_executions SET done_by = ?, done_at = NOW() WHERE id = ? AND done_at IS NULL')
            ->execute([$userId, $executionId]);
    }

    /** Condition (alias c / e) : parts à passer que cet utilisateur peut marquer comme passées. */
    private static function executionCondition(array $user): array
    {
        $sql = "e.done_at IS NULL AND c.statut = 'valide' AND (e.assigned_to = ?";
        $params = [(int) $user['id']];
        $roles = $user['roles'] ?? [];
        if ($roles) {
            $sql .= ' OR (e.assigned_to IS NULL AND e.role IN (' . implode(',', array_fill(0, count($roles), '?')) . '))';
            $params = array_merge($params, $roles);
        }
        return [$sql . ')', $params];
    }

    public static function actionableExecutionsFor(int $commandeId, array $user): array
    {
        [$cond, $params] = self::executionCondition($user);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT e.* FROM commande_executions e JOIN commandes c ON c.id = e.commande_id
             WHERE e.commande_id = ? AND $cond ORDER BY e.fournisseur"
        );
        $stmt->execute(array_merge([$commandeId], $params));
        return $stmt->fetchAll();
    }

    public static function listAwaitingExecution(array $user): array
    {
        [$cond, $params] = self::executionCondition($user);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT c.*, " . self::AGGREGATES . " FROM commandes c
             WHERE EXISTS (SELECT 1 FROM commande_executions e WHERE e.commande_id = c.id AND $cond)
             ORDER BY c.updated_at ASC"
        );
        $stmt->execute($params);
        return self::withDemandeurNames($stmt->fetchAll());
    }

    public static function lignesFor(int $commandeId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT * FROM commande_lignes WHERE commande_id = ? ORDER BY ordre, id'
        );
        $stmt->execute([$commandeId]);
        $lignes = $stmt->fetchAll();

        foreach ($lignes as &$ligne) {
            $f = $pdo->prepare('SELECT * FROM commande_ligne_fichiers WHERE commande_ligne_id = ? ORDER BY id');
            $f->execute([$ligne['id']]);
            $ligne['fichiers'] = $f->fetchAll();
        }
        return $lignes;
    }

    public static function validationsFor(int $commandeId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT cv.*,
                    d.display_name AS validateur_nom, d.email AS validateur_email,
                    a.display_name AS assigned_nom, a.email AS assigned_email
             FROM commande_validations cv
             LEFT JOIN users d ON d.id = cv.validateur_id
             LEFT JOIN users a ON a.id = cv.assigned_to
             WHERE cv.commande_id = ? ORDER BY cv.etape, cv.id'
        );
        $stmt->execute([$commandeId]);
        return $stmt->fetchAll();
    }

    public static function eventsFor(int $commandeId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT e.*, u.display_name AS user_nom
             FROM commande_events e LEFT JOIN users u ON u.id = e.user_id
             WHERE e.commande_id = ? ORDER BY e.id'
        );
        $stmt->execute([$commandeId]);
        return $stmt->fetchAll();
    }

    public static function addLigne(int $commandeId, array $data): int
    {
        $pdo = Database::connection();
        $pos = $pdo->prepare('SELECT COALESCE(MAX(ordre), -1) + 1 FROM commande_lignes WHERE commande_id = ?');
        $pos->execute([$commandeId]);
        $ordre = (int) $pos->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO commande_lignes
                (commande_id, fournisseur, description, destination, motif, quantite, prix_unitaire, prix_ttc, ordre)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $commandeId,
            $data['fournisseur'],
            $data['description'] ?: null,
            $data['destination'],
            $data['motif'] ?: null,
            $data['quantite'],
            $data['prix_unitaire'],
            $data['prix_ttc'],
            $ordre,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function deleteLigne(int $ligneId, int $commandeId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM commande_lignes WHERE id = ? AND commande_id = ?');
        $stmt->execute([$ligneId, $commandeId]);
    }

    public static function ligneBelongsTo(int $ligneId, int $commandeId): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT 1 FROM commande_lignes WHERE id = ? AND commande_id = ?');
        $stmt->execute([$ligneId, $commandeId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function addFichier(int $ligneId, int $uploadedBy, string $nomOriginal, string $nomStocke, string $mime, int $taille): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO commande_ligne_fichiers (commande_ligne_id, nom_original, nom_stocke, mime_type, taille_octets, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ligneId, $nomOriginal, $nomStocke, $mime, $taille, $uploadedBy]);
    }

    public static function findFichier(int $fichierId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT f.*, l.commande_id FROM commande_ligne_fichiers f
             JOIN commande_lignes l ON l.id = f.commande_ligne_id
             WHERE f.id = ?'
        );
        $stmt->execute([$fichierId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function deleteFichier(int $fichierId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM commande_ligne_fichiers WHERE id = ?')->execute([$fichierId]);
    }

    public static function setStatut(int $id, string $statut): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE commandes SET statut = ?, updated_at = now() WHERE id = ?')->execute([$statut, $id]);
    }

    public static function markSubmitted(int $id, string $numero, ?int $serviceId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE commandes SET statut = 'en_attente', numero_commande = ?, service_id = ?,
                    submitted_at = now(), updated_at = now() WHERE id = ?"
        );
        $stmt->execute([$numero, $serviceId, $id]);
    }

    public static function markRefused(int $id, string $motif): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE commandes SET statut = 'refuse', motif_refus = ?, updated_at = now() WHERE id = ?"
        );
        $stmt->execute([$motif, $id]);
    }

    public static function markFinalized(int $id, int $byUserId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE commandes SET statut = 'finalise', finalized_at = now(), finalized_by = ?, updated_at = now() WHERE id = ?"
        );
        $stmt->execute([$byUserId, $id]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM commandes WHERE id = ?')->execute([$id]);
    }

    public static function numeroFor(int $commandeId): string
    {
        return sprintf('BC-%s-%04d', date('Y'), $commandeId);
    }

    /** @param array<int,array{etape:int,role:string,assigned_to:?int}> $slots */
    public static function createSlots(int $commandeId, array $slots): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO commande_validations (commande_id, etape, role, assigned_to) VALUES (?, ?, ?, ?)'
        );
        foreach ($slots as $slot) {
            $stmt->execute([$commandeId, $slot['etape'], $slot['role'], $slot['assigned_to']]);
        }
    }

    public static function recordDecision(int $slotId, int $userId, string $decision, ?string $commentaire): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE commande_validations SET decision = ?, commentaire = ?, validateur_id = ?, decided_at = now()
             WHERE id = ? AND decision = 'en_attente'"
        );
        $stmt->execute([$decision, $commentaire, $userId, $slotId]);
    }

    /**
     * SQL condition (aliases c / cv) matching the slots this user can decide right now:
     * pending, in the lowest unfinished stage, and assigned to them or open to one of their roles.
     *
     * @return array{0:string,1:array}
     */
    private static function actionableCondition(array $user): array
    {
        $sql = "cv.decision = 'en_attente'
            AND c.statut IN ('en_attente', 'en_validation')
            AND NOT EXISTS (
                SELECT 1 FROM commande_validations p
                WHERE p.commande_id = cv.commande_id AND p.etape < cv.etape AND p.decision <> 'valide'
            )
            AND (cv.assigned_to = ?";
        $params = [(int) $user['id']];

        $roles = array_values(array_intersect($user['roles'] ?? [], \App\Support\Roles::VALIDATORS));
        if ($roles) {
            $sql .= ' OR (cv.assigned_to IS NULL AND cv.role IN (' . implode(',', array_fill(0, count($roles), '?')) . '))';
            $params = array_merge($params, $roles);
        }
        return [$sql . ')', $params];
    }

    public static function actionableSlotsFor(int $commandeId, array $user): array
    {
        [$cond, $params] = self::actionableCondition($user);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT cv.* FROM commande_validations cv JOIN commandes c ON c.id = cv.commande_id
             WHERE cv.commande_id = ? AND $cond ORDER BY cv.etape, cv.id"
        );
        $stmt->execute(array_merge([$commandeId], $params));
        return $stmt->fetchAll();
    }

    public static function logEvent(int $commandeId, ?int $userId, string $type, ?string $details = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO commande_events (commande_id, user_id, type, details) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$commandeId, $userId, $type, $details]);
    }

    // --- Listing helpers -------------------------------------------------

    public static function listForDemandeur(int $userId): array
    {
        return self::listWhere('demandeur_id = ?', [$userId]);
    }

    private const AGGREGATES = "
        (SELECT COALESCE(SUM(l.prix_ttc), 0) FROM commande_lignes l WHERE l.commande_id = c.id) AS total,
        (SELECT COUNT(*) FROM commande_lignes l WHERE l.commande_id = c.id) AS nb_lignes,
        (SELECT GROUP_CONCAT(DISTINCT l.fournisseur ORDER BY l.fournisseur SEPARATOR ', ')
            FROM commande_lignes l WHERE l.commande_id = c.id) AS fournisseurs,
        (SELECT GROUP_CONCAT(DISTINCT l.destination ORDER BY l.destination SEPARATOR '|')
            FROM commande_lignes l WHERE l.commande_id = c.id) AS destinations,
        (SELECT s.name FROM services s WHERE s.id = c.service_id) AS service_nom";

    public static function listAwaitingValidateur(array $user): array
    {
        [$cond, $params] = self::actionableCondition($user);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT c.*, " . self::AGGREGATES . " FROM commandes c
             WHERE EXISTS (
                SELECT 1 FROM commande_validations cv WHERE cv.commande_id = c.id AND $cond
             )
             ORDER BY c.submitted_at ASC"
        );
        $stmt->execute($params);
        return self::withDemandeurNames($stmt->fetchAll());
    }

    public static function listHistoryForValidateur(int $validateurId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.*, " . self::AGGREGATES . " FROM commandes c
             JOIN commande_validations cv ON cv.commande_id = c.id
             WHERE cv.validateur_id = ? AND cv.decision <> 'en_attente'
             ORDER BY c.updated_at DESC"
        );
        $stmt->execute([$validateurId]);
        return self::withDemandeurNames($stmt->fetchAll());
    }

    public static function listByStatuses(array $statuses): array
    {
        if (empty($statuses)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        return self::listWhere("statut IN ($placeholders)", $statuses);
    }

    public static function listAll(): array
    {
        return self::listWhere('1=1', []);
    }

    private static function listWhere(string $where, array $params): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT c.*, " . self::AGGREGATES . " FROM commandes c WHERE $where ORDER BY c.created_at DESC"
        );
        $stmt->execute($params);
        return self::withDemandeurNames($stmt->fetchAll());
    }

    private static function withDemandeurNames(array $commandes): array
    {
        if (empty($commandes)) {
            return [];
        }
        $pdo = Database::connection();
        $ids = array_unique(array_column($commandes, 'demandeur_id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        $names = array_column($stmt->fetchAll(), 'display_name', 'id');

        foreach ($commandes as &$c) {
            $c['demandeur_nom'] = $names[$c['demandeur_id']] ?? '?';
        }
        return $commandes;
    }
}
