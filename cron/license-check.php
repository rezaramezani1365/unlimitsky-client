<?php

define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/license.php';

USK_License::sync_presence_with_vendor(false);
$before = USK_License::get();
USK_License::refresh_from_vendor(true);
$after = USK_License::get();

$report = array(
    'ran_at' => date('c'),
    'was_pro' => (($before['tier'] ?? 'free') === 'pro'),
    'is_pro' => USK_License::is_pro(),
    'max_plans' => USK_License::max_plans(),
    'plan_count' => USK_License::current_plan_count(),
);

$dir = USK_ROOT . '/admin/data';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
file_put_contents($dir . '/license-last-cron.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => true, 'report' => $report), JSON_UNESCAPED_UNICODE);
