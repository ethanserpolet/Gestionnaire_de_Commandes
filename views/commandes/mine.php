<?php
use App\Support\Csrf;
use App\Support\View;

/** @var array $commandes */
$pageTitle = 'Mes commandes';
$n = count($commandes);
$newForm = '<form method="post" action="/commandes">' . Csrf::field()
    . '<button type="submit" class="btn btn-primary">' . View::icon('plus') . ' Nouvelle commande</button></form>';
?>
<div class="page-head">
    <div>
        <h1>Mes commandes</h1>
        <p class="page-sub"><?= $n ?> commande<?= $n > 1 ? 's' : '' ?> au total</p>
    </div>
    <?php if ($n > 0): ?><div class="page-actions"><?= $newForm ?></div><?php endif; ?>
</div>

<section class="glass card card-flush">
    <?php
    $showFilters = true;
    $showDemandeur = false;
    $emptyTitle = 'Aucune commande pour l’instant';
    $emptyText = 'Créez votre première demande d’achat : ajoutez les articles, joignez les devis puis envoyez-la en validation.';
    $emptyAction = $newForm;
    require __DIR__ . '/../partials/commande_table.php';
    ?>
</section>
