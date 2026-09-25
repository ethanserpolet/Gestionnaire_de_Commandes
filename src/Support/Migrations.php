<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/** Idempotent upgrades for databases created with an older schema.sql. */
final class Migrations
{
    /** @return string[] human-readable log */
    public static function run(PDO $pdo): array
    {
        $log = [];

        $pdo->exec('ALTER TABLE roles MODIFY code VARCHAR(30) NOT NULL, MODIFY label VARCHAR(60) NOT NULL');
        $pdo->exec("INSERT IGNORE INTO roles (code, label) VALUES
            ('responsable_service', 'Responsable de service'),
            ('comptabilite', 'Comptabilité'),
            ('chef_etablissement', 'Chef d’établissement')");
        $log[] = 'Rôles Comptabilité / Chef d’établissement / Responsable de service : OK';

        if (!self::hasColumn($pdo, 'users', 'service_id')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN service_id INT NULL AFTER display_name');
            $log[] = 'users.service_id ajoutée';
        }
        if (!self::hasColumn($pdo, 'commandes', 'service_id')) {
            $pdo->exec('ALTER TABLE commandes ADD COLUMN service_id INT NULL AFTER demandeur_id');
            $log[] = 'commandes.service_id ajoutée';
        }

        if (!self::hasColumn($pdo, 'commande_validations', 'etape')) {
            $pdo->exec("ALTER TABLE commande_validations
                ADD COLUMN etape TINYINT NOT NULL DEFAULT 2 AFTER commande_id,
                ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'validateur' AFTER etape,
                ADD COLUMN assigned_to INT NULL AFTER role,
                MODIFY validateur_id INT NULL");
            // Old rows were one-per-validator: keep them assigned to that person.
            $pdo->exec('UPDATE commande_validations SET assigned_to = validateur_id WHERE assigned_to IS NULL');
            $pdo->exec("UPDATE commande_validations SET validateur_id = NULL WHERE decision = 'en_attente'");
            $log[] = 'commande_validations migrée vers les étapes de validation';
        }

        if (self::hasIndex($pdo, 'commande_validations', 'uniq_commande_validateur')) {
            $pdo->exec('ALTER TABLE commande_validations DROP INDEX uniq_commande_validateur');
            $log[] = 'Ancienne contrainte unique des validations supprimée';
        }

        // Un service peut avoir plusieurs responsables : reprise de l'ancien responsable unique.
        if (self::hasColumn($pdo, 'services', 'responsable_id')) {
            $copied = $pdo->exec(
                'INSERT IGNORE INTO service_responsables (service_id, user_id)
                 SELECT id, responsable_id FROM services WHERE responsable_id IS NOT NULL'
            );
            // Vidé pour qu'une relance ne recrée pas un responsable retiré entre-temps.
            $pdo->exec('UPDATE services SET responsable_id = NULL');
            if ($copied) {
                $log[] = "$copied responsable(s) de service repris";
            }
        }

        $pdo->exec('ALTER TABLE commande_lignes MODIFY destination VARCHAR(255) NOT NULL');

        if (!self::hasColumn($pdo, 'suppliers', 'executeur_id')) {
            $pdo->exec('ALTER TABLE suppliers ADD COLUMN executeur_id INT NULL AFTER name');
            $log[] = 'suppliers.executeur_id ajoutée (exécuteur attitré par fournisseur)';
        }

        // Commandes déjà validées avant l'exécution par fournisseur : on leur crée leurs parts.
        $sansExecution = $pdo->query(
            "SELECT c.id FROM commandes c
             WHERE c.statut = 'valide'
               AND NOT EXISTS (SELECT 1 FROM commande_executions e WHERE e.commande_id = c.id)"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($sansExecution as $commandeId) {
            \App\Services\CommandeService::createExecutions((int) $commandeId);
        }
        if ($sansExecution) {
            $log[] = count($sansExecution) . ' commande(s) validée(s) réparties entre exécuteurs';
        }

        $log = array_merge($log, self::dropValidateurRole($pdo));

        // Ordre manuel des destinations (glisser-déposer) : on part de l'ordre alphabétique actuel.
        if (!self::hasColumn($pdo, 'destinations', 'position')) {
            $pdo->exec('ALTER TABLE destinations ADD COLUMN position INT NOT NULL DEFAULT 0 AFTER name');
            $update = $pdo->prepare('UPDATE destinations SET position = ? WHERE id = ?');
            $number = static function (array $nodes) use (&$number, $update): void {
                foreach (array_values($nodes) as $i => $node) {
                    $update->execute([$i, (int) $node['id']]);
                    $number($node['children']);
                }
            };
            $number(\App\Repositories\DestinationRepository::tree());
            $log[] = 'destinations.position ajoutée (ordre personnalisable)';
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM destinations')->fetchColumn() === 0) {
            self::seedDestinations($pdo);
            $log[] = 'Destinations par défaut créées (Pôle 1 / Pôle 2)';
        }

        return $log;
    }

    /**
     * Le rôle générique « Validateur » est supprimé : le circuit se limite au responsable de service,
     * à la comptabilité et au chef d'établissement. Les signatures déjà données restent dans l'historique.
     * @return string[]
     */
    private static function dropValidateurRole(PDO $pdo): array
    {
        $log = [];
        $touched = $pdo->query(
            "SELECT DISTINCT commande_id FROM commande_validations
             WHERE role = 'validateur' AND decision = 'en_attente'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($touched) {
            $pdo->exec("DELETE FROM commande_validations WHERE role = 'validateur' AND decision = 'en_attente'");
            $log[] = count($touched) . ' commande(s) en cours : validation « Validateur » retirée du circuit';
        }

        // Commandes dont il ne restait que ces validations : elles deviennent validées.
        $completes = $pdo->query(
            "SELECT c.id FROM commandes c
             WHERE c.statut IN ('en_attente', 'en_validation')
               AND NOT EXISTS (SELECT 1 FROM commande_validations v
                               WHERE v.commande_id = c.id AND v.decision = 'en_attente')"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($completes as $commandeId) {
            \App\Repositories\CommandeRepository::setStatut((int) $commandeId, 'valide');
            \App\Repositories\CommandeRepository::logEvent((int) $commandeId, null, 'info',
                'Toutes les validations requises obtenues (rôle « Validateur » supprimé) : commande validée.');
            \App\Services\CommandeService::createExecutions((int) $commandeId);
        }
        if ($completes) {
            $log[] = count($completes) . ' commande(s) passée(s) en « Validé » et transmises aux exécuteurs (sans e-mail)';
        }

        $removed = $pdo->exec(
            "DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.code = 'validateur'"
        );
        $pdo->exec("DELETE FROM roles WHERE code = 'validateur'");
        if ($removed) {
            $log[] = "Rôle « Validateur » supprimé ($removed attribution(s) retirée(s))";
        }
        return $log;
    }

    private static function seedDestinations(PDO $pdo): void
    {
        $defaults = [
            'Pôle 1' => ['Bat A-D', 'Cantine Prof', 'Amphi', 'VS', 'Infirmerie', 'IT', 'Accueil', 'Admin', 'Gymnase', 'Cantine Élève'],
            'Pôle 2' => ['Bat E-G', 'Cantine Prof', 'Cantine Élève', 'Admin', 'VS'],
        ];
        $insert = $pdo->prepare('INSERT INTO destinations (parent_id, name, position) VALUES (?, ?, ?)');
        $p = 0;
        foreach ($defaults as $pole => $lieux) {
            $insert->execute([null, $pole, $p++]);
            $poleId = (int) $pdo->lastInsertId();
            foreach ($lieux as $i => $lieu) {
                $insert->execute([$poleId, $lieu, $i]);
            }
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
