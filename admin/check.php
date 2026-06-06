<?php
/**
 * Panel health check — remove this file after debugging
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$adminDir = __DIR__;
$root = dirname($adminDir);

echo '<pre style="direction:ltr;font-family:monospace;padding:20px;">';
echo "PHP: " . PHP_VERSION . "\n";
echo "Panel root: $root\n";
echo "config.php: " . (file_exists("$root/config.php") ? 'YES' : 'NO') . "\n";
$revFile = "$adminDir/data/.deploy-rev";
if (is_file($revFile)) {
    echo "deploy rev: " . trim((string) file_get_contents($revFile)) . "\n";
}
echo "\n";

$files = array(
    'backup module' => "$adminDir/lib/backup.php",
    'backup action' => "$adminDir/backup-action.php",
    'backup page'   => "$adminDir/pages/backup.php",
    'backup UI'     => "$adminDir/includes/backup-panel.php",
    'migration'     => "$adminDir/lib/migration.php",
);

foreach ($files as $label => $path) {
    echo str_pad($label . ':', 16) . (is_file($path) ? 'OK' : 'MISSING') . "\n";
}

echo "\nZipArchive: " . (class_exists('ZipArchive') ? 'YES' : 'NO (apt install php-zip)') . "\n";

try {
    require_once "$adminDir/lib/init.php";
    echo "init.php: OK\n";
    $nav = usk_admin_nav();
    echo "nav backup item: " . (isset($nav['backup']) ? 'YES' : 'NO') . "\n";
    echo "panel version: " . usk_panel_version() . "\n";
    $base = usk_admin_base();
    echo "\nURLs:\n";
    echo "  updates:         {$base}/index.php?page=updates\n";
    echo "  settings+backup: {$base}/index.php?page=settings#usk-backup-section\n";
    echo "  backup page:     {$base}/index.php?page=backup\n";
    echo "  legacy panel:    " . (is_file("$adminDir/pages/coupons.php") ? 'YES (run update!)' : 'NO') . "\n";
    echo "\nIf any file shows MISSING, open Updates page or run panel-self-update.sh on the VPS.\n";
} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
echo '</pre>';
