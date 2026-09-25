<?php
declare(strict_types=1);

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

use App\Auth\Session;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommandeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Router;

date_default_timezone_set('Europe/Paris');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

Session::start();

$router = new Router();

$router->get('/', static fn() => DashboardController::index());

$router->get('/login', static fn() => AuthController::showLogin());
$router->get('/auth/microsoft', static fn() => AuthController::start());
$router->get('/auth/callback', static fn() => AuthController::callback());
$router->post('/logout', static fn() => AuthController::logout());

$router->get('/profil/service', static fn() => ProfileController::service());
$router->post('/profil/service', static fn() => ProfileController::saveService());

$router->get('/commandes/mes', static fn() => CommandeController::mine());
$router->post('/commandes', static fn() => CommandeController::store());
$router->get('/commandes/a-valider', static fn() => CommandeController::toValidate());
$router->get('/commandes/a-finaliser', static fn() => CommandeController::toFinalize());
$router->get('/commandes/validees', static fn() => CommandeController::validated());

$router->get('/commandes/{id}', static fn($p) => CommandeController::show($p));
$router->get('/commandes/{id}/pdf', static fn($p) => CommandeController::printable($p));
$router->post('/commandes/{id}/lignes', static fn($p) => CommandeController::addLigne($p));
$router->post('/commandes/{id}/lignes/{ligneId}/supprimer', static fn($p) => CommandeController::deleteLigne($p));
$router->post('/commandes/{id}/lignes/{ligneId}/fichiers', static fn($p) => CommandeController::uploadFichier($p));
$router->post('/commandes/{id}/soumettre', static fn($p) => CommandeController::submit($p));
$router->post('/commandes/{id}/supprimer', static fn($p) => CommandeController::delete($p));
$router->post('/commandes/{id}/decider', static fn($p) => CommandeController::decide($p));
$router->post('/commandes/{id}/finaliser', static fn($p) => CommandeController::finalize($p));

$router->get('/fichiers/{fichierId}', static fn($p) => CommandeController::downloadFichier($p));
$router->post('/fichiers/{fichierId}/supprimer', static fn($p) => CommandeController::deleteFichier($p));

$router->get('/admin/utilisateurs', static fn() => AdminController::users());
$router->post('/admin/utilisateurs/lot', static fn() => AdminController::bulkUsers());
$router->post('/admin/utilisateurs/{id}/roles', static fn($p) => AdminController::updateRoles($p));
$router->post('/admin/utilisateurs/{id}/actif', static fn($p) => AdminController::toggleActive($p));
$router->get('/admin/services', static fn() => AdminController::services());
$router->post('/admin/services', static fn() => AdminController::createService());
$router->post('/admin/services/{id}/modifier', static fn($p) => AdminController::updateService($p));
$router->post('/admin/services/{id}/supprimer', static fn($p) => AdminController::deleteService($p));
$router->get('/admin/simulation', static fn() => AdminController::simulation());
$router->post('/admin/simulation', static fn() => AdminController::runSimulation());
$router->post('/admin/simulation/mail-test', static fn() => AdminController::sendTestMail());
$router->get('/admin/destinations', static fn() => AdminController::destinations());
$router->post('/admin/destinations', static fn() => AdminController::createDestination());
$router->post('/admin/destinations/{id}/renommer', static fn($p) => AdminController::renameDestination($p));
$router->post('/admin/destinations/{id}/actif', static fn($p) => AdminController::toggleDestination($p));
$router->post('/admin/destinations/{id}/supprimer', static fn($p) => AdminController::deleteDestination($p));
$router->get('/admin/fournisseurs', static fn() => AdminController::suppliers());
$router->post('/admin/fournisseurs', static fn() => AdminController::createSupplier());
$router->post('/admin/fournisseurs/{id}/actif', static fn($p) => AdminController::toggleSupplierActive($p));
$router->post('/admin/fournisseurs/{id}/renommer', static fn($p) => AdminController::renameSupplier($p));

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    error_log('Unhandled error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $debug = \App\Config::get('APP_DEBUG') === '1'
        ? get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : null;
    \App\Support\View::render('errors/500', ['debugMessage' => $debug]);
}
