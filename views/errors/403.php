<?php
use App\Support\View;

$pageTitle = 'Accès refusé';
?>
<div class="error-wrap">
    <div class="glass error-card">
        <div class="error-code">403</div>
        <h1>Accès refusé</h1>
        <p>Vous n’avez pas les droits nécessaires pour accéder à cette page. Si vous pensez qu’il s’agit d’une erreur, contactez un administrateur.</p>
        <a href="/" class="btn btn-primary"><?= View::icon('arrow-left', 16) ?> Retour au tableau de bord</a>
    </div>
</div>
