<?php
use App\Repositories\DestinationRepository;
use App\Support\View;

/** @var array $commandes */
$showFilters = $showFilters ?? false;
$showDemandeur = $showDemandeur ?? true;
// Mode avancé : colonnes Service / Destination, filtres et tri (lecteurs, validateurs, exécuteurs).
$advanced = $advanced ?? false;
if ($advanced) {
    $showFilters = true;
}
?>
<?php if (empty($commandes)): ?>
    <div class="empty">
        <span class="empty-icon"><?= View::icon('inbox', 26) ?></span>
        <h3><?= View::e($emptyTitle ?? 'Aucune commande') ?></h3>
        <p><?= View::e($emptyText ?? 'Rien à afficher pour le moment.') ?></p>
        <?= $emptyAction ?? '' ?>
    </div>
<?php else:
    $presentStatuts = array_unique(array_column($commandes, 'statut'));
    $chipDefs = [
        'all' => 'Toutes',
        'brouillon' => 'Brouillons',
        'en_attente,en_validation' => 'En validation',
        'valide' => 'Validées',
        'finalise' => 'Finalisées',
        'refuse' => 'Refusées',
    ];

    $servicesPresents = [];
    $destinationFilter = [];
    if ($advanced) {
        foreach ($commandes as $c) {
            if (!empty($c['service_nom'])) {
                $servicesPresents[$c['service_nom']] = true;
            }
            // Chaque chemin et chacun de ses parents : filtrer « Pôle 1 » inclut tous ses lieux.
            foreach (array_filter(explode('|', (string) ($c['destinations'] ?? ''))) as $path) {
                $parts = explode(DestinationRepository::SEPARATOR, $path);
                for ($i = 1, $n = count($parts); $i <= $n; $i++) {
                    $destinationFilter[implode(DestinationRepository::SEPARATOR, array_slice($parts, 0, $i))] = $i - 1;
                }
            }
        }
        $servicesPresents = array_keys($servicesPresents);
        natcasesort($servicesPresents);
        uksort($destinationFilter, 'strnatcasecmp');
    }
    ?>
    <div data-filter-scope>
        <?php if ($showFilters): ?>
            <div class="toolbar toolbar-stack">
                <div class="toolbar-row">
                    <label class="search">
                        <?= View::icon('search', 16) ?>
                        <input type="search" placeholder="Rechercher un n°, un demandeur, un fournisseur…" data-filter-input aria-label="Rechercher">
                    </label>
                    <?php if ($advanced): ?>
                        <select data-filter-field="service" aria-label="Filtrer par service" class="toolbar-select">
                            <option value="">Tous les services</option>
                            <?php foreach ($servicesPresents as $svc): ?>
                                <option value="<?= View::e($svc) ?>"><?= View::e($svc) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select data-filter-field="destination" aria-label="Filtrer par destination" class="toolbar-select">
                            <option value="">Toutes les destinations</option>
                            <?php foreach ($destinationFilter as $path => $depth):
                                $segments = explode(DestinationRepository::SEPARATOR, (string) $path);
                                $label = str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . ($depth ? '↳ ' : '') . end($segments); ?>
                                <option value="<?= View::e($path) ?>"><?= View::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select data-sort-select aria-label="Trier" class="toolbar-select">
                            <option value="date:desc">Date : plus récentes</option>
                            <option value="date:asc">Date : plus anciennes</option>
                            <option value="service:asc">Service (A → Z)</option>
                            <option value="destination:asc">Destination (A → Z)</option>
                            <option value="total:desc">Montant décroissant</option>
                            <option value="total:asc">Montant croissant</option>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="chips" role="group" aria-label="Filtrer par statut">
                    <?php foreach ($chipDefs as $chipValue => $chipLabel):
                        if ($chipValue !== 'all' && !array_intersect(explode(',', $chipValue), $presentStatuts)) {
                            continue;
                        } ?>
                        <button type="button" class="chip<?= $chipValue === 'all' ? ' active' : '' ?>" data-filter-chip="<?= View::e($chipValue) ?>"><?= View::e($chipLabel) ?></button>
                    <?php endforeach; ?>
                    <?php if ($advanced): ?><span class="filter-count" data-filter-count></span><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr>
                    <th>Commande</th>
                    <?php if ($showDemandeur): ?><th>Demandeur</th><?php endif; ?>
                    <?php if ($advanced): ?>
                        <th><button type="button" class="th-sort" data-sort-key="service">Service</button></th>
                        <th><button type="button" class="th-sort" data-sort-key="destination">Destination</button></th>
                        <th><button type="button" class="th-sort is-sorted desc" data-sort-key="date">Date</button></th>
                        <th class="num"><button type="button" class="th-sort" data-sort-key="total">Montant TTC</button></th>
                    <?php else: ?>
                        <th>Date</th>
                        <th class="num">Montant TTC</th>
                    <?php endif; ?>
                    <th>Statut</th>
                    <th aria-hidden="true"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($commandes as $c):
                    $href = '/commandes/' . (int) $c['id'];
                    $nb = (int) ($c['nb_lignes'] ?? 0);
                    $sub = $nb === 0
                        ? 'Aucun article'
                        : trim(($c['fournisseurs'] ?? '') . ' · ' . $nb . ' article' . ($nb > 1 ? 's' : ''), ' ·');
                    $destinations = array_values(array_filter(explode('|', (string) ($c['destinations'] ?? ''))));
                    ?>
                    <tr data-href="<?= $href ?>" data-status="<?= View::e($c['statut']) ?>" data-filter-item
                        data-date="<?= View::e($c['submitted_at'] ?: $c['date_demande']) ?>"
                        data-total="<?= (float) ($c['total'] ?? 0) ?>"
                        data-service="<?= View::e($c['service_nom'] ?? '') ?>"
                        data-destination="<?= View::e($destinations[0] ?? '') ?>"
                        data-destinations="<?= View::e(implode('|', $destinations)) ?>">
                        <td data-label="Commande">
                            <div class="cell-title">
                                <a href="<?= $href ?>"><?= View::e($c['numero_commande'] ?: 'Brouillon #' . $c['id']) ?></a>
                                <span class="cell-sub"><?= View::e($sub) ?></span>
                            </div>
                        </td>
                        <?php if ($showDemandeur): ?>
                            <td data-label="Demandeur">
                                <div class="cell-user"><?= View::avatar($c['demandeur_nom'], 'sm') ?><span><?= View::e($c['demandeur_nom']) ?></span></div>
                            </td>
                        <?php endif; ?>
                        <?php if ($advanced): ?>
                            <td data-label="Service"><?= $c['service_nom'] ? View::e($c['service_nom']) : '<span class="muted">—</span>' ?></td>
                            <td data-label="Destination">
                                <?php if ($destinations): ?>
                                    <span class="cell-dest" title="<?= View::e(implode("\n", $destinations)) ?>"><?= View::e($destinations[0]) ?></span>
                                    <?php if (count($destinations) > 1): ?><span class="pill">+<?= count($destinations) - 1 ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td data-label="Date"><?= View::date($c['date_demande']) ?></td>
                        <td data-label="Montant" class="num"><?= View::money($c['total'] ?? 0) ?></td>
                        <td data-label="Statut"><?= View::badge($c['statut']) ?></td>
                        <td class="cell-chevron"><?= View::icon('chevron-right') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="filter-empty" data-filter-empty hidden>Aucune commande ne correspond à ces critères.</div>
    </div>
<?php endif; ?>
<?php unset($showFilters, $showDemandeur, $advanced, $emptyTitle, $emptyText, $emptyAction); ?>
