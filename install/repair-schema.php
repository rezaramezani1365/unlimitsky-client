<?php
/**
 * Re-run database schema using existing config.php (fixes incomplete installs).
 * Usage: sudo php install/repair-schema.php
 */
if (PHP_SAPI !== 'cli') {
    die("Run from CLI only.\n");
}

$root = dirname(__DIR__);
$config_path = $root . '/config.php';
if (!file_exists($config_path)) {
    fwrite(STDERR, "Missing config.php — run install-ubuntu.sh first.\n");
    exit(1);
}

require_once $config_path;
$db = $config['database'] ?? array();
foreach (array('db_name', 'db_username', 'db_password') as $k) {
    if (empty($db[$k]) || strpos($db[$k], '[*') === 0) {
        fwrite(STDERR, "config.php still has placeholders — run cli-install.php or the web installer.\n");
        exit(1);
    }
}

define('USK_INSTALL', true);
$_GET['db_name'] = $db['db_name'];
$_GET['db_username'] = $db['db_username'];
$_GET['db_password'] = $db['db_password'];

ob_start();
include $root . '/sql/sql.php';
$out = ob_get_clean();
$data = json_decode($out, true);
if (!is_array($data) || empty($data['status'])) {
    fwrite(STDERR, "Schema repair failed: " . ($data['msg'] ?? $out) . "\n");
    exit(1);
}

echo "OK: " . ($data['msg'] ?? 'schema updated') . "\n";
