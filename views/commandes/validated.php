<?php
/** @var array $commandes */
$pageTitle = 'Commandes validées';
$n = count($commandes);
?>
<div class="page-head">
    <div>
        <h1>Commandes validées</h1>
        <p class="page-sub"><?= $n ?> commande<?= $n > 1 ? 's' : '' ?> validée<?= $n > 1 ? 's' : '' ?> ou finalisée<?= $n > 1 ? 's' : '' ?></p>
    </div>
</div>

<section class="glass card card-flush">
    <?php
    $advanced = true;
    $emptyTitle = 'Aucune commande validée';
    $emptyText = 'Les commandes approuvées par les validateurs apparaîtront ici.';
    require __DIR__ . '/../partials/commande_table.php';
    ?>
</section>
