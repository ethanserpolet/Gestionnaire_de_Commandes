<?php
use App\Support\View;

/** @var string $content */
/** @var array|null $currentUser */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';
$documentTitle = isset($pageTitle) ? $pageTitle . ' · Commandes St Marc' : 'Commandes · Lycée St Marc';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#6366f1">
    <title><?= View::e($documentTitle) ?></title>
    <script>try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/app.css?v=9">
</head>
<body>
<div class="aurora" aria-hidden="true"><span></span><span></span><span></span></div>

<?php if ($currentUser): ?>
    <div class="app" data-app>
        <?php require __DIR__ . '/partials/sidebar.php'; ?>
        <div class="sidebar-backdrop" data-nav-close></div>

        <div class="main">
            <header class="mobile-bar glass">
                <button type="button" class="btn-icon" data-nav-toggle aria-label="Ouvrir le menu" aria-controls="sidebar">
                    <?= View::icon('menu', 20) ?>
                </button>
                <a href="/" class="brand">
                    <span class="brand-logo sm"><?= View::icon('receipt', 16) ?></span>
                    <span class="brand-name">Commandes</span>
                </a>
                <?= View::avatar($currentUser['display_name'], 'sm') ?>
            </header>

            <main class="content" id="contenu">
                <?= $content ?>
            </main>
        </div>
    </div>
<?php else: ?>
    <button type="button" class="btn-icon theme-toggle auth-theme glass" data-theme-toggle aria-label="Changer de thème">
        <span class="i-moon"><?= View::icon('moon') ?></span>
        <span class="i-sun"><?= View::icon('sun') ?></span>
    </button>
    <main class="auth-shell">
        <?= $content ?>
    </main>
<?php endif; ?>

<?php require __DIR__ . '/partials/flash.php'; ?>
<?php require __DIR__ . '/partials/confirm_dialog.php'; ?>
<script src="/assets/js/app.js?v=9"></script>
</body>
</html>
