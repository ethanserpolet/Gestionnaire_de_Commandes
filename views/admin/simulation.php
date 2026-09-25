<?php
use App\Support\Csrf;
use App\Support\Roles;
use App\Support\View;

/** @var array $preflight */
/** @var array $users */
/** @var array $responsables */
/** @var array $scenarios */
/** @var array|null $report */
/** @var array $form */
$pageTitle = 'Simulation';

$icons = ['ok' => 'check-circle', 'fail' => 'x-circle', 'warn' => 'alert', 'info' => 'circle'];
$preflightFails = count(array_filter($preflight, static fn($i) => $i['type'] === 'fail'));
$preflightWarns = count(array_filter($preflight, static fn($i) => $i['type'] === 'warn'));
?>
<div class="page-head">
    <div>
        <h1>Simulation du circuit</h1>
        <p class="page-sub">Rejoue une commande de bout en bout avec les vraies personnes configurées, sans rien enregistrer ni envoyer.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="/admin/simulation/mail-test"
              data-confirm="Un vrai e-mail de test va vous être envoyé via Microsoft Graph, pour vérifier la permission Mail.Send."
              data-confirm-title="Envoyer un e-mail de test ?" data-confirm-label="Envoyer">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-secondary"><?= View::icon('send', 16) ?> M’envoyer un e-mail de test</button>
        </form>
    </div>
</div>

<div class="layout-detail">
    <div class="stack">
        <section class="glass card">
            <div class="card-head">
                <div>
                    <h2>Lancer une simulation</h2>
                    <div class="sub">Tout se déroule dans une transaction annulée à la fin · e-mails interceptés</div>
                </div>
            </div>
            <form method="post" action="/admin/simulation" class="form-grid">
                <?= Csrf::field() ?>
                <div class="form-grid cols-2">
                    <div class="field">
                        <label class="field-label" for="sim-demandeur">Demandeur simulé</label>
                        <select id="sim-demandeur" name="demandeur_id" required>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"<?= (int) $u['id'] === (int) $form['demandeur_id'] ? ' selected' : '' ?>>
                                    <?= View::e($u['display_name']) ?><?= $u['service_nom'] ? ' — ' . View::e($u['service_nom']) : ' — sans service' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-hint">Son service détermine le responsable de l’étape 1.</span>
                    </div>
                    <div class="field">
                        <label class="field-label" for="sim-resp">Responsable désigné (si plusieurs)</label>
                        <select id="sim-resp" name="responsable_id">
                            <option value="0">Automatique (le premier du service)</option>
                            <?php foreach ($responsables as $r): ?>
                                <option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === (int) $form['responsable_id'] ? ' selected' : '' ?>><?= View::e($r['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <span class="field-label">Scénario</span>
                    <div class="toggle-group">
                        <?php foreach ($scenarios as $key => $label): ?>
                            <label class="toggle-chip">
                                <input type="radio" name="scenario" value="<?= View::e($key) ?>"<?= $form['scenario'] === $key ? ' checked' : '' ?>>
                                <span><?= View::icon('check', 14) ?><?= View::e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary btn-lg"><?= View::icon('history') ?> Lancer la simulation</button>
                </div>
            </form>
        </section>

        <?php if ($report): ?>
            <section class="glass card sim-summary <?= $report['failed'] ? 'is-fail' : ($report['warnings'] ? 'is-warn' : 'is-ok') ?>">
                <span class="notice-icon <?= $report['failed'] ? 'tone-danger' : ($report['warnings'] ? 'tone-warning' : 'tone-success') ?>">
                    <?= View::icon($report['failed'] ? 'x-circle' : ($report['warnings'] ? 'alert' : 'check-circle'), 22) ?>
                </span>
                <div>
                    <h2><?= $report['failed']
                        ? $report['failed'] . ' vérification' . ($report['failed'] > 1 ? 's' : '') . ' en échec'
                        : ($report['warnings'] ? 'Circuit fonctionnel, avec des points d’attention' : 'Tout le circuit fonctionne') ?></h2>
                    <p><?= View::e($report['scenario']) ?> · <?= $report['passed'] ?> réussie<?= $report['passed'] > 1 ? 's' : '' ?>
                        · <?= $report['failed'] ?> échec<?= $report['failed'] > 1 ? 's' : '' ?>
                        · <?= $report['warnings'] ?> avertissement<?= $report['warnings'] > 1 ? 's' : '' ?>
                        · <?= $report['duration'] ?> ms</p>
                </div>
            </section>

            <ol class="sim-steps">
                <?php foreach ($report['steps'] as $n => $step):
                    $types = array_column($step['lines'], 'type');
                    $state = in_array('fail', $types, true) ? 'fail' : (in_array('warn', $types, true) ? 'warn' : 'ok'); ?>
                    <li class="glass sim-step is-<?= $state ?>">
                        <div class="sim-step-head">
                            <span class="sim-step-num"><?= $n + 1 ?></span>
                            <div>
                                <h3><?= View::e($step['title']) ?></h3>
                                <?php if ($step['actor']): ?>
                                    <div class="sim-actor"><?= View::icon('users', 13) ?> Agit en tant que <strong><?= View::e($step['actor']) ?></strong></div>
                                <?php endif; ?>
                            </div>
                            <?php if ($step['mails']): ?>
                                <span class="pill primary"><?= View::icon('send', 12) ?> <?= count($step['mails']) ?> e-mail<?= count($step['mails']) > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                        </div>

                        <ul class="sim-lines">
                            <?php foreach ($step['lines'] as $line): ?>
                                <li class="sim-line is-<?= $line['type'] ?>"><?= View::icon($icons[$line['type']], 15) ?><span><?= View::e($line['text']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>

                        <?php foreach ($step['mails'] as $mail): ?>
                            <details class="sim-mail">
                                <summary>
                                    <?= View::icon('send', 14) ?>
                                    <strong><?= View::e($mail['subject']) ?></strong>
                                    <span class="muted">→ <?= View::e(implode(', ', $mail['to'])) ?></span>
                                </summary>
                                <iframe class="sim-mail-frame" sandbox="" loading="lazy" title="Aperçu de l’e-mail" srcdoc="<?= View::e($mail['html']) ?>"></iframe>
                            </details>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>

    <aside class="stack">
        <section class="glass card">
            <div class="card-head">
                <div>
                    <h2>Contrôle de la configuration</h2>
                    <div class="sub">
                        <?= $preflightFails ? $preflightFails . ' problème' . ($preflightFails > 1 ? 's' : '') : 'Aucun problème bloquant' ?>
                        <?= $preflightWarns ? ' · ' . $preflightWarns . ' avertissement' . ($preflightWarns > 1 ? 's' : '') : '' ?>
                    </div>
                </div>
            </div>
            <ul class="sim-lines">
                <?php foreach ($preflight as $item): ?>
                    <li class="sim-line is-<?= $item['type'] ?>"><?= View::icon($icons[$item['type']], 15) ?><span><?= View::e($item['text']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <div class="notice">
            <span class="notice-icon tone-info"><?= View::icon('shield') ?></span>
            <div>
                <strong>Sans risque</strong>
                <p>La simulation agit au nom des vraies personnes (responsable, comptabilité, chef d’établissement, exécuteur) mais annule tout à la fin : aucune commande n’apparaît chez eux et aucun e-mail ne part. Les numéros de commande utilisés sont simplement « consommés ».</p>
            </div>
        </div>
    </aside>
</div>
