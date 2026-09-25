<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Config;
use App\Database;
use PDO;

final class UserRepository
{
    public static function findById(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.*, s.name AS service_nom
             FROM users u LEFT JOIN services s ON s.id = u.service_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        $user['roles'] = self::rolesFor($id);
        return $user;
    }

    public static function findByEntraId(string $entraObjectId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE entra_object_id = ?');
        $stmt->execute([$entraObjectId]);
        $row = $stmt->fetch();
        return $row ? self::findById((int) $row['id']) : null;
    }

    /** @return string[] */
    private static function rolesFor(int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?'
        );
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(), 'code');
    }

    /**
     * Creates the user on first login, or refreshes their profile info on subsequent logins.
     * Never touches role assignments on existing users.
     */
    public static function upsertFromEntra(string $entraObjectId, string $email, string $displayName): int
    {
        $pdo = Database::connection();
        $adminEmails = Config::list('APP_ADMIN_EMAILS');
        $isAdmin = in_array(strtolower($email), $adminEmails, true) ? 1 : 0;

        $stmt = $pdo->prepare('SELECT id FROM users WHERE entra_object_id = ?');
        $stmt->execute([$entraObjectId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $upd = $pdo->prepare(
                'UPDATE users SET email = ?, display_name = ?, is_admin = (is_admin OR ?), last_login_at = NOW() WHERE id = ?'
            );
            $upd->execute([$email, $displayName, $isAdmin, $existing['id']]);
            return (int) $existing['id'];
        }

        $ins = $pdo->prepare(
            'INSERT INTO users (entra_object_id, email, display_name, is_admin, last_login_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $ins->execute([$entraObjectId, $email, $displayName, $isAdmin]);
        $userId = (int) $pdo->lastInsertId();

        // Tout nouvel inscrit peut immédiatement faire des demandes d'achat.
        $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = 'demandeur'")
            ->execute([$userId]);

        return $userId;
    }

    public static function all(): array
    {
        $pdo = Database::connection();
        $users = $pdo->query(
            'SELECT u.*, s.name AS service_nom FROM users u
             LEFT JOIN services s ON s.id = u.service_id ORDER BY u.display_name'
        )->fetchAll();
        foreach ($users as &$u) {
            $u['roles'] = self::rolesFor((int) $u['id']);
        }
        return $users;
    }

    /** @return array<int,array> user_id => user (only active users holding the given role) */
    public static function findActiveByRole(string $roleCode): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.* FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.code = ? AND u.is_active = TRUE
             ORDER BY u.display_name'
        );
        $stmt->execute([$roleCode]);
        return $stmt->fetchAll();
    }

    /** @param string[] $roleCodes */
    public static function setRoles(int $userId, array $roleCodes): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
            if (!empty($roleCodes)) {
                $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
                $stmt = $pdo->prepare(
                    "INSERT INTO user_roles (user_id, role_id)
                     SELECT ?, id FROM roles WHERE code IN ($placeholders)"
                );
                $stmt->execute([$userId, ...$roleCodes]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function setActive(int $userId, bool $active): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([(int) $active, $userId]);
    }

    public static function setService(int $userId, ?int $serviceId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET service_id = ? WHERE id = ?')->execute([$serviceId, $userId]);
    }

    public static function setAdmin(int $userId, bool $admin): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?')->execute([(int) $admin, $userId]);
    }

    // --- Recherche paginée (administration) ---------------------------------

    public const SORTS = [
        'nom' => 'u.display_name',
        'connexion' => 'u.last_login_at IS NULL, u.last_login_at DESC, u.display_name',
        'recent' => 'u.created_at DESC, u.display_name',
        'service' => 's.name IS NULL, s.name, u.display_name',
    ];

    /**
     * @param array{q:string,role:string,service:string,statut:string,tri:string} $f
     * @return array{rows:array,total:int}
     */
    public static function search(array $f, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(u.display_name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($f['role'] === '_none') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id)';
        } elseif ($f['role'] === '_admin') {
            $where[] = 'u.is_admin = 1';
        } elseif ($f['role'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code = ?)';
            $params[] = $f['role'];
        }
        if ($f['service'] === '_none') {
            $where[] = 'u.service_id IS NULL';
        } elseif ((int) $f['service'] > 0) {
            $where[] = 'u.service_id = ?';
            $params[] = (int) $f['service'];
        }
        if ($f['statut'] === 'actifs') {
            $where[] = 'u.is_active = 1';
        } elseif ($f['statut'] === 'inactifs') {
            $where[] = 'u.is_active = 0';
        }

        $pdo = Database::connection();
        $from = 'FROM users u LEFT JOIN services s ON s.id = u.service_id WHERE ' . implode(' AND ', $where);

        $count = $pdo->prepare("SELECT COUNT(*) $from");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = self::SORTS[$f['tri']] ?? self::SORTS['nom'];
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $pdo->prepare("SELECT u.*, s.name AS service_nom $from ORDER BY $order LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Rôles de la page en une seule requête.
        $ids = array_map(static fn($u) => (int) $u['id'], $rows);
        $roles = [];
        if ($ids) {
            $r = $pdo->prepare(
                'SELECT ur.user_id, r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.id'
            );
            $r->execute($ids);
            foreach ($r->fetchAll() as $row) {
                $roles[(int) $row['user_id']][] = $row['code'];
            }
        }
        foreach ($rows as &$u) {
            $u['roles'] = $roles[(int) $u['id']] ?? [];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** Compteurs pour l'en-tête et les filtres rapides. */
    public static function stats(): array
    {
        $pdo = Database::connection();
        $stats = $pdo->query(
            'SELECT COUNT(*) AS total,
                    SUM(is_active = 1) AS actifs,
                    SUM(is_active = 0) AS inactifs,
                    SUM(is_admin = 1) AS admins,
                    SUM(is_active = 1 AND service_id IS NULL) AS sans_service,
                    SUM(is_active = 1 AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = users.id)) AS sans_role
             FROM users'
        )->fetch();
        $stats['roles'] = array_column($pdo->query(
            'SELECT r.code, COUNT(u.id) AS n FROM roles r
             LEFT JOIN user_roles ur ON ur.role_id = r.id
             LEFT JOIN users u ON u.id = ur.user_id AND u.is_active = 1
             GROUP BY r.code'
        )->fetchAll(), 'n', 'code');
        return array_map(static fn($v) => is_array($v) ? array_map('intval', $v) : (int) $v, $stats);
    }

    // --- Actions groupées -----------------------------------------------------

    private static function inList(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** @param int[] $ids */
    public static function bulkAddRole(array $ids, string $code): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT u.id, r.id FROM users u JOIN roles r ON r.code = ? WHERE u.id IN (' . self::inList($ids) . ')'
        );
        $stmt->execute([$code, ...$ids]);
    }

    /** @param int[] $ids */
    public static function bulkRemoveRole(array $ids, string $code): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE r.code = ? AND ur.user_id IN (' . self::inList($ids) . ')'
        );
        $stmt->execute([$code, ...$ids]);
    }

    /** @param int[] $ids */
    public static function bulkSetService(array $ids, ?int $serviceId): void
    {
        Database::connection()->prepare('UPDATE users SET service_id = ? WHERE id IN (' . self::inList($ids) . ')')
            ->execute([$serviceId, ...$ids]);
    }

    /** @param int[] $ids */
    public static function bulkSetActive(array $ids, bool $active): void
    {
        Database::connection()->prepare('UPDATE users SET is_active = ? WHERE id IN (' . self::inList($ids) . ')')
            ->execute([(int) $active, ...$ids]);
    }

    public static function allRoles(): array
    {
        $pdo = Database::connection();
        return $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }
}
