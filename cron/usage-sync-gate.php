<?php

/**
 * Lightweight cron entry (every minute). Runs heavy native-limits only when interval elapsed.
 */
define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/usage-sync-settings.php';

$report = array('ok' => true, 'skipped' => true, 'reason' => 'not_due');

if (!USK_UsageSyncSettings::is_due()) {
    if (php_sapi_name() === 'cli') {
        exit(0);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE);
    exit;
}

$lockFp = USK_UsageSyncSettings::acquire_lock();
if ($lockFp === false) {
    $report = array('ok' => true, 'skipped' => true, 'reason' => 'already_running');
    if (php_sapi_name() === 'cli') {
        exit(0);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!USK_UsageSyncSettings::is_due()) {
        $report = array('ok' => true, 'skipped' => true, 'reason' => 'not_due');
    } else {
        $report = USK_UsageSyncSettings::run_sync_job();
        $report['ok'] = empty($report['error']);
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
    error_log('USK usage-sync-gate: ' . $e->getMessage());
} finally {
    USK_UsageSyncSettings::release_lock($lockFp);
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($report['error']) && empty($report['skipped']) ? 1 : 0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_UNESCAPED_UNICODE);
