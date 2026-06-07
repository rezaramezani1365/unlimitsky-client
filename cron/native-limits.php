<?php

define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/protocols/manager.php';
require_once USK_ROOT . '/admin/lib/protocols/limits.php';

try {
    $report = USK_ProtocolLimits::enforce_all();
    USK_ProtocolLimits::save_last_run($report);
} catch (Throwable $e) {
    $report = array(
        'ok' => false,
        'error' => $e->getMessage(),
        'usage_updated' => 0,
        'checked' => 0,
        'disabled' => 0,
        'details' => array(),
    );
    error_log('USK native-limits: ' . $e->getMessage());
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(empty($report['error']) ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => empty($report['error']), 'report' => $report), JSON_UNESCAPED_UNICODE);
