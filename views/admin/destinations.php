<?php
use App\Support\Csrf;
use App\Support\View;

/** @var array $tree */
$pageTitle = 'Destinations';

$countDescendants = static function (array $node) use (&$countDescendants): int {
    $n = 0;
    foreach ($node['children'] as $child) {
        $n += 1 + $countDescendants($child);
    }
    return $n;
};

$renderNode = static function (array $node, int $depth, bool $parentHidden) use (&$renderNode, $countDescendants): void {
    $id = (int) $node['id'];
    $actif = (bool) $node['is_active'];
    $hidden = $parentHidden || !$actif;
    $descendants = $countDescendants($node);
    $kind = $depth === 0 ? 'pôle' : 'lieu';
    ?>
    <div class="dest-node<?= $hidden ? ' is-hidden' : '' ?>" data-dest-id="<?= $id ?>" data-filter-item data-filter-text="<?= View::e($node['name']) ?>">
        <div class="dest-row">
            <button type="button" class="dest-handle" data-dest-handle
                    title="Glisser pour déplacer (ou flèches ↑ ↓ du clavier)"
                    aria-label="Déplacer « <?= View::e($node['name']) ?> »"><?= View::icon('grip', 16) ?></button>
            <span class="dest-icon <?= $depth === 0 ? 'tone-primary' : 'tone-neutral' ?>"><?= View::icon($depth === 0 ? 'building' : 'map-pin', 16) ?></span>

            <form class="rename" method="post" action="/admin/destinations/<?= $id ?>/renommer">
                <?= Csrf::field() ?>
                <input type="text" name="name" value="<?= View::e($node['name']) ?>" required maxlength="120" aria-label="Nom">
                <button type="submit" class="btn btn-secondary btn-sm">Renommer</button>
            </form>

            <?php if (!$actif): ?><span class="status status-brouillon">Masqué</span><?php endif; ?>
            <?php if ($descendants): ?><span class="pill"><?= $descendants ?> sous-lieu<?= $descendants > 1 ? 'x' : '' ?></span><?php endif; ?>

            <details class="dest-add">
                <summary class="btn btn-ghost btn-sm"><?= View::icon('plus', 14) ?> Sous-lieu</summary>
                <form method="post" action="/admin/destinations" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="parent_id" value="<?= $id ?>">
                    <input type="text" name="name" required maxlength="120" placeholder="Nouveau lieu dans « <?= View::e($node['name']) ?> »">
                    <button type="submit" class="btn btn-primary btn-sm">Ajouter</button>
                </form>
            </details>

            <div class="dest-actions">
                <form method="post" action="/admin/destinations/<?= $id ?>/actif">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="actif" value="<?= $actif ? '0' : '1' ?>">
                    <button type="submit" class="btn-icon" title="<?= $actif ? 'Masquer (et ses sous-lieux)' : 'Afficher' ?>" aria-label="<?= $actif ? 'Masquer' : 'Afficher' ?>">
                        <?= View::icon($actif ? 'eye' : 'power', 16) ?>
                    </button>
                </form>
                <form method="post" action="/admin/destinations/<?= $id ?>/supprimer"
                      data-confirm="<?= $descendants ? 'Ses ' . $descendants . ' sous-lieu(x) seront aussi supprimés. ' : '' ?>Les commandes existantes conservent le nom de la destination."
                      data-confirm-title="Supprimer le <?= $kind ?> « <?= View::e($node['name']) ?> » ?"
                      data-confirm-label="Supprimer" data-confirm-variant="danger">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn-icon danger" title="Supprimer" aria-label="Supprimer"><?= View::icon('trash', 16) ?></button>
                </form>
            </div>
        </div>

        <?php // Toujours présent : zone de dépôt pour transformer un lieu en parent. ?>
        <div class="dest-children<?= $node['children'] ? '' : ' is-empty' ?>" data-dest-list data-parent-id="<?= $id ?>"
             data-drop-label="Déposer ici pour en faire un sous-lieu de « <?= View::e($node['name']) ?> »">
            <?php foreach ($node['children'] as $child) {
                $renderNode($child, $depth + 1, $hidden);
            } ?>
        </div>
    </div>
    <?php
};
?>
<div class="page-head">
    <div>
        <h1>Destinations</h1>
        <p class="page-sub">Arborescence proposée dans le champ « Destination » des articles : pôles, lieux et sous-lieux</p>
    </div>
</div>

<div class="stack">
    <section class="glass card">
        <div class="card-head">
            <div>
                <h2>Ajouter un pôle</h2>
                <div class="sub">Ajoutez ensuite ses lieux avec « + Sous-lieu », sur autant de niveaux que nécessaire</div>
            </div>
        </div>
        <form method="post" action="/admin/destinations" class="inline-form">
            <?= Csrf::field() ?>
            <input type="text" name="name" required maxlength="120" placeholder="Pôle 3, Internat…" aria-label="Nom du pôle">
            <button type="submit" class="btn btn-primary"><?= View::icon('plus', 16) ?> Ajouter le pôle</button>
        </form>
    </section>

    <section class="glass card card-flush" data-filter-scope>
        <div class="toolbar">
            <h2 class="toolbar-title">Arborescence</h2>
            <?php if ($tree): ?>
                <label class="search">
                    <?= View::icon('search', 16) ?>
                    <input type="search" placeholder="Rechercher un lieu…" data-filter-input aria-label="Rechercher une destination">
                </label>
            <?php endif; ?>
        </div>

        <?php if (!$tree): ?>
            <div class="empty">
                <span class="empty-icon"><?= View::icon('map-pin', 26) ?></span>
                <h3>Aucune destination</h3>
                <p>Créez un premier pôle, puis ses lieux.</p>
            </div>
        <?php else: ?>
            <p class="dest-help"><?= View::icon('grip', 14) ?> Glissez un élément par sa poignée pour changer son ordre, ou déposez-le dans un autre pôle ou lieu. L’ordre est celui de la liste « Destination » des demandeurs.</p>
            <div class="dest-tree" data-dest-tree data-dest-list data-parent-id=""
                 data-reorder-url="/admin/destinations/ordre" data-csrf="<?= View::e(Csrf::token()) ?>">
                <?php foreach ($tree as $root) {
                    $renderNode($root, 0, false);
                } ?>
            </div>
            <div class="filter-empty" data-filter-empty hidden>Aucune destination ne correspond.</div>
        <?php endif; ?>
    </section>

    <p class="field-hint">
        Les pôles qui contiennent des lieux servent de groupes dans la liste : on choisit un lieu ou un sous-lieu.
        Un élément masqué (et tout son contenu) n’est plus proposé, sans toucher aux commandes existantes.
    </p>
</div>
