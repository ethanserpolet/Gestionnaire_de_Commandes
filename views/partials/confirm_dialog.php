<?php use App\Support\View; ?>
<dialog class="modal modal-sm" id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="modal-card">
        <div class="confirm-body">
            <span class="confirm-icon"><?= View::icon('alert', 22) ?></span>
            <div>
                <h3 id="confirm-title" data-confirm-title>Confirmer l’action</h3>
                <p data-confirm-message></p>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" data-close-dialog>Annuler</button>
            <button type="button" class="btn btn-primary" data-confirm-ok>Confirmer</button>
        </div>
    </div>
</dialog>
