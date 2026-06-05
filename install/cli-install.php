<?php
/**
 * CLI installer — Reseller panel (no browser needed)
 */
if (PHP_SAPI !== 'cli') {
    die("Run from CLI only.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

function usk_cli_arg($name, $default = '')
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function usk_cli_flag($name)
{
    global $argv;
    return in_array('--' . $name, $argv, true) || in_array('--' . $name . '=1', $argv, true);
}

function usk_cli_run_sql_setup($db_name, $db_user, $db_pass)
{
    define('USK_INSTALL', true);
    $_GET['db_name'] = $db_name;
    $_GET['db_username'] = $db_user;
    $_GET['db_password'] = $db_pass;

    ob_start();
    include dirname(__DIR__) . '/sql/sql.php';
    $output = ob_get_clean();
    $data = json_decode($output, true);
    if (!is_array($data)) {
        return array('status' => false, 'msg' => 'Invalid SQL setup response');
    }
    return $data;
}

$root = dirname(__DIR__);

if (file_exists(__DIR__ . '/unlimitsky.install')) {
    fwrite(STDERR, "Already installed. Remove install/unlimitsky.install to reinstall.\n");
    exit(1);
}

$domain = rtrim(trim(usk_cli_arg('domain')), '/');
$db_name = trim(usk_cli_arg('db-name'));
$db_user = trim(usk_cli_arg('db-user'));
$db_pass = usk_cli_arg('db-pass');
$admin_user = trim(usk_cli_arg('admin-user', 'admin')) ?: 'admin';
$admin_pass = usk_cli_arg('admin-pass');
$lang = in_array(usk_cli_arg('lang', 'fa'), array('fa', 'en'), true) ? usk_cli_arg('lang', 'fa') : 'fa';
$license_server = trim(usk_cli_arg('license-server'));
$license_token = trim(usk_cli_arg('license-token'));
$must_change = usk_cli_flag('must-change');

foreach (array('domain' => $domain, 'db-name' => $db_name, 'db-user' => $db_user) as $k => $v) {
    if ($v === '') {
        fwrite(STDERR, "Missing required: --{$k}\n");
        exit(1);
    }
}

if ($admin_pass === '') {
    $admin_pass = 'admin';
    $must_change = true;
}

if (strlen($admin_pass) < 6 && !($admin_pass === 'admin' && $must_change)) {
    fwrite(STDERR, "Admin password must be at least 6 characters.\n");
    exit(1);
}

$config_path = $root . '/config.php';
$config_file = file_get_contents($config_path);
if ($config_file === false) {
    fwrite(STDERR, "Cannot read config.php\n");
    exit(1);
}

$replace = str_replace(
    array('[*TOKEN*]', '[*DEV*]', '[*DB-NAME*]', '[*DB-USER*]', '[*DB-PASS*]', '[*LICENSE-SERVER*]', '[*LICENSE-TOKEN*]'),
    array('none', '0', $db_name, $db_user, $db_pass, $license_server, $license_token),
    $config_file
);

if (file_put_contents($config_path, $replace) === false) {
    fwrite(STDERR, "Cannot write config.php\n");
    exit(1);
}

$connect = usk_cli_run_sql_setup($db_name, $db_user, $db_pass);
if (empty($connect['status'])) {
    $provision = array(
        'db_name' => $db_name,
        'db_user' => $db_user,
        'db_pass' => $db_pass,
        'created_at' => date('c'),
    );
    @file_put_contents(__DIR__ . '/.db-provision.json', json_encode($provision, JSON_PRETTY_PRINT));
    fwrite(STDERR, 'Database setup failed: ' . ($connect['msg'] ?? 'unknown') . "\n");
    fwrite(STDERR, "Credentials kept in config.php and install/.db-provision.json — fix schema then re-run.\n");
    exit(1);
}

require_once $config_path;
require_once $root . '/admin/lib/auth.php';
require_once $root . '/admin/lib/license.php';
USK_Admin_Auth::create_from_install($admin_user, $admin_pass, $lang, $must_change);
USK_License::register_with_vendor();

$install_data = array(
    'install_location' => $domain,
    'db_name' => $db_name,
    'db_username' => $db_user,
    'language' => $lang,
    'admin_username' => $admin_user,
    'installed_at' => date('c'),
);

if (file_put_contents(__DIR__ . '/unlimitsky.install', json_encode($install_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    fwrite(STDERR, "Cannot write unlimitsky.install\n");
    exit(1);
}

@unlink(__DIR__ . '/.db-provision.json');

echo json_encode(array(
    'ok' => true,
    'domain' => $domain,
    'admin_url' => $domain . '/admin/login.php',
    'install_url' => $domain . '/install/index.php',
    'db_name' => $db_name,
    'db_user' => $db_user,
    'admin_user' => $admin_user,
    'must_change_password' => $must_change,
    'language' => $lang,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
