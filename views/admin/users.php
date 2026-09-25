<?php
use App\Support\Csrf;
use App\Support\Roles;
use App\Support\View;

/** @var array $users */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $perPage */
/** @var array $filters */
/** @var array $stats */
/** @var array $roles */
/** @var array $services */
/** @var int $currentAdminId */
$pageTitle = 'Utilisateurs';

$query = static function (array $override = []) use ($filters): string {
    $params = array_filter(array_merge($filters, ['page' => null], $override), static fn($v) => $v !== '' && $v !== null);
    unset($params['statut'], $params['tri']);
    if (($override['statut'] ?? $filters['statut']) !== 'tous') {
        $params['statut'] = $override['statut'] ?? $filters['statut'];
    }
    if (($override['tri'] ?? $filters['tri']) !== 'nom') {
        $params['tri'] = $override['tri'] ?? $filters['tri'];
    }
    return $params ? '?' . http_build_query($params) : '';
};
$currentQs = $query(['page' => $page > 1 ? $page : null]);
$filtered = $filters['q'] !== '' || $filters['role'] !== '' || $filters['service'] !== '' || $filters['statut'] !== 'tous';
$from = $total ? ($page - 1) * $perPage + 1 : 0;
$to = min($total, $page * $perPage);
?>
<div class="page-head">
    <div>
        <h1>Utilisateurs</h1>
        <p class="page-sub"><?= $stats['total'] ?> compte<?= $stats['total'] > 1 ? 's' : '' ?> · <?= $stats['actifs'] ?> actif<?= $stats['actifs'] > 1 ? 's' : '' ?> · créés automatiquement à la première connexion (rôle Demandeur par défaut)</p>
    </div>
</div>

<div class="stack">
    <div class="quick-filters" role="group" aria-label="Filtres rapides">
        <a class="qf<?= !$filtered ? ' active' : '' ?>" href="/admin/utilisateurs"><strong><?= $stats['total'] ?></strong><span>Tous</span></a>
        <?php foreach ($roles as $r): ?>
            <a class="qf<?= $filters['role'] === $r['code'] ? ' active' : '' ?>" href="/admin/utilisateurs<?= $query(['role' => $r['code'], 'page' => null]) ?>">
                <strong><?= (int) ($stats['roles'][$r['code']] ?? 0) ?></strong><span><?= View::e($r['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <a class="qf<?= $filters['role'] === '_admin' ? ' active' : '' ?>" href="/admin/utilisateurs<?= $query(['role' => '_admin', 'page' => null]) ?>"><strong><?= $stats['admins'] ?></strong><span>Admins</span></a>
        <?php if ($stats['sans_role']): ?>
            <a class="qf qf-warn<?= $filters['role'] === '_none' ? ' active' : '' ?>" href="/admin/utilisateurs<?= $query(['role' => '_none', 'statut' => 'actifs', 'page' => null]) ?>"><strong><?= $stats['sans_role'] ?></strong><span>Sans rôle</span></a>
        <?php endif; ?>
        <?php if ($stats['sans_service'] && $services): ?>
            <a class="qf qf-warn<?= $filters['service'] === '_none' ? ' active' : '' ?>" href="/admin/utilisateurs<?= $query(['service' => '_none', 'statut' => 'actifs', 'page' => null]) ?>"><strong><?= $stats['sans_service'] ?></strong><span>Sans service</span></a>
        <?php endif; ?>
        <?php if ($stats['inactifs']): ?>
            <a class="qf<?= $filters['statut'] === 'inactifs' ? ' active' : '' ?>" href="/admin/utilisateurs<?= $query(['statut' => 'inactifs', 'page' => null]) ?>"><strong><?= $stats['inactifs'] ?></strong><span>Désactivés</span></a>
        <?php endif; ?>
    </div>

    <section class="glass card card-flush">
        <form class="toolbar toolbar-stack" method="get" action="/admin/utilisateurs" data-autosubmit>
            <div class="toolbar-row">
                <label class="search">
                    <?= View::icon('search', 16) ?>
                    <input type="search" name="q" value="<?= View::e($filters['q']) ?>" placeholder="Nom ou adresse e-mail…" aria-label="Rechercher un utilisateur">
                </label>
                <select name="role" class="toolbar-select" aria-label="Filtrer par rôle">
                    <option value="">Tous les rôles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= View::e($r['code']) ?>"<?= $filters['role'] === $r['code'] ? ' selected' : '' ?>><?= View::e($r['label']) ?></option>
                    <?php endforeach; ?>
                    <option value="_admin"<?= $filters['role'] === '_admin' ? ' selected' : '' ?>>Administrateurs</option>
                    <option value="_none"<?= $filters['role'] === '_none' ? ' selected' : '' ?>>Sans aucun rôle</option>
                </select>
                <select name="service" class="toolbar-select" aria-label="Filtrer par service">
                    <option value="">Tous les services</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"<?= $filters['service'] === (string) $s['id'] ? ' selected' : '' ?>><?= View::e($s['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="_none"<?= $filters['service'] === '_none' ? ' selected' : '' ?>>Sans service</option>
                </select>
                <select name="statut" class="toolbar-select" aria-label="Filtrer par statut">
                    <option value="tous"<?= $filters['statut'] === 'tous' ? ' selected' : '' ?>>Actifs et désactivés</option>
                    <option value="actifs"<?= $filters['statut'] === 'actifs' ? ' selected' : '' ?>>Actifs seulement</option>
                    <option value="inactifs"<?= $filters['statut'] === 'inactifs' ? ' selected' : '' ?>>Désactivés seulement</option>
                </select>
                <select name="tri" class="toolbar-select" aria-label="Trier">
                    <option value="nom"<?= $filters['tri'] === 'nom' ? ' selected' : '' ?>>Tri : nom (A → Z)</option>
                    <option value="service"<?= $filters['tri'] === 'service' ? ' selected' : '' ?>>Tri : service</option>
                    <option value="connexion"<?= $filters['tri'] === 'connexion' ? ' selected' : '' ?>>Tri : dernière connexion</option>
                    <option value="recent"<?= $filters['tri'] === 'recent' ? ' selected' : '' ?>>Tri : inscription récente</option>
                </select>
                <button type="submit" class="btn btn-secondary"><?= View::icon('search', 16) ?> Filtrer</button>
                <?php if ($filtered): ?><a class="btn btn-ghost" href="/admin/utilisateurs">Effacer</a><?php endif; ?>
            </div>
        </form>

        <form id="bulk-form" method="post" action="/admin/utilisateurs/lot" class="bulk-bar" hidden
              data-confirm="L’action sera appliquée à tous les comptes cochés." data-confirm-title="Appliquer à la sélection ?" data-confirm-label="Appliquer">
            <?= Csrf::field() ?>
            <input type="hidden" name="return" value="<?= View::e($currentQs) ?>">
            <strong class="bulk-count" data-bulk-count>0 sélectionné</strong>
            <select name="action" data-bulk-action aria-label="Action groupée" required>
                <option value="">Action groupée…</option>
                <option value="add_role">Ajouter un rôle</option>
                <option value="remove_role">Retirer un rôle</option>
                <?php if ($services): ?><option value="set_service">Changer de service</option><?php endif; ?>
                <option value="activate">Réactiver</option>
                <option value="deactivate">Désactiver</option>
            </select>
            <select name="role" data-bulk-for="add_role remove_role" aria-label="Rôle" hidden>
                <?php foreach ($roles as $r): ?><option value="<?= View::e($r['code']) ?>"><?= View::e($r['label']) ?></option><?php endforeach; ?>
            </select>
            <select name="service_id" data-bulk-for="set_service" aria-label="Service" hidden>
                <option value="0">— Aucun service —</option>
                <?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"><?= View::e($s['name']) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
            <button type="button" class="btn btn-ghost btn-sm" data-bulk-clear>Annuler</button>
        </form>

        <?php if (!$users): ?>
            <div class="empty">
                <span class="empty-icon"><?= View::icon('users', 26) ?></span>
                <h3><?= $filtered ? 'Aucun compte ne correspond' : 'Aucun utilisateur' ?></h3>
                <p><?= $filtered ? 'Modifiez la recherche ou les filtres.' : 'Les comptes apparaissent ici dès la première connexion d’un membre du personnel.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-stack users-table" data-bulk-table>
                    <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" data-bulk-all aria-label="Tout sélectionner sur cette page"></th>
                        <th>Utilisateur</th>
                        <th>Service</th>
                        <th>Rôles</th>
                        <th>Dernière connexion</th>
                        <th>Statut</th>
                        <th aria-hidden="true"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $uid = (int) $u['id'];
                        $actif = (bool) $u['is_active'];
                        $payload = [
                            'id' => $uid,
                            'name' => $u['display_name'],
                            'email' => $u['email'],
                            'roles' => $u['roles'],
                            'service' => (int) $u['service_id'],
                            'admin' => (bool) $u['is_admin'],
                            'active' => $actif,
                            'self' => $uid === $currentAdminId,
                        ]; ?>
                        <tr class="<?= $actif ? '' : 'is-inactive' ?>">
                            <td class="col-check" data-label="Sélection">
                                <?php if ($uid !== $currentAdminId): ?>
                                    <input type="checkbox" value="<?= $uid ?>" data-bulk-item aria-label="Sélectionner <?= View::e($u['display_name']) ?>">
                                <?php endif; ?>
                            </td>
                            <td data-label="Utilisateur">
                                <div class="cell-user">
                                    <?= View::avatar($u['display_name'], 'sm') ?>
                                    <div class="cell-title">
                                        <strong><?= View::e($u['display_name']) ?><?= $uid === $currentAdminId ? ' <span class="muted">(vous)</span>' : '' ?></strong>
                                        <span class="cell-sub"><?= View::e($u['email']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Service"><?= $u['service_nom'] ? View::e($u['service_nom']) : '<span class="muted">—</span>' ?></td>
                            <td data-label="Rôles">
                                <div class="role-pills">
                                    <?php if ($u['is_admin']): ?><span class="pill primary"><?= View::icon('shield', 11) ?> Admin</span><?php endif; ?>
                                    <?php foreach ($u['roles'] as $code): ?><span class="pill"><?= View::e(Roles::label($code)) ?></span><?php endforeach; ?>
                                    <?php if (!$u['roles'] && !$u['is_admin']): ?><span class="pill danger">Aucun rôle</span><?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Connexion" class="nowrap"><?= $u['last_login_at'] ? View::date($u['last_login_at'], true) : '<span class="muted">Jamais</span>' ?></td>
                            <td data-label="Statut"><?= $actif ? '<span class="status status-valide">Actif</span>' : '<span class="status status-refuse">Désactivé</span>' ?></td>
                            <td class="col-action">
                                <button type="button" class="btn btn-secondary btn-sm" data-edit-user="<?= View::e(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>">
                                    <?= View::icon('edit', 14) ?> Modifier
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pager">
                <span class="muted"><?= $from ?>–<?= $to ?> sur <?= $total ?> compte<?= $total > 1 ? 's' : '' ?></span>
                <?php if ($pages > 1): ?>
                    <div class="pager-links">
                        <?php if ($page > 1): ?>
                            <a class="btn btn-secondary btn-sm" href="/admin/utilisateurs<?= $query(['page' => $page - 1]) ?>">← Précédent</a>
                        <?php endif; ?>
                        <span class="pager-pos">Page <?= $page ?> / <?= $pages ?></span>
                        <?php if ($page < $pages): ?>
                            <a class="btn btn-secondary btn-sm" href="/admin/utilisateurs<?= $query(['page' => $page + 1]) ?>">Suivant →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<dialog class="modal" id="dlg-user" aria-labelledby="dlg-user-title">
    <form class="modal-card" method="post" action="" data-user-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="return" value="<?= View::e($currentQs) ?>">
        <input type="hidden" name="has_active" value="1">
        <div class="modal-head">
            <div class="cell-user">
                <span class="avatar" data-user-avatar></span>
                <div>
                    <h2 id="dlg-user-title" data-user-name>Utilisateur</h2>
                    <span class="muted small" data-user-email></span>
                </div>
            </div>
            <button type="button" class="btn-icon" data-close-dialog aria-label="Fermer"><?= View::icon('x') ?></button>
        </div>
        <div class="modal-body form-grid">
            <div class="field">
                <span class="field-label">Rôles</span>
                <div class="toggle-group">
                    <?php foreach ($roles as $r): ?>
                        <label class="toggle-chip">
                            <input type="checkbox" name="roles[]" value="<?= View::e($r['code']) ?>">
                            <span><?= View::icon('check', 14) ?><?= View::e($r['label']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="dlg-user-service">Service</label>
                <select id="dlg-user-service" name="service_id">
                    <option value="0">— Non renseigné —</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"><?= View::e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grid cols-2">
                <label class="switch-row"><input type="checkbox" class="switch" name="is_admin" value="1"> Accès administrateur</label>
                <label class="switch-row"><input type="checkbox" class="switch" name="is_active" value="1"> Compte actif</label>
            </div>
            <p class="field-hint" data-user-self hidden>C’est votre propre compte : vos droits d’administrateur et son activation ne peuvent pas être retirés ici.</p>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" data-close-dialog>Annuler</button>
            <button type="submit" class="btn btn-primary"><?= View::icon('check', 16) ?> Enregistrer</button>
        </div>
    </form>
</dialog>
