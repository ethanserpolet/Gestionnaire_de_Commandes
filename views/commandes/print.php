<?php
use App\Support\View;

/** @var array $commande */
/** @var array $user */
$id = (int) $commande['id'];
$numero = $commande['numero_commande'] ?: 'Brouillon #' . $id;
$statut = $commande['statut'];

$total = 0.0;
$groupes = [];
foreach ($commande['lignes'] as $ligne) {
    $total += (float) $ligne['prix_ttc'];
    $groupes[(string) $ligne['fournisseur']][] = $ligne;
}
uksort($groupes, 'strnatcasecmp');
$nbArticles = count($commande['lignes']);
$qteTotale = array_sum(array_map(static fn($l) => (int) $l['quantite'], $commande['lignes']));
$validations = $commande['validations'];

$isImage = static fn(array $f): bool => str_starts_with((string) $f['mime_type'], 'image/');

// Annexes numérotées dans l'ordre d'affichage du tableau (A1, A2…).
$annexes = [];
$annexRefs = [];
$numero_ligne = 0;
foreach ($groupes as $fournisseur => $lignes) {
    foreach ($lignes as $ligne) {
        $numero_ligne++;
        foreach ($ligne['fichiers'] as $f) {
            if ($isImage($f)) {
                $ref = 'A' . (count($annexes) + 1);
                $annexRefs[(int) $f['id']] = $ref;
                $annexes[] = [
                    'ref' => $ref,
                    'ligne' => $numero_ligne,
                    'fournisseur' => (string) $fournisseur,
                    'fichier' => $f,
                ];
            }
        }
    }
}

$tones = [
    'en_attente' => 'warning', 'en_validation' => 'info', 'valide' => 'success',
    'finalise' => 'teal', 'refuse' => 'danger', 'brouillon' => 'neutral',
];
$tone = $tones[$statut] ?? 'neutral';
$check = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
$cross = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
$clock = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($numero) ?> - Demande d'achat - Lycée St Marc</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <style>
        @page {
            size: A4;
            margin: 13mm 12mm 15mm;
            @bottom-left { content: "<?= View::e($numero) ?> · Demande d’achat"; font: 7.5pt Inter, sans-serif; color: #8591a5; }
            @bottom-right { content: "Page " counter(page) " / " counter(pages); font: 7.5pt Inter, sans-serif; color: #8591a5; }
        }
        :root {
            --ink: #0f172a; --ink-2: #475569; --ink-3: #8591a5;
            --line: #e3e7ef; --soft: #f5f7fb;
            --primary: #6366f1; --primary-2: #8b5cf6;
        }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 9.5pt; line-height: 1.45; color: var(--ink); background: #e8ebf4; }
        h1, h2, p { margin: 0; }

        /* Barre d'outils (écran uniquement) */
        .toolbar {
            position: sticky; top: 0; z-index: 5;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, .88);
            -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
        }
        .tb-btn {
            display: inline-flex; align-items: center; gap: 8px;
            height: 38px; padding: 0 16px;
            border-radius: 10px; border: 1px solid var(--line);
            background: #fff; color: var(--ink);
            font: 600 13.5px Inter, sans-serif; text-decoration: none; cursor: pointer;
        }
        .tb-btn.primary { border: none; color: #fff; background: linear-gradient(135deg, var(--primary), var(--primary-2)); box-shadow: 0 8px 20px -8px var(--primary); }
        .tb-hint { font-size: 12.5px; color: var(--ink-2); text-align: center; }

        /* Feuille A4 */
        .sheet { width: 210mm; min-height: 297mm; margin: 24px auto 48px; padding: 13mm 12mm; background: #fff; border-radius: 4px; box-shadow: 0 24px 60px -24px rgba(15, 23, 42, .4); }

        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding-bottom: 14px; border-bottom: 2px solid var(--ink); }
        .org { display: flex; gap: 12px; align-items: center; }
        .org-logo { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, var(--primary), var(--primary-2)); flex-shrink: 0; }
        .org-name { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--ink-2); }
        .doc-title { font-size: 16pt; font-weight: 800; letter-spacing: -.02em; line-height: 1.15; margin-top: 2px; }
        .ref { text-align: right; }
        .k { font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-3); margin-bottom: 2px; }
        .ref-num { font-size: 15pt; font-weight: 800; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }

        .pill { display: inline-flex; align-items: center; gap: 5px; margin-top: 5px; padding: 2px 9px; border-radius: 999px; font-size: 7.5pt; font-weight: 700; }
        .pill::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .t-warning { background: #fef3c7; color: #b45309; }
        .t-info { background: #dbeafe; color: #1d4ed8; }
        .t-success { background: #dcfce7; color: #15803d; }
        .t-teal { background: #ccfbf1; color: #0f766e; }
        .t-danger { background: #fee2e2; color: #b91c1c; }
        .t-neutral { background: #eef1f5; color: #475569; }

        .info { display: grid; grid-template-columns: 1.5fr 1.2fr 1fr 1fr 1fr; margin: 16px 0 20px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
        .info > div { padding: 9px 12px; border-right: 1px solid var(--line); }
        .info > div:last-child { border-right: none; }
        .v { font-weight: 600; }
        .v small { display: block; font-size: 8pt; font-weight: 400; color: var(--ink-2); }

        h2 { display: flex; align-items: center; gap: 10px; margin: 0 0 8px; font-size: 8.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-2); }
        h2::after { content: ""; flex: 1; height: 1px; background: var(--line); }
        section { margin-bottom: 20px; }

        table { width: 100%; border-collapse: collapse; }
        .items th { padding: 6px 8px; border-bottom: 1.5px solid var(--ink); font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-3); text-align: left; }
        .items td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .items tr { break-inside: avoid; page-break-inside: avoid; }
        .supplier-row td { padding: 6px 8px; background: var(--soft); font-weight: 700; }
        .supplier-row .count { font-weight: 500; color: var(--ink-3); margin-left: 6px; }
        .idx { width: 24px; color: var(--ink-3); font-variant-numeric: tabular-nums; }
        .num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .desc { font-weight: 500; }
        .muted { color: var(--ink-3); }
        .pj { margin-top: 3px; font-size: 7.5pt; color: var(--ink-2); }
        .grand td { padding-top: 9px; border-top: 1.5px solid var(--ink); border-bottom: none; font-size: 10.5pt; font-weight: 800; }

        .summary { display: grid; grid-template-columns: 1fr 62mm; gap: 16px; align-items: start; break-inside: avoid; page-break-inside: avoid; }
        .recap td { padding: 5px 8px; border-bottom: 1px solid var(--line); }
        .recap tr:last-child td { border-bottom: none; }
        .total-box { padding: 14px 16px; border-radius: 12px; color: #fff; background: linear-gradient(135deg, var(--primary), var(--primary-2)); }
        .total-box .k { color: rgba(255, 255, 255, .8); }
        .total-box .amount { font-size: 18pt; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; line-height: 1.2; }
        .total-box .meta { margin-top: 2px; font-size: 8pt; opacity: .88; }

        .signs { display: grid; grid-template-columns: repeat(auto-fit, minmax(42mm, 1fr)); gap: 10px; break-inside: avoid; page-break-inside: avoid; }
        .sign { display: flex; flex-direction: column; gap: 2px; min-height: 26mm; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; break-inside: avoid; }
        .sign .role { font-size: 7pt; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-3); }
        .sign .name { font-weight: 700; }
        .sign .comment { font-size: 7.5pt; font-style: italic; color: var(--ink-2); }
        .sign .state { display: flex; align-items: center; gap: 5px; margin-top: auto; padding-top: 6px; font-size: 7.5pt; font-weight: 700; }
        .sign.ok { border-color: #bde6cb; background: #f4fbf6; }
        .sign.ok .state { color: #15803d; }
        .sign.ko { border-color: #f6c7c7; background: #fdf5f5; }
        .sign.ko .state { color: #b91c1c; }
        .sign.wait .state { color: #b45309; }
        .sign.none .state { color: var(--ink-3); }

        .refus { margin-bottom: 20px; padding: 10px 14px; border-left: 3px solid #dc2626; border-radius: 0 10px 10px 0; background: #fdf5f5; break-inside: avoid; }
        .refus strong { color: #b91c1c; }

        .thumbs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .thumb { margin: 0; padding: 3px; border: 1px solid var(--line); border-radius: 6px; background: #fff; }
        .thumb img { display: block; height: 22mm; max-width: 48mm; object-fit: contain; border-radius: 3px; }
        .thumb figcaption { margin-top: 2px; font-size: 6.5pt; font-weight: 600; color: var(--ink-3); text-align: center; }

        .annexes { margin-top: 28px; break-before: page; page-break-before: always; }
        .annex { margin: 0 0 14px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; break-inside: avoid; page-break-inside: avoid; }
        .annex-head { display: flex; justify-content: space-between; gap: 12px; padding: 7px 12px; background: var(--soft); border-bottom: 1px solid var(--line); font-size: 8pt; }
        .annex img { display: block; max-width: 100%; max-height: 215mm; margin: 0 auto; padding: 10px; object-fit: contain; }

        .doc-foot { display: flex; justify-content: space-between; gap: 12px; padding-top: 10px; border-top: 1px solid var(--line); font-size: 7.5pt; color: var(--ink-3); }

        @media screen and (max-width: 860px) {
            .sheet { width: auto; min-height: 0; margin: 12px; padding: 20px 16px; }
            .tb-hint { display: none; }
            .info { grid-template-columns: 1fr 1fr; }
            .info > div { border-right: none; border-bottom: 1px solid var(--line); }
            .summary { grid-template-columns: 1fr; }
            .table-scroll { overflow-x: auto; }
            .doc-head { flex-direction: column; }
            .ref { text-align: left; }
        }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; border-radius: 0; box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <a href="/commandes/<?= $id ?>" class="tb-btn"><?= View::icon('arrow-left', 16) ?> Retour</a>
    <div class="tb-hint">Dans la fenêtre d’impression, choisissez <strong>« Enregistrer au format PDF »</strong>.</div>
    <button type="button" class="tb-btn primary" onclick="window.print()"><?= View::icon('download', 16) ?> Télécharger le PDF</button>
</div>

<main class="sheet">
    <header class="doc-head">
        <div class="org">
            <div class="org-logo"><?= View::icon('receipt', 22) ?></div>
            <div>
                <div class="org-name">Lycée St Marc</div>
                <h1 class="doc-title">Demande d’achat<br>préalable à une commande</h1>
            </div>
        </div>
        <div class="ref">
            <div class="k">N° de commande</div>
            <div class="ref-num"><?= View::e($numero) ?></div>
            <span class="pill t-<?= $tone ?>"><?= View::e(View::statutLabel($statut)) ?></span>
        </div>
    </header>

    <div class="info">
        <div>
            <div class="k">Demandeur</div>
            <div class="v"><?= View::e($commande['demandeur_nom']) ?><small><?= View::e($commande['demandeur_email']) ?></small></div>
        </div>
        <div>
            <div class="k">Service</div>
            <div class="v"><?= View::e($commande['service_nom'] ?? '—') ?></div>
        </div>
        <div>
            <div class="k">Date de la demande</div>
            <div class="v"><?= View::date($commande['date_demande']) ?></div>
        </div>
        <div>
            <div class="k">Envoyée le</div>
            <div class="v"><?= View::date($commande['submitted_at']) ?><small><?= $commande['submitted_at'] ? date('H:i', strtotime($commande['submitted_at'])) : '' ?></small></div>
        </div>
        <div>
            <div class="k">Articles</div>
            <div class="v"><?= $nbArticles ?> ligne<?= $nbArticles > 1 ? 's' : '' ?><small><?= $qteTotale ?> unité<?= $qteTotale > 1 ? 's' : '' ?> · <?= count($groupes) ?> fournisseur<?= count($groupes) > 1 ? 's' : '' ?></small></div>
        </div>
    </div>

    <?php if ($statut === 'refuse'): ?>
        <div class="refus">
            <strong>Commande refusée.</strong>
            <?= $commande['motif_refus'] ? nl2br(View::e($commande['motif_refus'])) : 'Aucun motif précisé.' ?>
        </div>
    <?php endif; ?>

    <section>
        <h2>Détail de la demande</h2>
        <div class="table-scroll">
            <table class="items">
                <thead>
                <tr>
                    <th class="idx">#</th>
                    <th>Désignation</th>
                    <th>Destination</th>
                    <th>Motif</th>
                    <th class="num">Qté</th>
                    <th class="num">P.U.</th>
                    <th class="num">Total TTC</th>
                </tr>
                </thead>
                <tbody>
                <?php $n = 0; foreach ($groupes as $fournisseur => $lignes):
                    $sousTotal = array_sum(array_map(static fn($l) => (float) $l['prix_ttc'], $lignes)); ?>
                    <tr class="supplier-row">
                        <td colspan="6"><?= View::e((string) $fournisseur) ?><span class="count"><?= count($lignes) ?> article<?= count($lignes) > 1 ? 's' : '' ?></span></td>
                        <td class="num"><?= View::money($sousTotal) ?></td>
                    </tr>
                    <?php foreach ($lignes as $ligne): $n++; ?>
                        <tr>
                            <td class="idx"><?= $n ?></td>
                            <td>
                                <div class="desc"><?= $ligne['description'] ? nl2br(View::e($ligne['description'])) : '<span class="muted">Voir pièce jointe</span>' ?></div>
                                <?php
                                $images = array_values(array_filter($ligne['fichiers'], $isImage));
                                $autres = array_values(array_filter($ligne['fichiers'], static fn($f) => !$isImage($f)));
                                ?>
                                <?php if ($images): ?>
                                    <div class="thumbs">
                                        <?php foreach ($images as $img): ?>
                                            <figure class="thumb">
                                                <img src="/fichiers/<?= (int) $img['id'] ?>" alt="<?= View::e($img['nom_original']) ?>">
                                                <figcaption>Annexe <?= $annexRefs[(int) $img['id']] ?></figcaption>
                                            </figure>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($autres): ?>
                                    <div class="pj">PJ : <?= View::e(implode(', ', array_column($autres, 'nom_original'))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= View::e($ligne['destination']) ?></td>
                            <td><?= $ligne['motif'] ? View::e($ligne['motif']) : '<span class="muted">—</span>' ?></td>
                            <td class="num"><?= (int) $ligne['quantite'] ?></td>
                            <td class="num"><?= View::money($ligne['prix_unitaire']) ?></td>
                            <td class="num"><?= View::money($ligne['prix_ttc']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr class="grand">
                    <td colspan="6">Total TTC</td>
                    <td class="num"><?= View::money($total) ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="summary">
        <div>
            <h2>Récapitulatif par fournisseur</h2>
            <table class="recap">
                <?php foreach ($groupes as $fournisseur => $lignes): ?>
                    <tr>
                        <td><strong><?= View::e((string) $fournisseur) ?></strong></td>
                        <td class="muted"><?= count($lignes) ?> article<?= count($lignes) > 1 ? 's' : '' ?></td>
                        <td class="num"><?= View::money(array_sum(array_map(static fn($l) => (float) $l['prix_ttc'], $lignes))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="total-box">
            <div class="k">Montant total TTC</div>
            <div class="amount"><?= View::money($total) ?></div>
            <div class="meta"><?= $nbArticles ?> ligne<?= $nbArticles > 1 ? 's' : '' ?> · <?= count($groupes) ?> fournisseur<?= count($groupes) > 1 ? 's' : '' ?></div>
        </div>
    </section>

    <section>
        <h2>Circuit de validation</h2>
        <div class="signs">
            <div class="sign ok">
                <div class="role">Demandeur</div>
                <div class="name"><?= View::e($commande['demandeur_nom']) ?></div>
                <div class="state"><?= $check ?> Signé électroniquement le <?= View::date($commande['submitted_at'], true) ?></div>
            </div>

            <?php foreach ($validations as $v):
                $cls = ['valide' => 'ok', 'refuse' => 'ko'][$v['decision']] ?? 'wait';
                $who = $v['validateur_nom'] ?? $v['assigned_nom']; ?>
                <div class="sign <?= $cls ?>">
                    <div class="role"><?= View::e(\App\Support\Roles::label($v['role'])) ?></div>
                    <div class="name<?= $who ? '' : ' muted' ?>"><?= View::e($who ?: '—') ?></div>
                    <?php if ($v['commentaire']): ?><div class="comment">« <?= View::e($v['commentaire']) ?> »</div><?php endif; ?>
                    <div class="state">
                        <?php if ($v['decision'] === 'valide'): ?><?= $check ?> Validé le <?= View::date($v['decided_at'], true) ?>
                        <?php elseif ($v['decision'] === 'refuse'): ?><?= $cross ?> Refusé le <?= View::date($v['decided_at'], true) ?>
                        <?php else: ?><?= $clock ?> En attente de validation<?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($commande['executions']): ?>
                <?php foreach ($commande['executions'] as $e):
                    $passee = $e['done_at'] !== null;
                    $qui = $passee ? $e['done_nom'] : ($e['assigned_nom'] ?: \App\Support\Roles::label($e['role'])); ?>
                    <div class="sign <?= $passee ? 'ok' : 'wait' ?>">
                        <div class="role">Exécution · <?= View::e($e['fournisseur']) ?></div>
                        <div class="name"><?= View::e($qui) ?></div>
                        <div class="state">
                            <?php if ($passee): ?><?= $check ?> Passée le <?= View::date($e['done_at'], true) ?>
                            <?php else: ?><?= $clock ?> À passer auprès du fournisseur<?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php elseif ($statut === 'finalise'): ?>
                <div class="sign ok">
                    <div class="role">Exécution</div>
                    <div class="name"><?= View::e($commande['finalise_par_nom'] ?? '—') ?></div>
                    <div class="state"><?= $check ?> Finalisée le <?= View::date($commande['finalized_at'], true) ?></div>
                </div>
            <?php elseif ($statut !== 'refuse'): ?>
                <div class="sign <?= $statut === 'valide' ? 'wait' : 'none' ?>">
                    <div class="role">Exécution</div>
                    <div class="name muted">—</div>
                    <div class="state"><?= $clock ?> <?= $statut === 'valide' ? 'À passer auprès des fournisseurs' : 'Après validation' ?></div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <footer class="doc-foot">
        <span>Document généré le <?= View::date(date('Y-m-d H:i:s'), true) ?> par <?= View::e($user['display_name']) ?></span>
        <span>Commandes · Lycée St Marc</span>
    </footer>

    <?php if ($annexes): ?>
        <section class="annexes">
            <h2>Annexes · images jointes</h2>
            <?php foreach ($annexes as $a): ?>
                <figure class="annex">
                    <figcaption class="annex-head">
                        <span><strong><?= $a['ref'] ?></strong> · Article n° <?= $a['ligne'] ?> — <?= View::e($a['fournisseur']) ?></span>
                        <span class="muted"><?= View::e($a['fichier']['nom_original']) ?></span>
                    </figcaption>
                    <img src="/fichiers/<?= (int) $a['fichier']['id'] ?>" alt="<?= View::e($a['fichier']['nom_original']) ?>">
                </figure>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<script>
    if (new URLSearchParams(location.search).has('print')) {
        const imagesReady = Promise.all(Array.from(document.images).map((img) => (
            img.complete ? Promise.resolve() : new Promise((resolve) => { img.onload = img.onerror = resolve; })
        )));
        const fontsReady = document.fonts ? document.fonts.ready : Promise.resolve();
        Promise.all([fontsReady, imagesReady]).then(() => setTimeout(() => window.print(), 300));
    }
</script>
</body>
</html>
