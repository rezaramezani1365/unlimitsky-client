<?php

define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/protocols/manager.php';
require_once USK_ROOT . '/admin/lib/protocols/limits.php';

$report = USK_ProtocolLimits::enforce_all();
USK_ProtocolLimits::save_last_run($report);

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => true, 'report' => $report), JSON_UNESCAPED_UNICODE);
