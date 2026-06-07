<?php

require_once __DIR__ . '/service-config-view.php';
require_once __DIR__ . '/protocols/limits.php';
require_once __DIR__ . '/protocols/usage.php';
require_once __DIR__ . '/live-stats.php';

class USK_ServiceStats
{
    /** @return array<string, mixed>|null */
    public static function item_from_order(array $order, $client = null)
    {
        if ($client === null) {
            $native = USK_ProtocolLimits::find_client_for_order($order);
            $client = $native['client'] ?? null;
        }
        if (!$client || !is_array($client)) {
            return null;
        }

        $volumeGb = (int) ($order['volume'] ?? 0);
        $protocol = (string) ($order['protocol'] ?? '');
        $usage = USK_ProtocolUsage::usage_stats($protocol, $client, $volumeGb);
        $row = usk_service_list_row($order);

        return array(
            'code' => (string) ($order['code'] ?? ''),
            'usage_display' => $row['usage_display'],
            'usage_percent' => $row['usage_percent'],
            'usage_needs_sync' => !empty($row['usage_needs_sync']),
            'used_gb' => isset($usage['used_gb']) ? (float) $usage['used_gb'] : null,
            'remaining_gb' => $usage['remaining_gb'] !== null ? (float) $usage['remaining_gb'] : null,
            'limit_gb' => $volumeGb,
            'synced_at' => (string) ($usage['synced_at'] ?? ($client['usage_synced_at'] ?? '')),
            'status' => (string) ($order['status'] ?? ''),
            'badge_class' => $row['badge_class'],
            'badge_label' => $row['badge_label'],
            'tracked' => !empty($usage['tracked']),
            'connections_display' => $row['connections_display'],
            'connections_near_limit' => !empty($row['connections_near_limit']),
            'connections_warning' => !empty($row['connections_warning']),
            'active_connections' => (int) ($usage['active_connections'] ?? 0),
            'max_connections' => (int) ($usage['max_connections'] ?? 1),
        );
    }

    /**
     * @param array<int, string> $codes
     * @return array{ok:bool, items:array<string, array<string, mixed>>}
     */
    public static function for_codes(array $codes)
    {
        global $sql;

        $codes = array_values(array_unique(array_filter(array_map(function ($c) {
            return preg_replace('/[^0-9]/', '', (string) $c);
        }, $codes))));
        if ($codes === array()) {
            return array('ok' => true, 'items' => array());
        }
        if (count($codes) > 200) {
            $codes = array_slice($codes, 0, 200);
        }

        $items = array();
        foreach ($codes as $code) {
            $code_esc = $sql->real_escape_string($code);
            $order = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1")->fetch_assoc();
            if (!$order) {
                continue;
            }
            $item = self::item_from_order($order);
            if ($item !== null) {
                $items[$code] = $item;
            }
        }

        return array('ok' => true, 'items' => $items, 'server_time' => date('c'), 'live' => self::live_meta());
    }

    /** @return array<string, mixed> */
    public static function for_portal($code, $token)
    {
        require_once __DIR__ . '/customer-portal.php';

        $code = preg_replace('/[^0-9]/', '', (string) $code);
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $token));
        if ($code === '' || $token === '') {
            return array('ok' => false, 'error' => 'invalid_request');
        }

        $view = USK_CustomerPortal::load($code, $token);
        if (empty($view['ok'])) {
            return array('ok' => false, 'error' => $view['error'] ?? 'not_found');
        }

        $usage = $view['usage'] ?? array();
        $volumeGb = (int) ($view['volume_gb'] ?? 0);

        return array(
            'ok' => true,
            'code' => $code,
            'service_status' => (string) ($view['service_status'] ?? 'active'),
            'used_gb' => isset($usage['used_gb']) ? (float) $usage['used_gb'] : 0,
            'remaining_gb' => $usage['remaining_gb'] !== null ? (float) $usage['remaining_gb'] : null,
            'limit_gb' => $volumeGb,
            'percent' => $usage['percent'] !== null ? (float) $usage['percent'] : null,
            'needs_sync' => !empty($usage['needs_sync']),
            'synced_at' => (string) ($usage['synced_at'] ?? ''),
            'tracked' => !empty($usage['tracked']),
            'connections_display' => (string) ($usage['connections_display'] ?? ''),
            'connections_near_limit' => !empty($usage['connections_near_limit']),
            'connections_warning' => !empty($usage['connections_warning']),
            'active_connections' => (int) ($usage['active_connections'] ?? 0),
            'max_connections' => (int) ($usage['max_connections'] ?? ($view['max_connections'] ?? 1)),
            'server_time' => date('c'),
            'live' => self::live_meta(),
        );
    }

    /** @return array<string, mixed> */
    private static function live_meta()
    {
        require_once __DIR__ . '/usage-sync-settings.php';
        $cfg = USK_UsageSyncSettings::get();
        return array(
            'interval_minutes' => (int) ($cfg['interval_minutes'] ?? 5),
            'cache_age_sec' => USK_LiveStats::cache_age_sec(),
            'cache_fresh' => USK_LiveStats::is_fresh(),
        );
    }
}
