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

function usk_services_sync_diag(array $report)
{
    $meta = $report['usage_meta'] ?? array();
    if (!is_array($meta)) {
        return '';
    }
    $synced = (int) ($meta['usage_synced'] ?? 0);
    $connSynced = (int) ($meta['connections_synced'] ?? 0);
    $parts = array();
    if ($connSynced > 0) {
        $parts[] = sprintf(__('services_sync_diag_connections'), $connSynced);
    }
    if ($synced > 0 && (int) ($report['usage_updated'] ?? 0) === 0) {
        $parts[] = sprintf(__('services_sync_diag_synced'), $synced);
    }
    if (empty($meta['sudo_ok']) && ($meta['source'] ?? '') !== 'collect_script') {
        $parts[] = __('services_sync_diag_no_sudo');
    } elseif (empty($meta['sudo_ok'])) {
        $parts[] = __('services_sync_diag_collect_failed');
    }
    if (!empty($meta['parse_ok']) && $meta['parse_ok'] === false) {
        $parts[] = __('services_sync_diag_collect_parse');
    }
    $xrayCfg = (int) ($meta['xray_cfg_clients'] ?? 0);
    $xrayUsers = (int) ($meta['xray_users'] ?? ($meta['map_counts']['xray'] ?? 0));
    if ($xrayCfg > 0 && $xrayUsers === 0) {
        $parts[] = __('services_sync_diag_xray_stats');
    }
    $ovpnStatusFiles = (int) ($meta['ovpn_status_files'] ?? 0);
    $ovpnUsers = (int) ($meta['ovpn_users'] ?? ($meta['map_counts']['openvpn'] ?? 0));
    if ($ovpnStatusFiles === 0 && $ovpnUsers === 0 && (int) ($report['checked'] ?? 0) > 0) {
        $parts[] = __('services_sync_diag_openvpn_status');
    }
    if (!empty($meta['node_errors']) && is_array($meta['node_errors'])) {
        $parts[] = sprintf(__('services_sync_diag_node_errors'), count($meta['node_errors']));
    }
    if ($parts === array()) {
        return '';
    }
    return ' ' . implode(' ', $parts);
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
    ) . usk_services_sync_diag($report));
} catch (Throwable $e) {
    error_log('USK services sync_usage: ' . $e->getMessage());
    usk_flash(__('services_sync_failed'), 'error');
}

usk_services_redirect($params);
