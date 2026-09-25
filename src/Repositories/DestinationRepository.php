<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

/**
 * Destinations en arborescence libre (pôle > lieu > sous-lieu…).
 * Une ligne de commande stocke le chemin complet en texte (« Pôle 1 › Bat A-D › Salle 12 »),
 * pour que l'historique reste lisible même si l'arborescence change ensuite.
 */
final class DestinationRepository
{
    public const SEPARATOR = ' › ';

    /** @return array<int,array> racines, chaque nœud portant 'children' */
    public static function tree(bool $activeOnly = false): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query('SELECT * FROM destinations')->fetchAll();

        $byParent = [];
        foreach ($rows as $row) {
            if ($activeOnly && !$row['is_active']) {
                continue;
            }
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }

        $build = static function (int $parentId) use (&$build, $byParent): array {
            $nodes = $byParent[$parentId] ?? [];
            usort($nodes, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            foreach ($nodes as &$node) {
                $node['children'] = $build((int) $node['id']);
            }
            return $nodes;
        };

        return $build(0);
    }

    /**
     * Options du champ « Destination », groupées par pôle.
     * Les pôles qui ont des lieux servent de groupe ; un pôle sans lieu est sélectionnable seul.
     *
     * @return array<int,array{group:?string,options:array<int,array{id:int,label:string,path:string}>}>
     */
    public static function selectOptions(): array
    {
        $groups = [];
        foreach (self::tree(true) as $root) {
            if (!$root['children']) {
                $groups[] = ['group' => null, 'options' => [
                    ['id' => (int) $root['id'], 'label' => $root['name'], 'path' => $root['name']],
                ]];
                continue;
            }
            $options = [];
            $walk = static function (array $nodes, array $trail, int $depth) use (&$walk, &$options): void {
                foreach ($nodes as $node) {
                    $path = array_merge($trail, [$node['name']]);
                    $options[] = [
                        'id' => (int) $node['id'],
                        'label' => str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . ($depth ? '↳ ' : '') . $node['name'],
                        'path' => implode(self::SEPARATOR, $path),
                    ];
                    $walk($node['children'], $path, $depth + 1);
                }
            };
            $walk($root['children'], [$root['name']], 0);
            $groups[] = ['group' => $root['name'], 'options' => $options];
        }
        return $groups;
    }

    /** Chemin complet d'une destination sélectionnable et active, sinon null. */
    public static function selectablePath(int $id): ?string
    {
        foreach (self::selectOptions() as $group) {
            foreach ($group['options'] as $option) {
                if ($option['id'] === $id) {
                    return $option['path'];
                }
            }
        }
        return null;
    }

    public static function create(string $name, ?int $parentId): void
    {
        $pdo = Database::connection();
        if ($parentId !== null && !self::exists($parentId)) {
            return;
        }
        $pdo->prepare('INSERT INTO destinations (parent_id, name) VALUES (?, ?)')->execute([$parentId, trim($name)]);
    }

    public static function rename(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE destinations SET name = ? WHERE id = ?')->execute([trim($name), $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::connection()->prepare('UPDATE destinations SET is_active = ? WHERE id = ?')->execute([(int) $active, $id]);
    }

    /** Supprime la destination et tous ses sous-lieux (les commandes gardent le texte). */
    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM destinations WHERE id = ?')->execute([$id]);
    }

    private static function exists(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM destinations WHERE id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }
}
