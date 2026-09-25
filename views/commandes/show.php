<?php
use App\Support\Csrf;
use App\Support\Roles;
use App\Support\View;

/** @var array $commande */
/** @var array $suppliers */
/** @var array $destinationOptions */
/** @var array $responsablesChoix */
/** @var bool $demandeurEstResponsable */
/** @var bool $isOwner */
/** @var array $actionableSlots */
/** @var array $actionableExecutions */
/** @var array $currentUser */

$id = (int) $commande['id'];
$statut = $commande['statut'];
$userRoles = $currentUser['roles'];
$editable = $isOwner && $statut === 'brouillon';
$canDecide = !empty($actionableSlots);
$decideRoles = implode(' et ', array_unique(array_map(static fn($s) => Roles::label($s['role']), $actionableSlots)));
$executions = $commande['executions'];
$canFinalize = !empty($actionableExecutions);
$actionableIds = array_map(static fn($e) => (int) $e['id'], $actionableExecutions);
$nbPassees = count(array_filter($executions, static fn($e) => $e['done_at'] !== null));
$titre = $commande['numero_commande'] ?: 'Brouillon #' . $id;
$pageTitle = $titre;

if ($isOwner) {
    $backHref = '/commandes/mes';
} elseif (Roles::isValidator($currentUser) && in_array($statut, ['en_attente', 'en_validation', 'refuse'], true)) {
    $backHref = '/commandes/a-valider';
} elseif (in_array('executeur', $userRoles, true)) {
    $backHref = '/commandes/a-finaliser';
} elseif (in_array('lecteur', $userRoles, true)) {
    $backHref = '/commandes/validees';
} else {
    $backHref = '/';
}

$total = 0.0;
$groupes = [];
foreach ($commande['lignes'] as $ligne) {
    $total += (float) $ligne['prix_ttc'];
    $groupes[$ligne['fournisseur']][] = $ligne;
}
uksort($groupes, 'strnatcasecmp');
$nbArticles = count($commande['lignes']);

$validations = $commande['validations'];
$nbVal = count($validations);
$nbOk = count(array_filter($validations, static fn($v) => $v['decision'] === 'valide'));
$inValidation = in_array($statut, ['en_attente', 'en_validation'], true);
$pendingStages = array_map(static fn($v) => (int) $v['etape'], array_filter($validations, static fn($v) => $v['decision'] === 'en_attente'));
$currentStage = $inValidation && $pendingStages ? min($pendingStages) : null;
$stageSlots = [
    1 => array_values(array_filter($validations, static fn($v) => (int) $v['etape'] === 1)),
    2 => array_values(array_filter($validations, static fn($v) => (int) $v['etape'] === 2)),
];
$stageTitles = [1 => 'Responsable de service', 2 => 'Comptabilité et direction'];

// Stepper : chaque étape reçoit un état (done / current / refused / skipped / todo) et un sous-titre.
$stageStep = static function (int $stage) use ($stageSlots, $currentStage, $statut): array {
    $slots = $stageSlots[$stage];
    $label = $stage === 1 ? 'Resp. service' : 'Direction';
    if ($statut === 'brouillon') {
        return [$label, '', 'todo'];
    }
    if (!$slots) {
        return [$label, 'Non requise', 'skipped'];
    }
    $ok = count(array_filter($slots, static fn($v) => $v['decision'] === 'valide'));
    $sub = $ok . ' / ' . count($slots);
    if (array_filter($slots, static fn($v) => $v['decision'] === 'refuse')) {
        return ['Refusée', $sub, 'refused'];
    }
    if ($ok === count($slots)) {
        return [$label, $sub, 'done'];
    }
    return [$label, $sub, $currentStage === $stage ? 'current' : 'todo'];
};
$submitted = $statut !== 'brouillon';
$refused = $statut === 'refuse';
$allDone = $statut === 'finalise';
$steps = [
    ['Brouillon', View::date($commande['created_at']), $submitted ? 'done' : 'current'],
    ['Envoyée', $submitted ? View::date($commande['submitted_at']) : '', $submitted ? 'done' : 'todo'],
    $stageStep(1),
    $stageStep(2),
    ['Validée', '', in_array($statut, ['valide', 'finalise'], true) ? 'done' : 'todo'],
    ['Finalisée',
        $commande['finalized_at'] ? View::date($commande['finalized_at']) : ($statut === 'valide' && $executions ? $nbPassees . ' / ' . count($executions) : ''),
        $statut === 'finalise' ? 'done' : ($statut === 'valide' ? 'current' : 'todo')],
];

$eventLabels = [
    'creation' => ['Brouillon créé', 'edit', 'neutral'],
    'soumission' => ['Commande envoyée', 'send', 'info'],
    'validation' => ['Validation accordée', 'check', 'success'],
    'refus' => ['Commande refusée', 'x', 'danger'],
    'finalisation' => ['Commande finalisée', 'package', 'teal'],
    'execution' => ['Commande passée', 'package', 'teal'],
    'info' => ['Information', 'alert', 'neutral'],
];
?>

<a href="<?= $backHref ?>" class="back-link"><?= View::icon('arrow-left', 16) ?> Retour</a>

<section class="glass detail-head">
    <div class="detail-top">
        <div>
            <div class="detail-title">
                <h1><?= View::e($titre) ?></h1>
                <?= View::badge($statut, 'status-lg') ?>
            </div>
            <div class="detail-meta">
                <span><?= View::avatar($commande['demandeur_nom'], 'sm') ?> <?= View::e($commande['demandeur_nom']) ?></span>
                <?php if (!empty($commande['service_nom'])): ?>
                    <span><?= View::icon('building', 16) ?> <?= View::e($commande['service_nom']) ?></span>
                <?php endif; ?>
                <span><?= View::icon('clock', 16) ?> Demandée le <?= View::date($commande['date_demande']) ?></span>
                <span><?= View::icon('package', 16) ?> <?= $nbArticles ?> article<?= $nbArticles > 1 ? 's' : '' ?></span>
                <?php if ($commande['submitted_at']): ?>
                    <span><?= View::icon('edit', 16) ?> Signée par <?= View::e($commande['demandeur_nom']) ?> le <?= View::date($commande['submitted_at'], true) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="detail-total">
            <div class="label">Total TTC</div>
            <div class="value"><?= View::money($total) ?></div>
            <?php if ($statut !== 'brouillon'): ?>
                <a href="/commandes/<?= $id ?>/pdf?print=1" target="_blank" rel="noopener" class="btn btn-secondary btn-sm detail-pdf">
                    <?= View::icon('download', 16) ?> Télécharger le PDF
                </a>
            <?php endif; ?>
        </div>
    </div>

    <ol class="stepper" aria-label="Avancement de la commande">
        <?php foreach ($steps as $i => [$stepLabel, $stepSub, $state]): ?>
            <li class="step <?= $state ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
                <span class="step-dot">
                    <?php if ($state === 'done'): ?><?= View::icon('check', 16) ?>
                    <?php elseif ($state === 'refused'): ?><?= View::icon('x', 16) ?>
                    <?php elseif ($state === 'skipped'): ?>–
                    <?php else: ?><?= $i + 1 ?><?php endif; ?>
                </span>
                <span class="step-label"><?= View::e($stepLabel) ?></span>
                <?php if ($stepSub !== '' && $state !== 'todo'): ?>
                    <span class="step-sub"><?= View::e($stepSub) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<div class="layout-detail">
    <div class="stack">
        <section class="glass card">
            <div class="card-head">
                <div>
                    <h2>Articles</h2>
                    <div class="sub">Regroupés par fournisseur</div>
                </div>
                <?php if ($editable && $groupes): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-open-dialog="dlg-article"><?= View::icon('plus', 16) ?> Ajouter un article</button>
                <?php endif; ?>
            </div>

            <?php if (!$groupes): ?>
                <div class="empty">
                    <span class="empty-icon"><?= View::icon('package', 26) ?></span>
                    <h3>Aucun article</h3>
                    <?php if ($editable): ?>
                        <p>Ajoutez les articles à commander. Vous pouvez mélanger plusieurs fournisseurs dans la même commande, ils seront regroupés automatiquement.</p>
                        <button type="button" class="btn btn-primary" data-open-dialog="dlg-article"><?= View::icon('plus') ?> Ajouter un premier article</button>
                    <?php else: ?>
                        <p>Cette commande ne contient aucun article.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($groupes as $fournisseur => $lignes):
                    $fournisseur = (string) $fournisseur;
                    $sousTotal = array_sum(array_map(static fn($l) => (float) $l['prix_ttc'], $lignes)); ?>
                    <div class="supplier">
                        <div class="supplier-head">
                            <div class="supplier-name">
                                <span class="supplier-badge"><?= View::e(mb_strtoupper(mb_substr($fournisseur, 0, 2))) ?></span>
                                <div>
                                    <?= View::e($fournisseur) ?>
                                    <div class="supplier-sub"><?= count($lignes) ?> article<?= count($lignes) > 1 ? 's' : '' ?></div>
                                </div>
                            </div>
                            <div class="supplier-total"><?= View::money($sousTotal) ?></div>
                        </div>

                        <?php foreach ($lignes as $ligne): $ligneId = (int) $ligne['id']; ?>
                            <div class="item">
                                <div class="item-main">
                                    <div class="item-desc">
                                        <?= $ligne['description'] ? nl2br(View::e($ligne['description'])) : '<span class="muted">Voir pièce jointe</span>' ?>
                                    </div>
                                    <div class="item-meta">
                                        <span class="meta-chip"><?= View::icon('map-pin', 13) ?> <?= View::e($ligne['destination']) ?></span>
                                        <?php if ($ligne['motif']): ?>
                                            <span class="meta-chip"><?= View::icon('tag', 13) ?> <?= View::e($ligne['motif']) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($ligne['fichiers'] || $editable): ?>
                                        <div class="item-files">
                                            <?php foreach ($ligne['fichiers'] as $f):
                                                $isImage = str_starts_with((string) $f['mime_type'], 'image/'); ?>
                                                <div class="file-chip">
                                                    <a href="/fichiers/<?= (int) $f['id'] ?>" target="_blank" rel="noopener" title="<?= View::e($f['nom_original']) ?>">
                                                        <?= View::icon($isImage ? 'image' : 'file', 14) ?>
                                                        <span><?= View::e($f['nom_original']) ?></span>
                                                    </a>
                                                    <small><?= View::bytes($f['taille_octets']) ?></small>
                                                    <?php if ($editable): ?>
                                                        <form method="post" action="/fichiers/<?= (int) $f['id'] ?>/supprimer"
                                                              data-confirm="Le fichier « <?= View::e($f['nom_original']) ?> » sera supprimé."
                                                              data-confirm-title="Supprimer le fichier ?" data-confirm-label="Supprimer" data-confirm-variant="danger">
                                                            <?= Csrf::field() ?>
                                                            <button type="submit" class="btn-icon btn-icon-sm danger" aria-label="Supprimer le fichier"><?= View::icon('x', 14) ?></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if ($editable): ?>
                                                <button type="button" class="btn btn-ghost btn-sm" data-open-dialog="dlg-upload"
                                                        data-form-action="/commandes/<?= $id ?>/lignes/<?= $ligneId ?>/fichiers">
                                                    <?= View::icon('paperclip', 14) ?> Joindre
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="item-figures">
                                    <div class="item-qty"><?= (int) $ligne['quantite'] ?> × <?= View::money($ligne['prix_unitaire']) ?></div>
                                    <div class="item-total"><?= View::money($ligne['prix_ttc']) ?></div>
                                </div>

                                <?php if ($editable): ?>
                                    <form class="item-del" method="post" action="/commandes/<?= $id ?>/lignes/<?= $ligneId ?>/supprimer"
                                          data-confirm="Cet article et ses pièces jointes seront supprimés."
                                          data-confirm-title="Supprimer l’article ?" data-confirm-label="Supprimer" data-confirm-variant="danger">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn-icon danger" aria-label="Supprimer l’article"><?= View::icon('trash', 16) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="total-bar">
                    <span>Total TTC</span>
                    <strong><?= View::money($total) ?></strong>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="stack">
        <?php if ($editable): ?>
            <section class="glass card action-card">
                <div class="card-head"><h2>Envoyer la commande</h2></div>
                <p>Une fois envoyée, la commande reçoit son numéro, est signée à votre nom et part chez les validateurs. Elle ne sera plus modifiable.</p>
                <div class="action-stack">
                    <form method="post" action="/commandes/<?= $id ?>/soumettre"
                          data-confirm="La commande sera transmise aux validateurs par e-mail et ne pourra plus être modifiée."
                          data-confirm-title="Envoyer la commande ?" data-confirm-label="Envoyer">
                        <?= Csrf::field() ?>
                        <?php if (count($responsablesChoix) > 1): ?>
                            <div class="field submit-resp">
                                <label class="field-label" for="f-resp">Responsable qui validera <span class="req">*</span></label>
                                <select id="f-resp" name="responsable_id" required>
                                    <option value="">Choisir…</option>
                                    <?php foreach ($responsablesChoix as $r): ?>
                                        <option value="<?= (int) $r['id'] ?>"><?= View::e($r['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="field-hint">Il sera prévenu par e-mail et validera en premier.</span>
                            </div>
                        <?php elseif (count($responsablesChoix) === 1): ?>
                            <p class="submit-resp-info"><?= View::icon('check-circle', 16) ?> Validée d’abord par <strong><?= View::e($responsablesChoix[0]['display_name']) ?></strong>, responsable de votre service.</p>
                        <?php elseif ($demandeurEstResponsable): ?>
                            <p class="submit-resp-info"><?= View::icon('shield', 16) ?> Vous êtes responsable de votre service : la commande part directement en comptabilité / direction.</p>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-lg btn-block"<?= $nbArticles === 0 ? ' disabled' : '' ?>>
                            <?= View::icon('send') ?> Envoyer pour validation
                        </button>
                    </form>
                    <?php if ($nbArticles === 0): ?>
                        <p class="field-hint" style="margin:0;text-align:center">Ajoutez au moins un article pour pouvoir envoyer.</p>
                    <?php endif; ?>
                    <form method="post" action="/commandes/<?= $id ?>/supprimer"
                          data-confirm="Ce brouillon et toutes ses pièces jointes seront définitivement supprimés."
                          data-confirm-title="Supprimer le brouillon ?" data-confirm-label="Supprimer" data-confirm-variant="danger">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-danger-ghost btn-block"><?= View::icon('trash', 16) ?> Supprimer le brouillon</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($canDecide): ?>
            <section class="glass card action-card">
                <div class="card-head"><h2>Votre décision</h2></div>
                <p>
                    Votre validation est attendue en tant que <strong><?= View::e($decideRoles) ?></strong>.
                    <?php if ($nbOk > 0): ?><?= $nbOk ?> validation<?= $nbOk > 1 ? 's' : '' ?> déjà obtenue<?= $nbOk > 1 ? 's' : '' ?> sur <?= $nbVal ?>.<?php endif; ?>
                </p>
                <div class="action-stack">
                    <form method="post" action="/commandes/<?= $id ?>/decider"
                          data-confirm="Votre validation sera enregistrée et les personnes concernées seront prévenues par e-mail."
                          data-confirm-title="Valider la commande ?" data-confirm-label="Valider">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="decision" value="valide">
                        <button type="submit" class="btn btn-success btn-lg btn-block"><?= View::icon('check') ?> Valider la commande</button>
                    </form>
                    <button type="button" class="btn btn-danger-ghost btn-block" data-open-dialog="dlg-refus"><?= View::icon('x-circle', 16) ?> Refuser…</button>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($executions): ?>
            <section class="glass card<?= $canFinalize ? ' action-card' : '' ?>">
                <div class="card-head">
                    <h2>Passage des commandes</h2>
                    <span class="pill"><?= $nbPassees ?> / <?= count($executions) ?></span>
                </div>
                <?php if ($canFinalize): ?>
                    <p>Passez la commande auprès <?= count($actionableExecutions) > 1 ? 'des fournisseurs qui vous sont confiés' : 'du fournisseur qui vous est confié' ?>, puis marquez-la comme passée. Le demandeur est prévenu quand toutes les parts sont passées.</p>
                <?php endif; ?>
                <?php foreach ($executions as $e):
                    $done = $e['done_at'] !== null;
                    $qui = $e['assigned_nom'] ?: Roles::label($e['role']);
                    $mine = in_array((int) $e['id'], $actionableIds, true); ?>
                    <div class="v-row exec-row">
                        <span class="supplier-badge"><?= View::e(mb_strtoupper(mb_substr($e['fournisseur'], 0, 2))) ?></span>
                        <div class="v-main">
                            <strong><?= View::e($e['fournisseur']) ?></strong>
                            <span><?= $done
                                ? 'Passée par ' . View::e($e['done_nom']) . ' le ' . View::date($e['done_at'], true)
                                : 'Confiée à ' . View::e($qui) . ($e['assigned_to'] ? '' : ' (tout membre)') ?></span>
                        </div>
                        <?php if ($done): ?>
                            <span class="status status-finalise">Passée</span>
                        <?php elseif ($mine): ?>
                            <form method="post" action="/commandes/<?= $id ?>/finaliser"
                                  data-confirm="Confirmez-vous avoir passé la commande auprès de <?= View::e($e['fournisseur']) ?> ?"
                                  data-confirm-title="Marquer comme passée ?" data-confirm-label="Oui, c’est passé">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="execution_id" value="<?= (int) $e['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm"><?= View::icon('check', 14) ?> Passée</button>
                            </form>
                        <?php else: ?>
                            <span class="status status-en_attente">À passer</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (count($actionableExecutions) > 1): ?>
                    <form method="post" action="/commandes/<?= $id ?>/finaliser" style="margin-top:12px"
                          data-confirm="Toutes les parts qui vous sont confiées seront marquées comme passées."
                          data-confirm-title="Tout marquer comme passé ?" data-confirm-label="Tout marquer">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-secondary btn-block"><?= View::icon('package', 16) ?> Tout marquer comme passé</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($refused): ?>
            <div class="notice">
                <span class="notice-icon tone-danger"><?= View::icon('x-circle') ?></span>
                <div>
                    <strong>Commande refusée</strong>
                    <p><?= $commande['motif_refus'] ? nl2br(View::e($commande['motif_refus'])) : 'Aucun motif précisé.' ?></p>
                </div>
            </div>
        <?php elseif ($allDone): ?>
            <div class="notice">
                <span class="notice-icon tone-teal"><?= View::icon('package') ?></span>
                <div>
                    <strong>Commande finalisée</strong>
                    <p>Passée auprès des fournisseurs le <?= View::date($commande['finalized_at'], true) ?>.</p>
                </div>
            </div>
        <?php elseif ($statut === 'valide' && !$canFinalize): ?>
            <div class="notice">
                <span class="notice-icon tone-success"><?= View::icon('check-circle') ?></span>
                <div>
                    <strong>Commande validée</strong>
                    <p>Elle attend d’être passée auprès des fournisseurs (<?= $nbPassees ?> / <?= count($executions) ?> part<?= count($executions) > 1 ? 's' : '' ?> passée<?= $nbPassees > 1 ? 's' : '' ?>).</p>
                </div>
            </div>
        <?php elseif ($inValidation && !$canDecide):
            $waitingFor = [];
            foreach ($stageSlots[$currentStage ?? 2] ?? [] as $v) {
                if ($v['decision'] === 'en_attente') {
                    $waitingFor[] = Roles::label($v['role']) . ($v['assigned_nom'] ? ' (' . $v['assigned_nom'] . ')' : '');
                }
            } ?>
            <div class="notice">
                <span class="notice-icon tone-info"><?= View::icon('clock') ?></span>
                <div>
                    <strong>Validation en cours — étape <?= (int) $currentStage ?></strong>
                    <p>En attente de : <?= View::e(implode(', ', $waitingFor)) ?>.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($validations): ?>
            <section class="glass card">
                <div class="card-head">
                    <h2>Circuit de validation</h2>
                    <span class="pill"><?= $nbOk ?> / <?= $nbVal ?></span>
                </div>
                <?php foreach ($stageSlots as $stage => $slots):
                    if (!$slots) {
                        continue;
                    }
                    $stageLocked = $currentStage !== null && $stage > $currentStage; ?>
                    <div class="v-stage">
                        <span class="v-stage-num"><?= $stage ?></span>
                        <?= View::e($stageTitles[$stage]) ?>
                    </div>
                    <?php foreach ($slots as $v):
                        $who = $v['validateur_nom'] ?? $v['assigned_nom'];
                        if ($v['decided_at']) {
                            $sub = ($who ? $who . ' · ' : '') . View::date($v['decided_at'], true);
                        } elseif ($stageLocked) {
                            $sub = ($who ? $who . ' · ' : '') . 'Après l’étape ' . ($stage - 1);
                        } else {
                            $sub = $who ?: 'N’importe quel membre peut valider';
                        } ?>
                        <div class="v-row">
                            <?= $who ? View::avatar($who) : '<span class="avatar avatar-role">' . View::icon($v['role'] === 'comptabilite' ? 'euro' : 'shield', 16) . '</span>' ?>
                            <div class="v-main">
                                <strong><?= View::e(Roles::label($v['role'])) ?></strong>
                                <span><?= View::e($sub) ?></span>
                                <?php if ($v['commentaire']): ?>
                                    <div class="v-comment"><?= nl2br(View::e($v['commentaire'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <?= View::badge($v['decision']) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($commande['events']): ?>
            <section class="glass card">
                <div class="card-head"><h2>Historique</h2></div>
                <ol class="timeline">
                    <?php foreach (array_reverse($commande['events']) as $ev):
                        [$evLabel, $evIcon, $evTone] = $eventLabels[$ev['type']] ?? [ucfirst($ev['type']), 'clock', 'neutral']; ?>
                        <li>
                            <span class="tl-dot tone-<?= $evTone ?>"><?= View::icon($evIcon, 12) ?></span>
                            <div class="tl-title"><?= View::e($evLabel) ?></div>
                            <div class="tl-meta"><?= View::e($ev['user_nom'] ?? 'Système') ?> · <?= View::date($ev['created_at'], true) ?></div>
                            <?php if ($ev['details'] && in_array($ev['type'], ['refus', 'validation', 'info', 'execution'], true)): ?>
                                <div class="tl-details"><?= View::e($ev['details']) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php if ($editable): ?>
    <dialog class="modal" id="dlg-article" aria-labelledby="dlg-article-title">
        <form class="modal-card" method="post" action="/commandes/<?= $id ?>/lignes" enctype="multipart/form-data" data-autocalc>
            <?= Csrf::field() ?>
            <div class="modal-head">
                <h2 id="dlg-article-title">Ajouter un article</h2>
                <button type="button" class="btn-icon" data-close-dialog aria-label="Fermer"><?= View::icon('x') ?></button>
            </div>
            <div class="modal-body form-grid">
                <div class="form-grid cols-2">
                    <div class="field">
                        <label class="field-label" for="f-fournisseur">Fournisseur <span class="req">*</span></label>
                        <input type="text" id="f-fournisseur" name="fournisseur" list="fournisseurs-list" required maxlength="150" autocomplete="off" placeholder="Amazon, LDLC…">
                        <datalist id="fournisseurs-list">
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= View::e($s['name']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="field">
                        <label class="field-label" for="f-destination">Destination <span class="req">*</span></label>
                        <select id="f-destination" name="destination_id" required>
                            <option value="">Choisir…</option>
                            <?php foreach ($destinationOptions as $group): ?>
                                <?php if ($group['group'] === null): ?>
                                    <?php foreach ($group['options'] as $opt): ?>
                                        <option value="<?= $opt['id'] ?>"><?= View::e($opt['label']) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <optgroup label="<?= View::e($group['group']) ?>">
                                        <?php foreach ($group['options'] as $opt): ?>
                                            <option value="<?= $opt['id'] ?>" title="<?= View::e($opt['path']) ?>"><?= View::e($opt['label']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$destinationOptions): ?>
                            <span class="field-hint">Aucune destination configurée : un administrateur doit en créer dans Administration › Destinations.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="f-description">Description</label>
                    <textarea id="f-description" name="description" placeholder="Référence, modèle, caractéristiques… ou « Voir PJ » si un devis est joint"></textarea>
                </div>

                <div class="field">
                    <label class="field-label" for="f-motif">Motif</label>
                    <input type="text" id="f-motif" name="motif" maxlength="190" placeholder="Remplacement, nouvelle acquisition…">
                </div>

                <div class="form-grid cols-3">
                    <div class="field">
                        <label class="field-label" for="f-qte">Quantité</label>
                        <input type="number" id="f-qte" name="quantite" value="1" min="1" step="1" required>
                    </div>
                    <div class="field">
                        <label class="field-label" for="f-pu">Prix unitaire</label>
                        <div class="input-affix">
                            <input type="number" id="f-pu" name="prix_unitaire" value="0" min="0" step="0.01" required>
                            <span class="affix">€</span>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="f-ttc">Prix TTC</label>
                        <div class="input-affix">
                            <input type="number" id="f-ttc" name="prix_ttc" value="0.00" min="0" step="0.01">
                            <span class="affix">€</span>
                        </div>
                        <span class="field-hint">Calculé automatiquement</span>
                    </div>
                </div>

                <div class="field">
                    <span class="field-label">Pièces jointes</span>
                    <?php require __DIR__ . '/../partials/dropzone.php'; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-dialog>Annuler</button>
                <button type="submit" class="btn btn-primary"><?= View::icon('plus', 16) ?> Ajouter l’article</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal modal-sm" id="dlg-upload" aria-labelledby="dlg-upload-title">
        <form class="modal-card" method="post" action="" enctype="multipart/form-data">
            <?= Csrf::field() ?>
            <div class="modal-head">
                <h2 id="dlg-upload-title">Joindre des fichiers</h2>
                <button type="button" class="btn-icon" data-close-dialog aria-label="Fermer"><?= View::icon('x') ?></button>
            </div>
            <div class="modal-body">
                <?php require __DIR__ . '/../partials/dropzone.php'; ?>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-dialog>Annuler</button>
                <button type="submit" class="btn btn-primary"><?= View::icon('upload', 16) ?> Téléverser</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($canDecide): ?>
    <dialog class="modal modal-sm" id="dlg-refus" aria-labelledby="dlg-refus-title">
        <form class="modal-card" method="post" action="/commandes/<?= $id ?>/decider">
            <?= Csrf::field() ?>
            <input type="hidden" name="decision" value="refuse">
            <div class="modal-head">
                <h2 id="dlg-refus-title">Refuser la commande</h2>
                <button type="button" class="btn-icon" data-close-dialog aria-label="Fermer"><?= View::icon('x') ?></button>
            </div>
            <div class="modal-body form-grid">
                <p>Le demandeur sera prévenu par e-mail avec le motif indiqué. Un refus clôt la commande.</p>
                <div class="field">
                    <label class="field-label" for="f-motif-refus">Motif du refus <span class="req">*</span></label>
                    <textarea id="f-motif-refus" name="commentaire" required placeholder="Expliquez pourquoi la commande est refusée…"></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-dialog>Annuler</button>
                <button type="submit" class="btn btn-danger"><?= View::icon('x-circle', 16) ?> Confirmer le refus</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
