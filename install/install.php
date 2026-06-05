<?php
/**
 * UnlimitedSky installer — database auto-provisioned by install-ubuntu.sh
 */
require_once __DIR__ . '/guard.php';
usk_install_guard();

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function usk_install_lang()
{
    $lang = isset($_POST['language']) ? $_POST['language'] : 'fa';
    return in_array($lang, array('fa', 'en'), true) ? $lang : 'fa';
}

function usk_install_domain()
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $path = dirname(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''));
    $path = ($path === '/' || $path === '\\' || $path === '.') ? '' : $path;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

function usk_install_message($text, $success, $lang)
{
    $dir = ($lang === 'en') ? 'ltr' : 'rtl';
    $color = $success ? '#00c853' : '#ff3b30';
    $bs = ($lang === 'en')
        ? '../admin/assets/vendor/bootstrap/bootstrap.min.css'
        : '../admin/assets/vendor/bootstrap/bootstrap.rtl.min.css';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars($lang) . '" dir="' . $dir . '">';
    echo '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link rel="stylesheet" href="../admin/assets/css/fonts.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($bs) . '">';
    echo '<title>UnlimitedSky</title>';
    echo '<style>body{font-family:IRANSans,Segoe UI,sans-serif;background:#000;color:#f5f5f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;margin:0}';
    echo '.box{max-width:520px;border:1px solid #222;border-top:2px solid #0099ff;padding:2rem;border-radius:4px;text-align:center;line-height:1.8}';
    echo 'h2{color:' . $color . ';font-size:1.15rem;font-weight:600;margin:0 0 1rem}a{color:#0099ff}</style></head><body><div class="box">';
    echo '<div style="font-weight:800;margin-bottom:1rem">UnlimitedSky</div>';
    echo $text;
    echo '<p style="margin-top:1.5rem"><a href="index.php">' . ($lang === 'en' ? 'Back to install' : 'بازگشت به نصب') . '</a></p>';
    echo '</div></body></html>';
    exit;
}

function usk_run_sql_setup($db_name, $db_user, $db_pass)
{
    if (!class_exists('mysqli')) {
        return array('status' => false, 'msg' => 'PHP mysqli extension is not enabled');
    }

    if (!defined('USK_INSTALL')) {
        define('USK_INSTALL', true);
    }

    $_GET['db_name'] = $db_name;
    $_GET['db_username'] = $db_user;
    $_GET['db_password'] = $db_pass;

    ob_start();
    try {
        include dirname(__DIR__) . '/sql/sql.php';
    } catch (Throwable $e) {
        ob_end_clean();
        return array('status' => false, 'msg' => $e->getMessage());
    }
    $output = ob_get_clean();

    if ($output === false || $output === '') {
        return array('status' => false, 'msg' => 'Empty response from database setup');
    }

    $data = json_decode($output, true);
    if (!is_array($data)) {
        return array('status' => false, 'msg' => 'Invalid setup response: ' . substr(strip_tags($output), 0, 200));
    }
    return $data;
}

function usk_load_db_provision()
{
    $file = __DIR__ . '/.db-provision.json';
    if (!is_readable($file)) {
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || empty($data['db_name']) || empty($data['db_user']) || !isset($data['db_pass'])) {
        return null;
    }
    return $data;
}

$lang = usk_install_lang();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    usk_install_message(
        $lang === 'en' ? '<h2>Start from <a href="index.php">install step 1</a></h2>' : '<h2>از <a href="index.php">مرحله ۱ نصب</a> شروع کنید</h2>',
        false,
        $lang
    );
}

if (!isset($_POST['admin-user']) || trim($_POST['admin-user']) === '') {
    usk_install_message(
        $lang === 'en' ? '<h2>Admin username required</h2>' : '<h2>نام کاربری مدیر الزامی است</h2>',
        false,
        $lang
    );
}

if (file_exists(__DIR__ . '/unlimitsky.install')) {
    usk_install_message(
        $lang === 'en' ? '<h2>Already installed</h2>' : '<h2>قبلاً نصب شده</h2>',
        false,
        $lang
    );
}

$provision = usk_load_db_provision();
if ($provision === null) {
    usk_install_message(
        $lang === 'en'
            ? '<h2>Database not ready</h2><p>Run <code>sudo bash install-ubuntu.sh --port 8082</code> on the server first.</p>'
            : '<h2>دیتابیس آماده نیست</h2><p>ابتدا روی سرور <code>sudo bash install-ubuntu.sh --port 8082</code> را اجرا کنید.</p>',
        false,
        $lang
    );
}

$root = dirname(__DIR__);
$admin_user = trim($_POST['admin-user']);
$admin_pass = trim($_POST['admin-pass'] ?? '');
$must_change = false;

if ($admin_pass === '') {
    $admin_pass = 'admin';
    $must_change = true;
} elseif (strlen($admin_pass) < 6) {
    usk_install_message(
        $lang === 'en' ? '<h2>Admin password must be at least 6 characters</h2>' : '<h2>رمز مدیر حداقل ۶ کاراکتر</h2>',
        false,
        $lang
    );
}

$db_name = $provision['db_name'];
$db_user = $provision['db_user'];
$db_pass = $provision['db_pass'];

$config_path = $root . '/config.php';
$config_file = file_get_contents($config_path);
if ($config_file === false) {
    usk_install_message(
        $lang === 'en' ? '<h2>Cannot read config.php</h2>' : '<h2>خواندن config.php ممکن نیست</h2>',
        false,
        $lang
    );
}

$replace = str_replace(
    array('[*TOKEN*]', '[*DEV*]', '[*DB-NAME*]', '[*DB-USER*]', '[*DB-PASS*]', '[*LICENSE-SERVER*]', '[*LICENSE-TOKEN*]'),
    array(
        'none', '0', $db_name, $db_user, $db_pass,
        trim(isset($_POST['license-server']) ? $_POST['license-server'] : ''),
        trim(isset($_POST['license-token']) ? $_POST['license-token'] : ''),
    ),
    $config_file
);

if (@file_put_contents($config_path, $replace) === false) {
    usk_install_message(
        $lang === 'en' ? '<h2>Cannot write config.php</h2>' : '<h2>نوشتن config.php ممکن نیست</h2>',
        false,
        $lang
    );
}

$connect = usk_run_sql_setup($db_name, $db_user, $db_pass);

if (empty($connect['status'])) {
    @file_put_contents($config_path, $config_file);
    $msg = isset($connect['msg']) ? htmlspecialchars($connect['msg']) : ($lang === 'en' ? 'Database setup failed' : 'راه‌اندازی دیتابیس ناموفق');
    usk_install_message(
        ($lang === 'en' ? '<h2>Database setup failed</h2>' : '<h2>راه‌اندازی دیتابیس ناموفق</h2>') . '<p style="color:#888;font-size:14px">' . $msg . '</p>',
        false,
        $lang
    );
}

require_once $config_path;
require_once $root . '/admin/lib/auth.php';
require_once $root . '/admin/lib/license.php';
USK_Admin_Auth::create_from_install($admin_user, $admin_pass, $lang, $must_change);
USK_License::register_with_vendor();

$install_data = array(
    'install_location' => usk_install_domain(),
    'db_name'          => $db_name,
    'db_username'      => $db_user,
    'language'         => $lang,
    'admin_username'   => $admin_user,
    'installed_at'     => date('c'),
);

if (@file_put_contents(__DIR__ . '/unlimitsky.install', json_encode($install_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    usk_install_message(
        $lang === 'en' ? '<h2>Cannot write install/unlimitsky.install</h2>' : '<h2>نوشتن install/unlimitsky.install ممکن نیست</h2>',
        false,
        $lang
    );
}

@unlink(__DIR__ . '/.db-provision.json');
@chmod($config_path, 0640);

$admin_url = htmlspecialchars(rtrim(usk_install_domain(), '/') . '/admin/login.php');
$change_note = $must_change
    ? ($lang === 'en' ? '<p style="color:#888;font-size:14px">You must change the default password on first login.</p>' : '<p style="color:#888;font-size:14px">در اولین ورود باید رمز پیش‌فرض را تغییر دهید.</p>')
    : '';

if (session_status() === PHP_SESSION_ACTIVE) {
    unset($_SESSION['usk_install_lang']);
}

usk_install_message(
    ($lang === 'en'
        ? '<h2>Installed successfully</h2>' . $change_note . '<p><a href="' . $admin_url . '">Open admin panel</a></p>'
        : '<h2>نصب با موفقیت انجام شد</h2>' . $change_note . '<p><a href="' . $admin_url . '">ورود به پنل مدیریت</a></p>'),
    true,
    $lang
);
