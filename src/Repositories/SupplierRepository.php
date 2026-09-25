<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class SupplierRepository
{
    public static function allActive(): array
    {
        $pdo = Database::connection();
        return $pdo->query('SELECT * FROM suppliers WHERE is_active = TRUE ORDER BY name')->fetchAll();
    }

    public static function all(): array
    {
        $pdo = Database::connection();
        return $pdo->query(
            'SELECT s.*, u.display_name AS executeur_nom, u.is_active AS executeur_actif
             FROM suppliers s LEFT JOIN users u ON u.id = s.executeur_id
             ORDER BY s.name'
        )->fetchAll();
    }

    public static function create(string $name, ?int $executeurId = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT IGNORE INTO suppliers (name, executeur_id) VALUES (?, ?)');
        $stmt->execute([trim($name), $executeurId]);
    }

    public static function setActive(int $id, bool $active): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE suppliers SET is_active = ? WHERE id = ?')->execute([(int) $active, $id]);
    }

    public static function update(int $id, string $name, ?int $executeurId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE suppliers SET name = ?, executeur_id = ? WHERE id = ?')->execute([trim($name), $executeurId, $id]);
    }
}
