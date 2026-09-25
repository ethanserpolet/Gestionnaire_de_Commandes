<?php
use App\Support\View;

/** @var array $items */
?>
<?php if (empty($items)): ?>
    <div class="empty empty-sm">
        <span class="empty-icon"><?= View::icon('inbox', 22) ?></span>
        <p><?= View::e($emptyText ?? 'Rien à afficher.') ?></p>
    </div>
<?php else: ?>
    <div class="mini-list">
        <?php foreach ($items as $c):
            $meta = [$c['demandeur_nom'] ?? '', View::date($c['date_demande'])];
            if (!empty($c['fournisseurs'])) {
                $meta[] = $c['fournisseurs'];
            } ?>
            <a class="mini-row" href="/commandes/<?= (int) $c['id'] ?>">
                <?= View::avatar($c['demandeur_nom'] ?? '?', 'sm') ?>
                <div class="mini-main">
                    <strong><?= View::e($c['numero_commande'] ?: 'Brouillon #' . $c['id']) ?></strong>
                    <span><?= View::e(implode(' · ', array_filter($meta))) ?></span>
                </div>
                <div class="mini-end">
                    <span class="mini-amount"><?= View::money($c['total'] ?? 0) ?></span>
                    <?= View::badge($c['statut']) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php unset($items, $emptyText); ?>
