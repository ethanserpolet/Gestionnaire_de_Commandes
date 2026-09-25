<?php
use App\Support\View;

$pageTitle = 'Connexion';
?>
<div class="glass auth-card">
    <div class="auth-logo"><?= View::icon('receipt', 28) ?></div>
    <h1>Commandes St Marc</h1>
    <p>Demandes d’achat, validations et suivi des commandes du lycée. Connectez-vous avec votre compte <strong>@st-marc.eu</strong>.</p>

    <a href="/auth/microsoft" class="btn-ms">
        <svg width="20" height="20" viewBox="0 0 21 21" aria-hidden="true"><rect width="9.5" height="9.5" fill="#f25022"/><rect x="11.5" width="9.5" height="9.5" fill="#7fba00"/><rect y="11.5" width="9.5" height="9.5" fill="#00a4ef"/><rect x="11.5" y="11.5" width="9.5" height="9.5" fill="#ffb900"/></svg>
        Se connecter avec Microsoft
    </a>

    <div class="auth-foot"><?= View::icon('shield', 14) ?> Accès réservé au personnel du Lycée St Marc</div>
</div>
<p class="auth-legal">Lycée St Marc · Service informatique</p>
