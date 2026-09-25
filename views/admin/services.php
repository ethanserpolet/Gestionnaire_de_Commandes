<?php
use App\Support\Csrf;
use App\Support\View;

/** @var array $services */
/** @var array $responsables */
$pageTitle = 'Services';
$n = count($services);
$sansResp = count(array_filter($services, static fn($s) => !$s['responsable_ids']));

$responsableChips = static function (array $selected) use ($responsables): string {
    if (!$responsables) {
        return '<span class="field-hint">Aucun utilisateur n’a le rôle « Responsable de service ».</span>';
    }
    $html = '<div class="toggle-group">';
    foreach ($responsables as $r) {
        $checked = in_array((int) $r['id'], $selected, true) ? ' checked' : '';
        $html .= '<label class="toggle-chip"><input type="checkbox" name="responsables[]" value="' . (int) $r['id'] . '"' . $checked . '>'
            . '<span>' . View::icon('check', 14) . View::e($r['display_name']) . '</span></label>';
    }
    return $html . '</div>';
};
?>
<div class="page-head">
    <div>
        <h1>Services</h1>
        <p class="page-sub">
            <?= $n ?> service<?= $n > 1 ? 's' : '' ?>
            <?php if ($sansResp): ?> · <strong><?= $sansResp ?> sans responsable</strong><?php endif; ?>
            · un responsable du service valide en premier chaque commande
        </p>
    </div>
</div>

<div class="stack">
    <?php if (!$responsables): ?>
        <div class="notice">
            <span class="notice-icon tone-warning"><?= View::icon('alert') ?></span>
            <div>
                <strong>Aucun responsable de service disponible</strong>
                <p>Attribuez d’abord le rôle « Responsable de service » aux personnes concernées dans <a href="/admin/utilisateurs">Utilisateurs</a>.</p>
            </div>
        </div>
    <?php endif; ?>

    <section class="glass card">
        <div class="card-head">
            <div>
                <h2>Ajouter un service</h2>
                <div class="sub">Les utilisateurs choisiront leur service à leur prochaine connexion</div>
            </div>
        </div>
        <form method="post" action="/admin/services" class="form-grid">
            <?= Csrf::field() ?>
            <div class="field">
                <label class="field-label" for="new-name">Nom du service</label>
                <input type="text" id="new-name" name="name" required maxlength="120" placeholder="Vie scolaire, Service informatique…">
            </div>
            <div class="field">
                <span class="field-label">Responsables</span>
                <?= $responsableChips([]) ?>
            </div>
            <div>
                <button type="submit" class="btn btn-primary"><?= View::icon('plus', 16) ?> Ajouter le service</button>
            </div>
        </form>
    </section>

    <?php if (!$services): ?>
        <section class="glass card">
            <div class="empty">
                <span class="empty-icon"><?= View::icon('building', 26) ?></span>
                <h3>Aucun service</h3>
                <p>Créez les services du lycée et désignez leurs responsables : l’un d’eux validera en premier les commandes de son équipe.</p>
            </div>
        </section>
    <?php else: ?>
        <div class="service-grid">
            <?php foreach ($services as $s): $sid = (int) $s['id']; $nbResp = count($s['responsable_ids']); ?>
                <article class="glass service-card">
                    <form method="post" action="/admin/services/<?= $sid ?>/modifier" class="form-grid">
                        <?= Csrf::field() ?>
                        <div class="service-card-head">
                            <span class="stat-icon tone-info"><?= View::icon('building', 20) ?></span>
                            <div class="field" style="flex:1">
                                <label class="field-label" for="svc-name-<?= $sid ?>">Nom</label>
                                <input type="text" id="svc-name-<?= $sid ?>" name="name" value="<?= View::e($s['name']) ?>" required maxlength="120">
                            </div>
                        </div>
                        <div class="field">
                            <span class="field-label">Responsables<?= $nbResp ? ' (' . $nbResp . ')' : '' ?></span>
                            <?= $responsableChips($s['responsable_ids']) ?>
                            <span class="field-hint">
                                <?php if ($nbResp === 0): ?>
                                    Sans responsable, les commandes passent directement en comptabilité / direction.
                                <?php elseif ($nbResp > 1): ?>
                                    Le demandeur choisira lequel valide sa commande.
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="user-foot">
                            <span class="small muted"><?= (int) $s['nb_membres'] ?> membre<?= (int) $s['nb_membres'] > 1 ? 's' : '' ?></span>
                            <div class="actions">
                                <button type="submit" form="del-svc-<?= $sid ?>" class="btn btn-danger-ghost btn-sm"><?= View::icon('trash', 14) ?> Supprimer</button>
                                <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                            </div>
                        </div>
                    </form>
                    <form id="del-svc-<?= $sid ?>" method="post" action="/admin/services/<?= $sid ?>/supprimer" hidden
                          data-confirm="Les <?= (int) $s['nb_membres'] ?> membre(s) de ce service devront choisir un nouveau service à leur prochaine visite. Les commandes existantes sont conservées."
                          data-confirm-title="Supprimer le service « <?= View::e($s['name']) ?> » ?"
                          data-confirm-label="Supprimer" data-confirm-variant="danger">
                        <?= Csrf::field() ?>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
