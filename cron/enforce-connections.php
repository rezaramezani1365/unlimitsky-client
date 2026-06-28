<?php

define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/protocols/manager.php';
require_once USK_ROOT . '/admin/lib/protocols/limits.php';
require_once USK_ROOT . '/admin/lib/protocols/connections.php';

try {
    $report = USK_ProtocolLimits::enforce_connection_limits();
    USK_ProtocolConnections::save_last_run(is_array($report) ? $report : array('ok' => false));
} catch (Throwable $e) {
    $report = array(
        'ok' => false,
        'error' => $e->getMessage(),
        'checked' => 0,
        'trimmed' => 0,
        'connections_trimmed' => 0,
        'details' => array(),
    );
    error_log('USK enforce-connections: ' . $e->getMessage());
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(empty($report['error']) ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => empty($report['error']), 'report' => $report), JSON_UNESCAPED_UNICODE);
