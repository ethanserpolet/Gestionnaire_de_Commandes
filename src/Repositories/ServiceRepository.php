<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class ServiceRepository
{
    public static function all(): array
    {
        $pdo = Database::connection();
        $services = $pdo->query(
            "SELECT s.id, s.name, s.created_at,
                    (SELECT COUNT(*) FROM users u WHERE u.service_id = s.id) AS nb_membres,
                    (SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ')
                       FROM service_responsables sr JOIN users u ON u.id = sr.user_id
                      WHERE sr.service_id = s.id) AS responsables_noms,
                    (SELECT GROUP_CONCAT(sr.user_id) FROM service_responsables sr
                      WHERE sr.service_id = s.id) AS responsables_ids
             FROM services s
             ORDER BY s.name"
        )->fetchAll();

        foreach ($services as &$s) {
            $s['responsable_ids'] = $s['responsables_ids'] !== null
                ? array_map('intval', explode(',', $s['responsables_ids']))
                : [];
        }
        return $services;
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, name, created_at FROM services WHERE id = ?');
        $stmt->execute([$id]);
        $service = $stmt->fetch();
        if (!$service) {
            return null;
        }
        $service['responsables'] = self::responsablesOf($id);
        return $service;
    }

    /** Active responsables of a service, sorted by name. */
    public static function responsablesOf(int $serviceId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.* FROM service_responsables sr
             JOIN users u ON u.id = sr.user_id
             WHERE sr.service_id = ? AND u.is_active = 1
             ORDER BY u.display_name'
        );
        $stmt->execute([$serviceId]);
        return $stmt->fetchAll();
    }

    public static function count(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM services')->fetchColumn();
    }

    /** @param int[] $responsableIds */
    public static function create(string $name, array $responsableIds): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT IGNORE INTO services (name) VALUES (?)');
        $stmt->execute([trim($name)]);
        $id = (int) $pdo->lastInsertId();
        if ($id) {
            self::setResponsables($id, $responsableIds);
        }
    }

    /** @param int[] $responsableIds */
    public static function update(int $id, string $name, array $responsableIds): void
    {
        $pdo = Database::connection();
        $before = array_map(static fn($u) => (int) $u['id'], self::responsablesOf($id));

        $pdo->prepare('UPDATE services SET name = ? WHERE id = ?')->execute([trim($name), $id]);
        self::setResponsables($id, $responsableIds);

        // Commandes en attente d'un responsable retiré : confiées au premier responsable restant.
        $removed = array_diff($before, $responsableIds);
        $remaining = array_values(array_map(static fn($u) => (int) $u['id'], self::responsablesOf($id)));
        if ($removed && $remaining) {
            $stmt = $pdo->prepare(
                "UPDATE commande_validations cv
                 JOIN commandes c ON c.id = cv.commande_id
                 SET cv.assigned_to = ?
                 WHERE c.service_id = ? AND cv.etape = 1 AND cv.decision = 'en_attente' AND cv.assigned_to = ?
                   AND c.statut IN ('en_attente', 'en_validation') AND c.demandeur_id <> ?"
            );
            foreach ($removed as $oldId) {
                $stmt->execute([$remaining[0], $id, $oldId, $remaining[0]]);
            }
        }
    }

    public static function delete(int $id): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET service_id = NULL WHERE service_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE commandes SET service_id = NULL WHERE service_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param int[] $userIds */
    private static function setResponsables(int $serviceId, array $userIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM service_responsables WHERE service_id = ?')->execute([$serviceId]);
        $insert = $pdo->prepare('INSERT IGNORE INTO service_responsables (service_id, user_id) VALUES (?, ?)');
        foreach (array_unique($userIds) as $userId) {
            $insert->execute([$serviceId, (int) $userId]);
        }
    }
}
