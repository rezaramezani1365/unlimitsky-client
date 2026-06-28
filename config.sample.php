<?php
/**
 * Copy to config.php on first install (install-ubuntu.sh does this automatically).
 * Placeholders are replaced by cli-install.php.
 */

date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL ^ E_NOTICE);

if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (!empty($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
    $_SERVER['HTTPS'] = 'on';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$config = [
    'version' => '3.0',
    'domain' => '[*DOMAIN*]',
    'token' => '[*TOKEN*]',
    'dev' => '[*DEV*]',
    // Default license server: vendor.iranip.online:8081 (token set via Admin → Pro License)
    'license_server' => 'http://vendor.iranip.online:8081/api/v1.php',
    'license_api_token' => '[*LICENSE-TOKEN*]',
    'free_max_plans' => 1,
    'database' => [
        'db_name' => '[*DB-NAME*]',
        'db_username' => '[*DB-USER*]',
        'db_password' => '[*DB-PASS*]',
    ],
];

if (strpos($config['domain'], '[*') === 0) {
    $config['domain'] = $scheme . '://' . $host;
}

$db_cfg = $config['database'];
$config_incomplete = (
    strpos($db_cfg['db_name'], '[*') === 0
    || strpos($db_cfg['db_username'], '[*') === 0
    || strpos($db_cfg['db_password'], '[*') === 0
);

$sql = null;
$connect_error = '';

if (!$config_incomplete) {
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $sql = new mysqli(
            'localhost',
            $db_cfg['db_username'],
            $db_cfg['db_password'],
            $db_cfg['db_name']
        );
        mysqli_report(MYSQLI_REPORT_OFF);
    } catch (mysqli_sql_exception $e) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $connect_error = $e->getMessage();
    }
} else {
    $connect_error = 'Install not finished yet.';
}

if ($connect_error !== '') {
    if (defined('USK_ADMIN')) {
        http_response_code(503);
        die('<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>Setup required</title></head><body style="font-family:tahoma;padding:40px;">'
            . '<h2>Setup not complete</h2><p>' . htmlspecialchars($connect_error) . '</p>'
            . '<p>Re-run the install command on the server, or open the install wizard.</p>'
            . '<p><a href="' . htmlspecialchars(rtrim($config['domain'], '/') . '/install/index.php') . '">Install wizard</a></p></body></html>');
    }
    die(json_encode(array('status' => false, 'msg' => $connect_error, 'error' => 'database')));
}

$sql->set_charset('utf8mb4');

if (is_file(__DIR__ . '/admin/lib/panel-access.php')) {
    require_once __DIR__ . '/admin/lib/panel-access.php';
    USK_PanelAccess::enforce_request_host();
}

define('API_KEY', $config['token']);
