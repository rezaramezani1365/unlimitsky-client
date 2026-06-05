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

$sql = @new mysqli(
    'localhost',
    $config['database']['db_username'],
    $config['database']['db_password'],
    $config['database']['db_name']
);

if ($sql->connect_error) {
    if (defined('USK_ADMIN')) {
        http_response_code(500);
        die('<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>Database error</title></head><body style="font-family:tahoma;padding:40px;">'
            . '<h2>Database connection failed</h2><p>' . htmlspecialchars($sql->connect_error) . '</p>'
            . '<p><a href="' . htmlspecialchars(rtrim($base_domain, '/') . '/install/index.php') . '">Install wizard</a></p></body></html>');
    }
    die(json_encode(['status' => false, 'msg' => $sql->connect_error, 'error' => 'database']));
}

$sql->set_charset('utf8mb4');

define('API_KEY', $config['token']);
