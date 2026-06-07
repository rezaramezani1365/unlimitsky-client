<?php
/**
 * Recreate panel admin user (empty panel_admin / lost login).
 * Usage: sudo php install/reset-admin.php [--user=admin] [--pass=Pass123] [--lang=fa]
 */
if (PHP_SAPI !== 'cli') {
    die("Run from CLI only.\n");
}

function usk_reset_arg($name, $default = '')
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

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "Missing config.php — run install first.\n");
    exit(1);
}

if (!defined('USK_ROOT')) {
    define('USK_ROOT', $root);
}

require_once $root . '/config.php';
require_once $root . '/admin/lib/auth.php';

$user = trim(usk_reset_arg('user', 'admin')) ?: 'admin';
$pass = usk_reset_arg('pass', 'Pass123');
$lang = in_array(usk_reset_arg('lang', 'fa'), array('fa', 'en'), true) ? usk_reset_arg('lang', 'fa') : 'fa';
$must_change = in_array('--must-change', $argv, true);

if ($pass === '' || strlen($pass) < 6) {
    fwrite(STDERR, "Password must be at least 6 characters.\n");
    exit(1);
}

global $sql;
$db_name = $config['database']['db_name'] ?? '?';

if (!($sql instanceof mysqli) || $sql->connect_error) {
    fwrite(STDERR, "Database connection failed — check config.php (db: {$db_name})\n");
    exit(1);
}

$before = $sql->query('SELECT COUNT(*) AS c FROM `panel_admin`');
$count_before = $before ? (int) $before->fetch_assoc()['c'] : -1;

if (!USK_Admin_Auth::create_from_install($user, $pass, $lang, $must_change)) {
    fwrite(STDERR, "Failed to create admin in `{$db_name}`.\n");
    exit(1);
}

$after = $sql->query('SELECT `id`, `username` FROM `panel_admin` ORDER BY `id` ASC LIMIT 1');
$row = $after && $after->num_rows > 0 ? $after->fetch_assoc() : null;
if (!$row) {
    fwrite(STDERR, "Insert failed — panel_admin is still empty in `{$db_name}`.\n");
    exit(1);
}

$login = rtrim((string) ($config['domain'] ?? ''), '/') . '/admin/login.php';
echo "OK: admin recreated in `{$db_name}`\n";
echo "  id:       {$row['id']}\n";
echo "  username: {$row['username']}\n";
echo "  rows before: {$count_before}\n";
echo "  login URL: {$login}\n";
