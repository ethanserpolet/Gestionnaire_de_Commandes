<?php
declare(strict_types=1);

// Script d'installation / mise à jour de la base — À SUPPRIMER du serveur après utilisation.
// Peut être relancé sans risque : il ne recrée que ce qui manque.
// Protégé par la clé APP_SETUP_KEY du .env (jamais versionnée).

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Config;
use App\Database;
use App\Support\Migrations;

$expected = (string) Config::get('APP_SETUP_KEY', '');
$key = (string) ($_GET['key'] ?? '');
if (strlen($expected) < 12 || !hash_equals($expected, $key)) {
    http_response_code(403);
    exit(strlen($expected) < 12
        ? 'Accès refusé : définissez APP_SETUP_KEY (12 caractères minimum) dans le fichier .env.'
        : 'Accès refusé. Ajoutez ?key=<APP_SETUP_KEY> à l\'URL.');
}

header('Content-Type: text/plain; charset=utf-8');

echo "PHP version : " . PHP_VERSION . "\n\n";

// Codes MySQL/MariaDB signifiant « existe déjà » : table, colonne, index.
const ALREADY_EXISTS = [1050, 1060, 1061];

try {
    $pdo = Database::connection();
    echo "Connexion à la base OK.\n\n";

    foreach (['schema.sql', 'seed.sql'] as $file) {
        $path = dirname(__DIR__) . "/database/$file";
        if (!is_file($path)) {
            echo "Fichier introuvable : $file\n";
            continue;
        }

        $sql = preg_replace('/^--.*$/m', '', file_get_contents($path));
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        $skipped = 0;
        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), ALREADY_EXISTS, true)) {
                    throw $e;
                }
                $skipped++;
            }
        }
        echo "Exécuté : $file (" . count($statements) . " instructions, $skipped déjà présentes) — OK\n";
    }

    echo "\nMigrations :\n";
    foreach (Migrations::run($pdo) as $line) {
        echo "  - $line\n";
    }

    echo "\nTerminé. Base à jour.\n";
    echo "\n⚠️  Supprimez ce fichier (public/_setup.php) maintenant.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERREUR (" . get_class($e) . ") : " . $e->getMessage() . "\n";
    echo "\nFichier : " . $e->getFile() . ':' . $e->getLine() . "\n";
}
