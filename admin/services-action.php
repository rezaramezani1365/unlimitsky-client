<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/protocols/limits.php';

USK_Admin_Auth::boot();
USK_Admin_Auth::require_login();

function usk_services_redirect(array $params = array())
{
    header('Location: ' . usk_admin_url('services', $params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    usk_services_redirect();
}

$action = (string) ($_POST['action'] ?? '');
if ($action !== 'sync_usage') {
    usk_services_redirect();
}

$returnFilter = preg_replace('/[^a-z_]/', '', (string) ($_POST['filter'] ?? 'all'));
$params = array();
if ($returnFilter !== '' && $returnFilter !== 'all') {
    $params['filter'] = $returnFilter;
}
$returnView = (int) ($_POST['view'] ?? 0);
if ($returnView > 0) {
    $params['view'] = $returnView;
}

try {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    if (!method_exists('USK_ProtocolLimits', 'sync_usage_and_enforce')) {
        throw new RuntimeException('sync_not_available');
    }

    $report = USK_ProtocolLimits::sync_usage_and_enforce();
    if (!is_array($report)) {
        throw new RuntimeException('sync_empty_report');
    }

    usk_flash(sprintf(
        __('services_sync_ok'),
        (int) ($report['usage_updated'] ?? 0),
        (int) ($report['checked'] ?? 0),
        (int) ($report['disabled'] ?? 0)
    ));
} catch (Throwable $e) {
    error_log('USK services sync_usage: ' . $e->getMessage());
    usk_flash(__('services_sync_failed'), 'error');
}

usk_services_redirect($params);
