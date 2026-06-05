<?php
header('Content-Type: text/html; charset=utf-8');

$base_url   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$base_url   = ($base_url === '' || $base_url === '.') ? '' : $base_url;
$site_root  = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base_url;
$admin_url  = $site_root . '/admin/login.php';
$installed  = file_exists(__DIR__ . '/install/unlimitsky.install');
$lang = 'fa';
if ($installed) {
    $inst = json_decode(file_get_contents(__DIR__ . '/install/unlimitsky.install'), true);
    if (!empty($inst['language'])) $lang = $inst['language'];
}
if (!empty($_GET['lang']) && in_array($_GET['lang'], array('fa', 'en'), true)) {
    $lang = $_GET['lang'];
}
$dir = $lang === 'en' ? 'ltr' : 'rtl';
$bs = $lang === 'en' ? 'admin/assets/vendor/bootstrap/bootstrap.min.css' : 'admin/assets/vendor/bootstrap/bootstrap.rtl.min.css';
$t = array(
    'fa' => array('tag' => 'پنل شخصی مدیریت VPN', 'installed' => 'نصب شده', 'not_inst' => 'نیاز به نصب', 'enter' => 'ورود به پنل', 'install' => 'نصب'),
    'en' => array('tag' => 'Personal VPN management panel', 'installed' => 'Installed', 'not_inst' => 'Setup required', 'enter' => 'Open panel', 'install' => 'Install'),
);
$tr = $t[$lang];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="<?= $dir ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UnlimitedSky</title>
    <link href="<?= htmlspecialchars($base_url) ?>/admin/assets/css/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($base_url) ?>/admin/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($base_url) ?>/<?= $bs ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars($base_url) ?>/admin/assets/css/theme.css" rel="stylesheet">
    <script src="<?= htmlspecialchars($base_url) ?>/admin/assets/js/theme.js"></script>
    <style>
        .hero-page { min-height:100vh; background:#000; display:flex; align-items:center; justify-content:center; padding:2rem; }
        .hero-card { max-width:420px; width:100%; text-align:center; background:var(--usk-surface); border:1px solid var(--usk-border); border-top:2px solid var(--usk-blue); border-radius:4px; padding:2.5rem 2rem; }
        .hero-title { font-size:1.5rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
        .hero-title span { color:var(--usk-blue); }
    </style>
</head>
<body class="hero-page">
    <div class="hero-card">
        <div class="hero-title mb-2">Unlimited<span>Sky</span></div>
        <p class="text-muted small mb-3"><?= htmlspecialchars($tr['tag']) ?></p>
        <div class="lang-switch justify-content-center mb-3 d-inline-flex">
            <a href="?lang=fa" class="<?= $lang === 'fa' ? 'active' : '' ?>">FA</a>
            <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
        </div>
        <p class="mb-4">
            <?= $installed
                ? '<span class="badge badge-usk"><i class="fa-solid fa-check"></i> ' . htmlspecialchars($tr['installed']) . '</span>'
                : '<span class="badge badge-danger">' . htmlspecialchars($tr['not_inst']) . '</span>' ?>
        </p>
        <?php if ($installed) : ?>
            <a class="btn btn-usk-primary btn-lg w-100" href="<?= htmlspecialchars($admin_url) ?>">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> <?= htmlspecialchars($tr['enter']) ?>
            </a>
        <?php else : ?>
            <a class="btn btn-usk-primary btn-lg w-100" href="install/index.php">
                <i class="fa-solid fa-download"></i> <?= htmlspecialchars($tr['install']) ?>
            </a>
        <?php endif; ?>
        <div class="mt-3">
            <button type="button" class="btn btn-sm btn-outline-usk" id="theme-toggle"><i class="fa-solid fa-moon" id="theme-icon"></i></button>
        </div>
    </div>
</body>
</html>
