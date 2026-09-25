<?php
use App\Support\Csrf;
use App\Support\View;

/** @var array $suppliers */
/** @var array $executeurs */
/** @var bool $hasComptabilite */
$pageTitle = 'Fournisseurs';
$nbActifs = count(array_filter($suppliers, static fn($s) => (bool) $s['is_active']));
$sansExec = count(array_filter($suppliers, static fn($s) => !$s['executeur_id']));
$defaut = $hasComptabilite ? 'Comptabilité (par défaut)' : 'Exécuteurs (par défaut)';

$executeurSelect = static function (string $id, ?int $selected) use ($executeurs, $defaut): string {
    $html = '<select id="' . $id . '" name="executeur_id" aria-label="Exécuteur attitré"><option value="0">— ' . View::e($defaut) . ' —</option>';
    foreach ($executeurs as $u) {
        $html .= '<option value="' . (int) $u['id'] . '"' . ((int) $selected === (int) $u['id'] ? ' selected' : '') . '>'
            . View::e($u['display_name']) . '</option>';
    }
    return $html . '</select>';
};
?>
<div class="page-head">
    <div>
        <h1>Fournisseurs</h1>
        <p class="page-sub"><?= $nbActifs ?> fournisseur<?= $nbActifs > 1 ? 's' : '' ?> proposé<?= $nbActifs > 1 ? 's' : '' ?> aux demandeurs · la saisie libre reste toujours possible</p>
    </div>
</div>

<div class="stack">
    <div class="notice">
        <span class="notice-icon tone-info"><?= View::icon('package') ?></span>
        <div>
            <strong>Qui passe les commandes ?</strong>
            <p>Chaque fournisseur peut avoir un <strong>exécuteur attitré</strong> : il reçoit les commandes validées pour ce fournisseur.
                Pour un fournisseur absent de cette liste ou sans exécuteur, c’est la <strong><?= $hasComptabilite ? 'comptabilité' : 'personne ayant le rôle Exécuteur' ?></strong> qui s’en charge.
                <?php if ($sansExec && $suppliers): ?><?= $sansExec ?> fournisseur<?= $sansExec > 1 ? 's sont gérés' : ' est géré' ?> par défaut.<?php endif; ?></p>
        </div>
    </div>

    <section class="glass card">
        <div class="card-head">
            <div>
                <h2>Ajouter un fournisseur</h2>
                <div class="sub">Il apparaîtra en suggestion dans le champ « Fournisseur »</div>
            </div>
        </div>
        <form method="post" action="/admin/fournisseurs" class="form-grid supplier-form">
            <?= Csrf::field() ?>
            <div class="field">
                <label class="field-label" for="new-sup">Nom du fournisseur</label>
                <input type="text" id="new-sup" name="name" placeholder="Amazon, LDLC…" required maxlength="150">
            </div>
            <div class="field">
                <label class="field-label" for="new-exec">Exécuteur attitré</label>
                <?= $executeurSelect('new-exec', null) ?>
            </div>
            <div class="field service-form-action">
                <button type="submit" class="btn btn-primary"><?= View::icon('plus', 16) ?> Ajouter</button>
            </div>
        </form>
    </section>

    <section class="glass card card-flush" data-filter-scope>
        <div class="toolbar">
            <h2 class="toolbar-title">Liste des fournisseurs</h2>
            <?php if ($suppliers): ?>
                <label class="search">
                    <?= View::icon('search', 16) ?>
                    <input type="search" placeholder="Rechercher un fournisseur ou un exécuteur…" data-filter-input aria-label="Rechercher">
                </label>
            <?php endif; ?>
        </div>

        <?php if (!$suppliers): ?>
            <div class="empty">
                <span class="empty-icon"><?= View::icon('store', 26) ?></span>
                <h3>Aucun fournisseur</h3>
                <p>Ajoutez vos fournisseurs habituels pour accélérer la saisie des commandes et désigner qui les passe.</p>
            </div>
        <?php else: ?>
            <div>
                <?php foreach ($suppliers as $s):
                    $sid = (int) $s['id'];
                    $actif = (bool) $s['is_active'];
                    $execInactif = $s['executeur_id'] && !$s['executeur_actif']; ?>
                    <div class="list-row supplier-row" data-filter-item data-filter-text="<?= View::e($s['name'] . ' ' . ($s['executeur_nom'] ?? $defaut)) ?>">
                        <span class="supplier-badge"><?= View::e(mb_strtoupper(mb_substr($s['name'], 0, 2))) ?></span>
                        <form class="supplier-edit" method="post" action="/admin/fournisseurs/<?= $sid ?>/renommer">
                            <?= Csrf::field() ?>
                            <input type="text" name="name" value="<?= View::e($s['name']) ?>" required maxlength="150" aria-label="Nom du fournisseur">
                            <?= $executeurSelect('exec-' . $sid, $s['executeur_id'] ? (int) $s['executeur_id'] : null) ?>
                            <button type="submit" class="btn btn-secondary btn-sm">Enregistrer</button>
                        </form>
                        <?php if ($execInactif): ?><span class="pill danger" title="Compte désactivé : la comptabilité prend le relais">Exécuteur inactif</span><?php endif; ?>
                        <span class="status <?= $actif ? 'status-valide' : 'status-brouillon' ?>"><?= $actif ? 'Actif' : 'Masqué' ?></span>
                        <form method="post" action="/admin/fournisseurs/<?= $sid ?>/actif">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="actif" value="<?= $actif ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" title="<?= $actif ? 'Ne plus proposer ce fournisseur' : 'Proposer de nouveau ce fournisseur' ?>">
                                <?= View::icon('power', 14) ?> <?= $actif ? 'Masquer' : 'Afficher' ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="filter-empty" data-filter-empty hidden>Aucun fournisseur ne correspond.</div>
        <?php endif; ?>
    </section>
</div>
