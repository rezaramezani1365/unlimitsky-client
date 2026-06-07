<?php

/** Manual / forced sync — ignores interval (still uses flock). */
define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/usage-sync-settings.php';

$lockFp = USK_UsageSyncSettings::acquire_lock();
if ($lockFp === false) {
    $report = array('ok' => true, 'skipped' => true, 'error' => 'already_running', 'usage_updated' => 0, 'checked' => 0, 'disabled' => 0, 'details' => array());
} else {
    try {
        $report = USK_UsageSyncSettings::run_sync_job();
        $report['ok'] = empty($report['error']);
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
        USK_UsageSyncSettings::release_lock($lockFp);
    }
}

if (php_sapi_name() === 'cli') {
    echo json_encode($report, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(!empty($report['error']) && empty($report['skipped']) ? 1 : 0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => empty($report['error']) || !empty($report['skipped']), 'report' => $report), JSON_UNESCAPED_UNICODE);
