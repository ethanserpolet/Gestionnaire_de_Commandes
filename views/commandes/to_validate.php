<?php
/** @var array $aValider */
/** @var array $historique */
$pageTitle = 'À valider';
$n = count($aValider);
?>
<div class="page-head">
    <div>
        <h1>À valider</h1>
        <p class="page-sub"><?= $n ? $n . ' commande' . ($n > 1 ? 's attendent' : ' attend') . ' votre décision' : 'Aucune commande n’attend votre décision' ?></p>
    </div>
</div>

<div class="stack">
    <section class="glass card card-flush">
        <div class="card-head"><h2>En attente de votre décision</h2></div>
        <?php
        $commandes = $aValider;
        $advanced = $n > 1;
        $emptyTitle = 'Rien à valider';
        $emptyText = 'Vous recevrez un e-mail dès qu’une nouvelle commande nécessitera votre validation.';
        require __DIR__ . '/../partials/commande_table.php';
        ?>
    </section>

    <section class="glass card card-flush">
        <div class="card-head"><h2>Historique de mes décisions</h2></div>
        <?php
        $commandes = $historique;
        $advanced = true;
        $emptyTitle = 'Aucune décision pour l’instant';
        $emptyText = 'Les commandes que vous aurez validées ou refusées apparaîtront ici.';
        require __DIR__ . '/../partials/commande_table.php';
        ?>
    </section>
</div>
