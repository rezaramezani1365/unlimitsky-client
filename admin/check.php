<?php
/**
 * تست سلامت پنل — بعد از رفع مشکل این فایل را حذف کنید
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

echo '<pre style="direction:ltr;font-family:monospace;padding:20px;">';
echo "PHP: " . PHP_VERSION . "\n";
echo "USK_ROOT test: " . dirname(__DIR__) . "\n";
echo "config exists: " . (file_exists(dirname(__DIR__) . '/config.php') ? 'YES' : 'NO') . "\n";

try {
    require_once __DIR__ . '/lib/init.php';
    echo "init.php: OK\n";
    echo "DB: OK\n";
    require_once __DIR__ . '/lib/auth.php';
    USK_Admin_Auth::boot();
    echo "auth boot: OK\n";
    echo "data writable: " . (is_writable(__DIR__ . '/data') || is_writable(__DIR__) ? 'YES' : 'NO') . "\n";
    echo "\nAll OK — login.php should work.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
echo '</pre>';
