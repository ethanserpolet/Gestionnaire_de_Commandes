<?php
use App\Support\Csrf;
use App\Support\View;

/** @var array $user */
/** @var array $services */
/** @var bool $firstTime */
$pageTitle = 'Mon service';
?>
<div class="onboarding">
    <section class="glass card onboarding-card">
        <span class="empty-icon"><?= View::icon('building', 26) ?></span>
        <h1><?= $firstTime ? 'Dans quel service travaillez-vous ?' : 'Mon service' ?></h1>
        <p class="page-sub">
            <?= $firstTime
                ? 'Une dernière étape : indiquez votre service. Son responsable validera en premier vos demandes d’achat.'
                : 'Votre service détermine le responsable qui valide en premier vos demandes d’achat.' ?>
        </p>

        <?php if (!$services): ?>
            <div class="empty empty-sm">
                <p>Aucun service n’a encore été créé. Contactez un administrateur.</p>
            </div>
        <?php else: ?>
            <form method="post" action="/profil/service" class="form-grid">
                <?= Csrf::field() ?>
                <div class="service-choices" role="radiogroup" aria-label="Service">
                    <?php foreach ($services as $s): $checked = (int) $user['service_id'] === (int) $s['id']; ?>
                        <label class="service-choice">
                            <input type="radio" name="service_id" value="<?= (int) $s['id'] ?>" required<?= $checked ? ' checked' : '' ?>>
                            <span class="service-choice-body">
                                <strong><?= View::e($s['name']) ?></strong>
                                <span>
                                    <?php if ($s['responsables_noms']): ?>
                                        Responsable<?= count($s['responsable_ids']) > 1 ? 's' : '' ?> : <?= View::e($s['responsables_noms']) ?>
                                    <?php else: ?>
                                        Pas de responsable désigné
                                    <?php endif; ?>
                                </span>
                            </span>
                            <span class="service-choice-check"><?= View::icon('check', 14) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block"><?= View::icon('check') ?> <?= $firstTime ? 'Continuer' : 'Enregistrer' ?></button>
            </form>
        <?php endif; ?>
        <p class="field-hint" style="text-align:center">Votre service n’apparaît pas ? Contactez le service informatique.</p>
    </section>
</div>
