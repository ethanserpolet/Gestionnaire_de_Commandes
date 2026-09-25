<?php
use App\Support\View;

$pageTitle = 'Page introuvable';
?>
<div class="error-wrap">
    <div class="glass error-card">
        <div class="error-code">404</div>
        <h1>Page introuvable</h1>
        <p>Cette page n’existe pas ou a été déplacée.</p>
        <a href="/" class="btn btn-primary"><?= View::icon('arrow-left', 16) ?> Retour au tableau de bord</a>
    </div>
</div>
