<?php
use App\Support\View;

$pageTitle = 'Erreur';
?>
<div class="error-wrap">
    <div class="glass error-card">
        <div class="error-code">500</div>
        <h1>Une erreur est survenue</h1>
        <p>Réessayez dans un instant. Si le problème persiste, contactez le service informatique.</p>
        <?php if (!empty($debugMessage)): ?>
            <pre style="text-align:left;white-space:pre-wrap;word-break:break-word;font-size:12.5px;padding:12px 14px;border-radius:12px;background:var(--danger-soft);color:var(--danger);margin:0 0 20px"><?= View::e($debugMessage) ?></pre>
        <?php endif; ?>
        <a href="/" class="btn btn-primary"><?= View::icon('arrow-left', 16) ?> Retour au tableau de bord</a>
    </div>
</div>
