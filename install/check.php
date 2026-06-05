<?php
require_once __DIR__ . '/guard.php';
usk_install_guard();
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>UnlimitedSky Install Check</title>
    <style>
        body{font-family:tahoma,sans-serif;background:#111;color:#eee;padding:24px;max-width:720px;margin:0 auto}
        h1{color:#0099ff;font-size:1.25rem}
        .ok{color:#00c853}.bad{color:#ff3b30}
        pre{background:#000;padding:12px;border:1px solid #333;overflow:auto;font-size:13px}
        li{margin:6px 0}
    </style>
</head>
<body>
<h1>UnlimitedSky — Install Check</h1>
<ul>
<?php
$checks = array(
    'PHP ' . PHP_VERSION => version_compare(PHP_VERSION, '7.0.0', '>='),
    'mysqli extension' => extension_loaded('mysqli'),
    'json extension' => extension_loaded('json'),
    'config.php readable' => is_readable(dirname(__DIR__) . '/config.php'),
    'config.php writable' => is_writable(dirname(__DIR__) . '/config.php'),
    'sql/sql.php exists' => file_exists(dirname(__DIR__) . '/sql/sql.php'),
    'admin/data writable' => is_writable(dirname(__DIR__) . '/admin/data') || is_writable(dirname(__DIR__) . '/admin'),
    'install/ writable' => is_writable(__DIR__),
    'Already installed' => file_exists(__DIR__ . '/unlimitsky.install'),
);
foreach ($checks as $label => $ok) {
    $cls = $ok ? 'ok' : 'bad';
    $icon = $ok ? 'OK' : 'FAIL';
    echo '<li class="' . $cls . '">' . htmlspecialchars($label) . ' — <strong>' . $icon . '</strong></li>';
}
?>
</ul>
<h2>Paths</h2>
<pre><?php
echo 'SCRIPT: ' . (__FILE__) . "\n";
echo 'ROOT: ' . dirname(__DIR__) . "\n";
echo 'DOMAIN: ' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '-') . "\n";
echo 'HTTPS: ' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'no') . "\n";
?></pre>
<p>If all required items are OK, open <a href="index.php" style="color:#0099ff">install wizard (step 1)</a></p>
<p style="color:#888;font-size:12px">Delete this file after troubleshooting.</p>
</body>
</html>
