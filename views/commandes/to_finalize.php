<?php
/** @var array $commandes */
/** @var array $finalisees */
$pageTitle = 'À passer';
$aPasser = $commandes;
$n = count($aPasser);
?>
<div class="page-head">
    <div>
        <h1>À passer</h1>
        <p class="page-sub"><?= $n
            ? $n . ' commande' . ($n > 1 ? 's validées contiennent' : ' validée contient') . ' des fournisseurs qui vous sont confiés'
            : 'Aucune commande à passer pour vous' ?></p>
    </div>
</div>

<div class="stack">
    <section class="glass card card-flush">
        <div class="card-head">
            <div>
                <h2>Validées, à passer auprès des fournisseurs</h2>
                <div class="sub">Les fournisseurs qui vous sont attribués, ou ceux sans exécuteur attitré si vous êtes à la comptabilité</div>
            </div>
        </div>
        <?php
        $commandes = $aPasser;
        $advanced = $n > 1;
        $emptyTitle = 'Rien à passer';
        $emptyText = 'Vous recevrez un e-mail dès qu’une commande validée concernera un de vos fournisseurs.';
        require __DIR__ . '/../partials/commande_table.php';
        ?>
    </section>

    <section class="glass card card-flush">
        <div class="card-head"><h2>Récemment finalisées</h2></div>
        <?php
        $commandes = $finalisees;
        $advanced = true;
        $emptyTitle = 'Aucune commande finalisée';
        $emptyText = 'L’historique des commandes finalisées s’affichera ici.';
        require __DIR__ . '/../partials/commande_table.php';
        ?>
    </section>
</div>
