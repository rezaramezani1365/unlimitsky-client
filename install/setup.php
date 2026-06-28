<?php
require_once __DIR__ . '/guard.php';
usk_install_guard();
require_once __DIR__ . '/common.php';

if (file_exists(__DIR__ . '/unlimitsky.install')) {
    header('Location: ../home.php');
    exit;
}

if (empty($_SESSION['usk_install_lang'])) {
    header('Location: index.php');
    exit;
}

if (!file_exists(__DIR__ . '/.db-provision.json')) {
    header('Location: index.php');
    exit;
}

$lang = usk_valid_lang($_SESSION['usk_install_lang']);

usk_install_head($lang, usk_install_t('step2_title', $lang));
usk_install_brand();
usk_install_steps(2);
?>
<p class="install-sub"><?= htmlspecialchars(usk_install_t('step2_sub', $lang)) ?></p>

<div class="selected-lang-badge">
    <i class="fa-solid fa-globe"></i>
    <?= htmlspecialchars(usk_install_t('selected_lang', $lang)) ?>:
    <strong><?= htmlspecialchars(usk_install_lang_label($lang)) ?></strong>
    <a href="index.php" class="btn-link-back"><?= htmlspecialchars(usk_install_t('change_lang', $lang)) ?></a>
</div>

<div class="alert alert-usk-info mb-3" style="background:#0a1a2a;border:1px solid #0099ff;color:#b8dcff;padding:12px;border-radius:6px;font-size:14px;">
    <i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars(usk_install_t('db_auto_note', $lang)) ?>
</div>

<form action="install.php" method="POST" class="install-form">
    <input type="hidden" name="language" value="<?= htmlspecialchars($lang) ?>">

    <div class="form-section">
        <div class="section-title"><?= htmlspecialchars(usk_install_t('admin_section', $lang)) ?></div>
        <div class="form-group">
            <label><i class="fa-solid fa-user"></i> <?= htmlspecialchars(usk_install_t('admin_user', $lang)) ?></label>
            <input type="text" name="admin-user" required autocomplete="off" value="admin">
        </div>
        <div class="form-group">
            <label><i class="fa-solid fa-lock"></i> <?= htmlspecialchars(usk_install_t('admin_pass', $lang)) ?></label>
            <input type="password" name="admin-pass" autocomplete="new-password" placeholder="<?= htmlspecialchars(usk_install_t('admin_pass_hint', $lang)) ?>">
            <small style="color:#888;display:block;margin-top:6px;"><?= htmlspecialchars(usk_install_t('admin_pass_help', $lang)) ?></small>
        </div>
    </div>

    <div class="form-section">
        <div class="section-title"><?= $lang === 'en' ? 'License server (optional)' : 'سرور لایسنس (اختیاری)' ?></div>
        <div class="form-group">
            <label><i class="fa-solid fa-link"></i> License API URL</label>
            <input type="url" name="license-server" autocomplete="off" placeholder="https://license.example.com/api/v1.php" dir="ltr">
        </div>
        <div class="form-group">
            <label><i class="fa-solid fa-key"></i> API token</label>
            <input type="text" name="license-token" autocomplete="off" dir="ltr">
        </div>
    </div>

    <button type="submit" class="btn-install">
        <i class="fa-solid fa-rocket"></i>
        <?= htmlspecialchars(usk_install_t('submit', $lang)) ?>
    </button>
</form>
<?php usk_install_foot(); ?>
