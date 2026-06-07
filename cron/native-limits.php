<?php

define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/protocols/manager.php';
require_once USK_ROOT . '/admin/lib/protocols/limits.php';

$lockPath = USK_ROOT . '/data/live/native-limits.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockFp = @fopen($lockPath, 'c');
if ($lockFp === false) {
    $report = array('ok' => false, 'error' => 'lock_open_failed', 'usage_updated' => 0, 'checked' => 0, 'disabled' => 0, 'details' => array());
} elseif (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    $report = array('ok' => true, 'skipped' => true, 'error' => 'already_running', 'usage_updated' => 0, 'checked' => 0, 'disabled' => 0, 'details' => array());
} else {
    try {
        $report = USK_ProtocolLimits::enforce_all_with_connections();
        USK_ProtocolLimits::save_last_run($report);
        require_once USK_ROOT . '/admin/lib/protocols/connections.php';
        if (!empty($report['connections']) && is_array($report['connections'])) {
            USK_ProtocolConnections::save_last_run($report['connections']);
        }
        $forceFlag = USK_ROOT . '/data/live/request-sync.flag';
        if (is_file($forceFlag)) {
            @unlink($forceFlag);
        }
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
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($report['error']) && empty($report['skipped']) ? 1 : 0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => empty($report['error']) || !empty($report['skipped']), 'report' => $report), JSON_UNESCAPED_UNICODE);
