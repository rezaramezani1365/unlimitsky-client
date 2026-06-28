<?php
require_once __DIR__ . '/guard.php';
usk_install_guard();
require_once __DIR__ . '/common.php';

if (file_exists(__DIR__ . '/unlimitsky.install')) {
    header('Location: ../home.php');
    exit;
}

$lang = 'fa';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['language'])) {
    $lang = usk_valid_lang($_POST['language']);
    $_SESSION['usk_install_lang'] = $lang;
    header('Location: setup.php');
    exit;
}

if (isset($_SESSION['usk_install_lang'])) {
    $lang = $_SESSION['usk_install_lang'];
}

$provision_ok = file_exists(__DIR__ . '/.db-provision.json');

usk_install_head($lang, usk_install_t('step1_title', $lang));
usk_install_brand();
usk_install_steps(1);
?>
<p class="install-sub"><?= htmlspecialchars(usk_install_t('step1_sub', $lang)) ?></p>

<?php if (!$provision_ok) : ?>
    <div class="alert alert-danger mb-3" style="background:#2a1010;border:1px solid #ff3b30;color:#ffb4b0;padding:12px;border-radius:6px;font-size:14px;">
        <?= htmlspecialchars(usk_install_t('provision_missing', $lang)) ?>
    </div>
<?php endif; ?>

<form method="post" action="index.php">
    <div class="lang-pick mb-4">
        <span class="lang-label"><?= htmlspecialchars(usk_install_t('language', $lang)) ?></span>
        <div class="lang-switch lang-switch-lg">
            <label class="lang-option">
                <input type="radio" name="language" value="fa" <?= $lang === 'fa' ? 'checked' : '' ?> required>
                <span class="lang-btn lang-btn-lg"><i class="fa-solid fa-language"></i> <?= usk_install_t('lang_fa', $lang) ?></span>
            </label>
            <label class="lang-option">
                <input type="radio" name="language" value="en" <?= $lang === 'en' ? 'checked' : '' ?>>
                <span class="lang-btn lang-btn-lg"><i class="fa-solid fa-language"></i> <?= usk_install_t('lang_en', $lang) ?></span>
            </label>
        </div>
    </div>
    <button type="submit" class="btn-install" <?= $provision_ok ? '' : 'disabled' ?>>
        <?= htmlspecialchars(usk_install_t('continue', $lang)) ?>
        <i class="fa-solid fa-arrow-<?= $lang === 'en' ? 'right' : 'left' ?>"></i>
    </button>
</form>
<?php usk_install_foot(); ?>
