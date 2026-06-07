<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
USK_Admin_Auth::boot();

if (!empty($_GET['lang']) && in_array($_GET['lang'], array('fa', 'en'), true)) {
    $_SESSION['usk_lang'] = $_GET['lang'];
    header('Location: login.php');
    exit;
}

if (USK_Admin_Auth::check()) {
    if (USK_Admin_Auth::must_change_password()) {
        header('Location: ' . usk_admin_url('settings'));
        exit;
    }
    header('Location: index.php');
    exit;
}

$error = '';
$lockout = USK_Admin_Auth::lockout_remaining_seconds();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockout > 0) {
        $error = __('login_locked', 'Too many attempts. Try again later.');
    } elseif (USK_Admin_Auth::login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        USK_License::sync_presence_with_vendor(true);
        if (USK_Admin_Auth::must_change_password()) {
            header('Location: ' . usk_admin_url('settings'));
            exit;
        }
        header('Location: index.php');
        exit;
    } else {
        $lockout = USK_Admin_Auth::lockout_remaining_seconds();
        $error = $lockout > 0 ? __('login_locked', 'Too many attempts. Try again later.') : __('login_failed');
    }
}

$lang = usk_lang();
$dir = usk_dir();
?>
<!DOCTYPE html>
<html lang="<?= usk_esc(USK_I18n::html_lang()) ?>" dir="<?= $dir ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('login_title') ?> | <?= __('brand') ?></title>
    <?php usk_enqueue_head(); ?>
</head>
<body class="login-page">
    <div class="login-page-shell">
        <div class="login-stack">
    <div class="login-card">
        <div class="login-brand"><?= __('brand') ?></div>
        <p class="text-center text-muted small mb-4"><?= __('login_sub') ?></p>
        <div class="lang-switch justify-content-center mb-3 d-flex">
            <a href="?lang=fa" class="<?= $lang === 'fa' ? 'active' : '' ?>">FA</a>
            <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
        </div>
        <?php if ($error) : ?>
            <div class="alert alert-danger py-2 small">
                <?= usk_esc($error) ?>
                <?php if ($lockout > 0) : ?> (<?= (int) ceil($lockout / 60) ?> <?= __('minutes', 'min') ?>)<?php endif; ?>
            </div>
        <?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label"><?= __('username') ?></label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-secondary"><i class="fa-solid fa-user"></i></span>
                    <input class="form-control" type="text" name="username" required autofocus autocomplete="username"
                        value="<?= usk_esc(trim($_POST['username'] ?? '')) ?>"
                        placeholder="<?= usk_esc(__('username')) ?>">
                </div>
                <p class="form-text text-muted small mb-0"><?= __('login_username_note') ?></p>
            </div>
            <div class="mb-4">
                <label class="form-label"><?= __('password') ?></label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-secondary"><i class="fa-solid fa-lock"></i></span>
                    <input class="form-control" type="password" name="password" required>
                </div>
            </div>
            <button type="submit" class="btn btn-usk-primary w-100" <?= $lockout > 0 ? 'disabled' : '' ?>><?= __('login_btn') ?></button>
        </form>
        <div class="text-center mt-3">
            <button type="button" class="btn btn-sm btn-outline-usk" id="theme-toggle"><i class="fa-solid fa-moon" id="theme-icon"></i></button>
        </div>
    </div>
            <?php require __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>
    <?php usk_enqueue_foot(); ?>
</body>
</html>
