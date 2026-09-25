<?php
use App\Support\View;

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$toastIcons = ['success' => 'check-circle', 'error' => 'alert', 'warning' => 'alert'];
?>
<div class="toasts" aria-live="polite">
    <?php foreach ($flashes as $flash):
        $type = in_array($flash['type'], ['error', 'warning'], true) ? $flash['type'] : 'success'; ?>
        <div class="toast toast-<?= $type ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>" data-toast>
            <span class="toast-icon"><?= View::icon($toastIcons[$type]) ?></span>
            <div class="toast-msg"><?= View::e($flash['message']) ?></div>
            <button type="button" class="toast-close" data-toast-close aria-label="Fermer"><?= View::icon('x', 16) ?></button>
        </div>
    <?php endforeach; ?>
</div>
