<?php
use App\Repositories\CommandeRepository;
use App\Support\Csrf;
use App\Support\View;

/** @var array $currentUser */
/** @var string $path */
$navRoles = $currentUser['roles'] ?? [];
$navHas = static fn(string $role): bool => in_array($role, $navRoles, true);
$navIsValidator = \App\Support\Roles::isValidator($currentUser);

$navCounts = [];
try {
    if ($navIsValidator) {
        $navCounts['/commandes/a-valider'] = count(CommandeRepository::listAwaitingValidateur($currentUser));
    }
    if (\App\Support\Roles::isExecutor($currentUser)) {
        $navCounts['/commandes/a-finaliser'] = count(CommandeRepository::listAwaitingExecution($currentUser));
    }
} catch (\Throwable $e) {
    $navCounts = [];
}

// 4e élément : ouverture dans un nouvel onglet.
$navSections = ['Général' => [
    ['/', 'Tableau de bord', 'dashboard'],
    ['/tutoriel', 'Tutoriel', 'book', true],
]];

$navCommandes = [];
if ($navHas('demandeur')) {
    $navCommandes[] = ['/commandes/mes', 'Mes commandes', 'file'];
}
if ($navIsValidator) {
    $navCommandes[] = ['/commandes/a-valider', 'À valider', 'check-circle'];
}
if (\App\Support\Roles::isExecutor($currentUser)) {
    $navCommandes[] = ['/commandes/a-finaliser', 'À passer', 'package'];
}
if ($navHas('lecteur')) {
    $navCommandes[] = ['/commandes/validees', 'Commandes validées', 'eye'];
}
if ($navCommandes) {
    $navSections['Commandes'] = $navCommandes;
}
if (!empty($currentUser['is_admin'])) {
    $navSections['Administration'] = [
        ['/admin/utilisateurs', 'Utilisateurs', 'users'],
        ['/admin/services', 'Services', 'building'],
        ['/admin/destinations', 'Destinations', 'map-pin'],
        ['/admin/fournisseurs', 'Fournisseurs', 'store'],
        ['/admin/simulation', 'Simulation', 'history'],
    ];
}

$navIsActive = static fn(string $href): bool => $href === '/' ? $path === '/' : str_starts_with($path, $href);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-inner glass">
        <a href="/" class="brand">
            <span class="brand-logo"><?= View::icon('receipt', 20) ?></span>
            <span>
                <span class="brand-name">Commandes</span>
                <span class="brand-sub">Lycée St Marc</span>
            </span>
        </a>

        <?php if ($navHas('demandeur')): ?>
            <form method="post" action="/commandes" class="sidebar-cta">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-primary btn-block"><?= View::icon('plus', 16) ?> Nouvelle commande</button>
            </form>
        <?php endif; ?>

        <nav aria-label="Navigation principale">
            <?php foreach ($navSections as $sectionLabel => $sectionItems): ?>
                <div class="nav-label"><?= View::e($sectionLabel) ?></div>
                <div class="nav">
                    <?php foreach ($sectionItems as $navItem):
                        [$href, $label, $icon] = $navItem;
                        $newTab = !empty($navItem[3]);
                        $isActive = !$newTab && $navIsActive($href); ?>
                        <a href="<?= View::e($href) ?>" class="nav-item<?= $isActive ? ' active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?><?= $newTab ? ' target="_blank" rel="noopener"' : '' ?>>
                            <?= View::icon($icon) ?>
                            <span><?= View::e($label) ?></span>
                            <?php if (!empty($navCounts[$href])): ?>
                                <span class="nav-count"><?= (int) $navCounts[$href] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <?= View::avatar($currentUser['display_name']) ?>
            <a href="/profil/service" class="user-mini" title="Changer de service">
                <strong><?= View::e($currentUser['display_name']) ?></strong>
                <span><?= View::e($currentUser['service_nom'] ?? $currentUser['email']) ?></span>
            </a>
            <button type="button" class="btn-icon theme-toggle" data-theme-toggle aria-label="Changer de thème" title="Changer de thème">
                <span class="i-moon"><?= View::icon('moon') ?></span>
                <span class="i-sun"><?= View::icon('sun') ?></span>
            </button>
            <form method="post" action="/logout">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-icon" aria-label="Se déconnecter" title="Se déconnecter"><?= View::icon('logout') ?></button>
            </form>
        </div>
    </div>
</aside>
