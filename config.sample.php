<?php
/**
 * Copy to config.php on first install (install-ubuntu.sh does this automatically).
 * Placeholders are replaced by the install wizard / cli-install.php.
 */

date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL ^ E_NOTICE);

$script_path = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
$folder = basename(dirname($script_path));
if ($folder === 'lib' || $folder === 'admin') {
    $folder = basename(dirname(dirname($script_path)));
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_domain = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($folder && $folder !== '.' ? '/' . $folder : '');

$config = [
    'version' => '3.0',
    'domain' => $base_domain,
    'token' => '[*TOKEN*]',
    'dev' => '[*DEV*]',
    'license_server' => '[*LICENSE-SERVER*]',
    'license_api_token' => '[*LICENSE-TOKEN*]',
    'free_max_plans' => 1,
    'database' => [
        'db_name' => '[*DB-NAME*]',
        'db_username' => '[*DB-USER*]',
        'db_password' => '[*DB-PASS*]',
    ],
];

$db_cfg = $config['database'];
$config_incomplete = (
    strpos($db_cfg['db_name'], '[*') === 0
    || strpos($db_cfg['db_username'], '[*') === 0
    || strpos($db_cfg['db_password'], '[*') === 0
);

$sql = null;
if (!$config_incomplete) {
    try {
        $sql = new mysqli(
            'localhost',
            $db_cfg['db_username'],
            $db_cfg['db_password'],
            $db_cfg['db_name']
        );
    } catch (mysqli_sql_exception $e) {
        $sql = null;
        $connect_error = $e->getMessage();
    }
}

if ($config_incomplete) {
    $connect_error = 'Install not finished — database credentials are not configured yet.';
} elseif (!($sql instanceof mysqli) || !empty($sql->connect_error)) {
    $connect_error = ($sql instanceof mysqli && $sql->connect_error)
        ? $sql->connect_error
        : ($connect_error ?? 'Database connection failed');
}

if (!empty($connect_error)) {
    $install_url = preg_replace('#/admin(/.*)?$#', '', rtrim($base_domain, '/')) . '/install/index.php';
    if (defined('USK_ADMIN')) {
        http_response_code(503);
        die('<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>Database error</title></head><body style="font-family:tahoma;padding:40px;">'
            . '<h2>Database connection failed</h2><p>' . htmlspecialchars($connect_error) . '</p>'
            . '<p>Run on the server: <code>sudo bash install/finish-install.sh \'YourPass123\'</code></p>'
            . '<p><a href="' . htmlspecialchars($install_url) . '">Install wizard</a></p></body></html>');
    }
    die(json_encode(array('status' => false, 'msg' => $connect_error, 'error' => 'database')));
}

$sql->set_charset('utf8mb4');

define('API_KEY', $config['token']);
