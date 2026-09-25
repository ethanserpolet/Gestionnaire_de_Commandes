<?php
use App\Support\Csrf;
use App\Support\View;

/** @var array $user */
$pageTitle = 'Tableau de bord';
$roles = $user['roles'];
$has = static fn(string $role): bool => in_array($role, $roles, true);
$isValidator = \App\Support\Roles::isValidator($user);
$isExecutor = \App\Support\Roles::isExecutor($user);
$prenom = explode(' ', trim($user['display_name']))[0] ?: $user['display_name'];

$stats = [];
if ($has('demandeur')) {
    $stats[] = ['Brouillons', count($mesBrouillons), 'edit', 'neutral', '/commandes/mes'];
    $stats[] = ['En cours', count($mesEnCours), 'clock', 'info', '/commandes/mes'];
}
if ($isValidator) {
    $stats[] = ['À valider', count($aValider), 'check-circle', 'warning', '/commandes/a-valider'];
}
if ($isExecutor) {
    $stats[] = ['À passer', count($aFinaliser), 'package', 'teal', '/commandes/a-finaliser'];
}
if ($has('lecteur')) {
    $stats[] = ['Validées', $nbValidees, 'eye', 'success', '/commandes/validees'];
}

$taches = [];
if ($isValidator) {
    $taches = array_merge($taches, $aValider);
}
if ($isExecutor) {
    $taches = array_merge($taches, $aFinaliser);
}
// Une même commande peut être à la fois à valider et à passer : on ne l'affiche qu'une fois.
$taches = array_values(array_column($taches, null, 'id'));
$nbTaches = count($taches);

if (empty($roles) && !$user['is_admin']) {
    $sousTitre = 'Votre compte est prêt, il ne reste plus qu’à vous attribuer un rôle.';
} elseif ($nbTaches > 0) {
    $sousTitre = $nbTaches . ' commande' . ($nbTaches > 1 ? 's attendent' : ' attend') . ' votre action.';
} else {
    $sousTitre = 'Tout est à jour, rien ne vous attend pour le moment.';
}
?>

<div class="hero">
    <div>
        <div class="hero-date"><?= View::e(ucfirst(View::todayLong())) ?></div>
        <h1>Bonjour <?= View::e($prenom) ?></h1>
        <p><?= View::e($sousTitre) ?></p>
    </div>
    <?php if ($has('demandeur')): ?>
        <form method="post" action="/commandes">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary btn-lg"><?= View::icon('plus') ?> Nouvelle commande</button>
        </form>
    <?php endif; ?>
</div>

<div class="stack">
    <?php if (empty($roles) && !$user['is_admin']): ?>
        <section class="glass card">
            <div class="empty">
                <span class="empty-icon"><?= View::icon('shield', 26) ?></span>
                <h3>Aucun rôle attribué</h3>
                <p>Un administrateur doit vous attribuer un rôle (demandeur, comptabilité, chef d’établissement, exécuteur ou lecteur) pour que vous puissiez utiliser l’application.</p>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($stats): ?>
        <div class="grid grid-stats">
            <?php foreach ($stats as [$label, $value, $icon, $tone, $href]): ?>
                <a href="<?= View::e($href) ?>" class="glass stat">
                    <span class="stat-icon tone-<?= $tone ?>"><?= View::icon($icon, 22) ?></span>
                    <div>
                        <div class="stat-value"><?= (int) $value ?></div>
                        <div class="stat-label"><?= View::e($label) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cards">
        <?php if ($isValidator || $isExecutor): ?>
            <section class="glass card">
                <div class="card-head">
                    <div>
                        <h2>À traiter</h2>
                        <div class="sub">Commandes qui attendent votre action</div>
                    </div>
                </div>
                <?php $items = array_slice($taches, 0, 6); $emptyText = 'Rien à traiter, bravo !'; require __DIR__ . '/../partials/mini_list.php'; ?>
            </section>
        <?php endif; ?>

        <?php if ($has('demandeur')): ?>
            <section class="glass card">
                <div class="card-head">
                    <div>
                        <h2>Mes commandes récentes</h2>
                        <div class="sub">Vos 6 dernières demandes</div>
                    </div>
                    <a href="/commandes/mes" class="link">Tout voir <?= View::icon('chevron-right', 14) ?></a>
                </div>
                <?php $items = $mesRecentes; $emptyText = 'Vous n’avez encore créé aucune commande.'; require __DIR__ . '/../partials/mini_list.php'; ?>
            </section>
        <?php endif; ?>

        <?php if ($has('lecteur')): ?>
            <section class="glass card">
                <div class="card-head">
                    <div>
                        <h2>Dernières commandes validées</h2>
                        <div class="sub">Validées ou finalisées</div>
                    </div>
                    <a href="/commandes/validees" class="link">Tout voir <?= View::icon('chevron-right', 14) ?></a>
                </div>
                <?php $items = $validees; $emptyText = 'Aucune commande validée pour le moment.'; require __DIR__ . '/../partials/mini_list.php'; ?>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($user['is_admin']): ?>
        <section class="glass card">
            <div class="card-head"><h2>Administration</h2></div>
            <div class="tiles">
                <a href="/admin/utilisateurs" class="tile">
                    <span class="stat-icon tone-primary"><?= View::icon('users', 20) ?></span>
                    <div><strong>Utilisateurs</strong><span>Rôles et accès du personnel</span></div>
                    <?= View::icon('chevron-right') ?>
                </a>
                <a href="/admin/services" class="tile">
                    <span class="stat-icon tone-info"><?= View::icon('building', 20) ?></span>
                    <div><strong>Services</strong><span>Services et responsables</span></div>
                    <?= View::icon('chevron-right') ?>
                </a>
                <a href="/admin/destinations" class="tile">
                    <span class="stat-icon tone-warning"><?= View::icon('map-pin', 20) ?></span>
                    <div><strong>Destinations</strong><span>Pôles, lieux et sous-lieux</span></div>
                    <?= View::icon('chevron-right') ?>
                </a>
                <a href="/admin/simulation" class="tile">
                    <span class="stat-icon tone-success"><?= View::icon('history', 20) ?></span>
                    <div><strong>Simulation</strong><span>Tester tout le circuit sans risque</span></div>
                    <?= View::icon('chevron-right') ?>
                </a>
                <a href="/admin/fournisseurs" class="tile">
                    <span class="stat-icon tone-teal"><?= View::icon('store', 20) ?></span>
                    <div><strong>Fournisseurs</strong><span>Liste proposée aux demandeurs</span></div>
                    <?= View::icon('chevron-right') ?>
                </a>
            </div>
        </section>
    <?php endif; ?>
</div>
