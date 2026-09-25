<?php
declare(strict_types=1);

namespace App\Support;

final class Roles
{
    /** Roles that take part in the validation circuit. */
    public const VALIDATORS = ['responsable_service', 'comptabilite', 'chef_etablissement', 'validateur'];

    /** Roles that may pass validated orders to suppliers (comptabilité is the default executor). */
    public const EXECUTORS = ['executeur', 'comptabilite'];

    private const LABELS = [
        'demandeur' => 'Demandeur',
        'validateur' => 'Validateur',
        'executeur' => 'Exécuteur',
        'lecteur' => 'Lecteur',
        'comptabilite' => 'Comptabilité',
        'chef_etablissement' => 'Chef d’établissement',
        'responsable_service' => 'Responsable de service',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    public static function isValidator(array $user): bool
    {
        return (bool) array_intersect($user['roles'] ?? [], self::VALIDATORS);
    }

    public static function isExecutor(array $user): bool
    {
        return (bool) array_intersect($user['roles'] ?? [], self::EXECUTORS);
    }
}
