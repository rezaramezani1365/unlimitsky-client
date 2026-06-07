<?php

class USK_ProtocolLimits
{
    private static function clients_dir()
    {
        return USK_ROOT . '/data/clients';
    }

    public static function vpn_data_root()
    {
        $candidates = array(
            getenv('USK_DATA_ROOT') ?: '',
            '/var/lib/unlimitsky',
        );
        foreach ($candidates as $root) {
            $root = rtrim(trim((string) $root), '/');
            if ($root !== '' && is_dir($root)) {
                return $root;
            }
        }
        return '/var/lib/unlimitsky';
    }

    private static function registry_clients_file($protocol)
    {
        return self::vpn_data_root() . '/' . $protocol . '/clients.json';
    }

    /** Shell provisioning registry (/var/lib/unlimitsky/{protocol}/clients.json). */
    public static function load_registry_clients($protocol)
    {
        $file = self::registry_clients_file($protocol);
        if (!is_file($file)) {
            return array();
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return array();
        }
        return self::normalize_clients($data);
    }

    private static function merge_client_records(array $panel, array $registry)
    {
        foreach ($registry as $username => $rec) {
            if (!is_array($rec)) {
                continue;
            }
            if (!isset($panel[$username]) || !is_array($panel[$username])) {
                $panel[$username] = $rec;
                continue;
            }
            $panel[$username] = array_merge($rec, $panel[$username]);
        }
        return $panel;
    }

    public static function load_protocol_clients($protocol)
    {
        $panel = array();
        $file = self::clients_dir() . '/' . $protocol . '.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $panel = self::normalize_clients($data);
            }
        }
        $registry = self::load_registry_clients($protocol);
        return self::merge_client_records($panel, $registry);
    }

    /** Accept legacy list-shaped JSON registries. */
    private static function normalize_clients(array $data)
    {
        if ($data === array()) {
            return array();
        }
        $keys = array_keys($data);
        if ($keys !== range(0, count($data) - 1)) {
            return $data;
        }
        $out = array();
        foreach ($data as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $username = trim((string) ($rec['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            $out[$username] = $rec;
        }
        return $out;
    }

    public static function save_protocol_clients($protocol, array $clients)
    {
        $dir = self::clients_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/' . $protocol . '.json',
            json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Sync live usage from VPN daemons, then disable expired / over-volume clients.
     * Called from admin button (no cron required) or cron/native-limits.php.
     *
     * @return array{usage_updated:int,checked:int,disabled:int,details:array}
     */
    public static function enforce_all()
    {
        require_once __DIR__ . '/usage.php';
        $usageUpdated = USK_ProtocolUsage::sync_all();
        $usageMeta = USK_ProtocolUsage::last_collect_meta();

        $report = array(
            'usage_updated' => (int) $usageUpdated,
            'usage_meta' => is_array($usageMeta) ? $usageMeta : array(),
            'checked' => 0,
            'disabled' => 0,
            'details' => array(),
        );

        foreach (array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco', 'amnezia') as $protocol) {
            $clients = self::load_protocol_clients($protocol);
            foreach ($clients as $username => $rec) {
                if (!is_array($rec) || !self::is_active_status($rec['status'] ?? 'active')) {
                    continue;
                }
                $report['checked']++;

                $reason = self::check_client($protocol, $username, $rec);
                if ($reason) {
                    self::disable_client($protocol, $username, $reason);
                    $report['disabled']++;
                    $report['details'][] = array(
                        'protocol' => $protocol,
                        'username' => $username,
                        'reason' => $reason,
                    );
                }
            }
        }

        self::sync_orders_status();
        return $report;
    }

    /** Enforce plan max_connections (concurrent session slots). */
    public static function enforce_connection_limits()
    {
        require_once __DIR__ . '/connections.php';
        return USK_ProtocolConnections::enforce_all();
    }

    public static function enforce_all_with_connections()
    {
        $report = self::enforce_all();
        $conn = self::enforce_connection_limits();
        $report['connections'] = is_array($conn) ? $conn : array('ok' => false);
        $report['connections_trimmed'] = (int) ($conn['trimmed'] ?? ($conn['connections_trimmed'] ?? 0));
        if (!empty($conn['details']) && is_array($conn['details'])) {
            $report['details'] = array_merge($report['details'] ?? array(), $conn['details']);
        }
        return $report;
    }

    /** Admin button — prefer CLI worker (no PHP-FPM timeout); fallback to in-process. */
    public static function sync_usage_and_enforce()
    {
        $report = self::run_limits_via_cli();
        if (is_array($report)) {
            return $report;
        }

        $report = self::enforce_all_with_connections();
        self::save_last_run($report);
        return $report;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function run_limits_via_cli()
    {
        $script = USK_ROOT . '/cron/native-limits.php';
        if (!is_file($script)) {
            return null;
        }

        $php = self::php_cli_bin();
        $cmd = 'timeout 240 ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $raw = @shell_exec($cmd);
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $report = json_decode($raw, true);
        if (!is_array($report) && preg_match('/(\{.*\})/s', $raw, $m)) {
            $report = json_decode($m[1], true);
        }
        if (!is_array($report)) {
            return null;
        }
        if (!empty($report['error'])) {
            return null;
        }

        if (!isset($report['usage_updated'])) {
            $report['usage_updated'] = 0;
        }

        return $report;
    }

    private static function php_cli_bin()
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos(PHP_BINARY, 'fpm') === false) {
            return PHP_BINARY;
        }
        $which = trim((string) @shell_exec('command -v php 2>/dev/null'));
        return $which !== '' ? $which : 'php';
    }

    private static function sudo_script_cmd($script, array $args = array())
    {
        $parts = array('sudo', '-n', '/bin/bash', escapeshellarg($script));
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        return implode(' ', $parts) . ' 2>&1';
    }

    public static function format_last_run_at($iso)
    {
        $iso = trim((string) $iso);
        if ($iso === '') {
            return '';
        }
        $ts = strtotime($iso);
        if (!$ts) {
            return $iso;
        }
        return date('Y-m-d H:i', $ts);
    }

    public static function client_meta(array $rec)
    {
        $meta = is_array($rec['meta'] ?? null) ? $rec['meta'] : array();
        return array_merge($meta, $rec);
    }

    public static function is_active_status($status)
    {
        return in_array($status, array('active'), true);
    }

    private static function check_client($protocol, $username, array $rec)
    {
        if (!empty($rec['expires_at'])) {
            $exp = strtotime($rec['expires_at']);
            if ($exp && $exp < time()) {
                return 'expired';
            }
        }

        if (!empty($rec['volume_gb']) && (int) $rec['volume_gb'] > 0) {
            $used = (int) ($rec['usage_bytes'] ?? 0);
            $limit = (int) $rec['volume_gb'] * 1024 * 1024 * 1024;
            if ($used >= $limit) {
                return 'volume_exceeded';
            }
        }

        return null;
    }

    public static function wireguard_usage_bytes(array $rec)
    {
        $pub = $rec['public_key'] ?? null;
        if (!$pub && !empty($rec['meta']['public_key'])) {
            $pub = $rec['meta']['public_key'];
        }
        if (!$pub) {
            return null;
        }

        $raw = @shell_exec('wg show wg0 dump 2>/dev/null');
        if (!$raw) {
            return null;
        }

        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[0] === $pub) {
                return (int) $parts[5] + (int) $parts[6];
            }
        }

        return null;
    }

    public static function format_bytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return $bytes . ' B';
    }

    /** Disable on server — user cannot connect; record kept for reseller. */
    public static function disable_client($protocol, $username, $reason)
    {
        $status = ($reason === 'volume_exceeded') ? 'volume_exceeded' : 'expired';

        $clients = self::load_protocol_clients($protocol);
        if (!isset($clients[$username])) {
            return;
        }

        self::run_disable_script($protocol, $username, $clients[$username]);

        $clients[$username]['status'] = $status;
        $clients[$username]['disabled_at'] = date('c');
        $clients[$username]['disable_reason'] = $reason;
        self::save_protocol_clients($protocol, $clients);

        self::update_orders_by_client($protocol, $username, $status);
    }

    public static function enable_client($protocol, $username)
    {
        $clients = self::load_protocol_clients($protocol);
        if (!isset($clients[$username])) {
            return array('ok' => false, 'error' => 'client_not_found');
        }

        $rec = $clients[$username];
        $new_config = self::run_enable_script($protocol, $username, $rec);

        if (is_array($new_config) && !empty($new_config['config'])) {
            $clients[$username]['meta'] = array_merge($clients[$username]['meta'] ?? array(), $new_config);
            if (!empty($new_config['config'])) {
                self::update_orders_link($protocol, $username, $new_config['config']);
            }
        }

        return array('ok' => true);
    }

    private static function run_disable_script($protocol, $username, array $rec)
    {
        $m = self::client_meta($rec);
        $script = USK_ROOT . '/bin/disable-user-' . $protocol . '.sh';
        if (!file_exists($script)) {
            return;
        }

        $cmd = self::sudo_script_cmd($script, array($username));
        if ($protocol === 'wireguard') {
            $cmd = self::sudo_script_cmd($script, array($username, (string) ($m['public_key'] ?? '')));
        } elseif ($protocol === 'amnezia') {
            $cmd = self::sudo_script_cmd($script, array($username, (string) ($m['public_key'] ?? '')));
        } elseif ($protocol === 'xray') {
            $cmd = self::sudo_script_cmd($script, array($username, (string) ($m['uuid'] ?? '')));
        }
        @shell_exec($cmd);
    }

    private static function run_enable_script($protocol, $username, array $rec)
    {
        $m = self::client_meta($rec);
        $script = USK_ROOT . '/bin/enable-user-' . $protocol . '.sh';
        if (!file_exists($script)) {
            return null;
        }

        $args = array($username);
        if ($protocol === 'wireguard' || $protocol === 'amnezia') {
            $args[] = (string) ($m['public_key'] ?? '');
            $args[] = (string) ($m['client_ip'] ?? '');
        } elseif ($protocol === 'xray') {
            $args[] = (string) ($m['uuid'] ?? '');
        } elseif ($protocol === 'l2tp' || $protocol === 'cisco') {
            $args[] = (string) ($m['password'] ?? '');
        }

        $out = @shell_exec(self::sudo_script_cmd($script, $args));
        if (($protocol === 'openvpn' || $protocol === 'cisco') && $out && preg_match('/USK_JSON:(.+)$/s', $out, $match)) {
            $data = json_decode(trim($match[1]), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    private static function update_orders_link($protocol, $username, $link)
    {
        global $sql;
        if (!$sql instanceof mysqli) {
            return;
        }
        $clients = self::load_protocol_clients($protocol);
        $rec = $clients[$username] ?? array();
        $link_esc = $sql->real_escape_string($link);
        $p = $sql->real_escape_string($protocol);
        $u = $sql->real_escape_string($username);
        $code = $sql->real_escape_string((string) ($rec['order_code'] ?? ''));
        $wc = (int) ($rec['wc_order_id'] ?? 0);
        $where = "(`from_id`='native-$u'";
        if ($code !== '') {
            $where .= " OR `code`='$code'";
        }
        if ($wc > 0) {
            $where .= " OR `from_id`='wc-$wc'";
        }
        $where .= ')';
        $sql->query("UPDATE `orders` SET `link`='$link_esc' WHERE `protocol`='$p' AND `type`='native' AND $where");
    }

    /** @deprecated */
    public static function mark_limit($protocol, $username, $reason)
    {
        self::disable_client($protocol, $username, $reason);
    }

    /** Remove VPN user from server (manual action by reseller). */
    public static function remove_from_server($protocol, $username)
    {
        $script = USK_ROOT . '/bin/remove-user-' . $protocol . '.sh';
        if (file_exists($script)) {
            $uuid = '';
            if ($protocol === 'xray') {
                $clients = self::load_protocol_clients($protocol);
                $uuid = $clients[$username]['meta']['uuid'] ?? ($clients[$username]['uuid'] ?? '');
            }
            $args = array($username);
            if ($protocol === 'xray' && $uuid !== '') {
                $args[] = $uuid;
            }
            @shell_exec(self::sudo_script_cmd($script, $args));
        }

        $clients = self::load_protocol_clients($protocol);
        if (isset($clients[$username])) {
            $clients[$username]['status'] = 'revoked';
            $clients[$username]['revoked_at'] = date('c');
            $clients[$username]['revoke_reason'] = 'manual';
            self::save_protocol_clients($protocol, $clients);
        }

        self::update_orders_by_client($protocol, $username, 'revoked');
    }

    /** Extend / renew — reseller adds days and/or GB, service active again. */
    public static function extend_client($protocol, $username, $extra_days = 0, $extra_gb = 0)
    {
        $clients = self::load_protocol_clients($protocol);
        if (!isset($clients[$username])) {
            return array('ok' => false, 'error' => 'client_not_found');
        }

        $rec = $clients[$username];
        $extra_days = max(0, (int) $extra_days);
        $extra_gb = max(0, (int) $extra_gb);

        if ($extra_days > 0) {
            $base = !empty($rec['expires_at']) ? strtotime($rec['expires_at']) : time();
            if ($base < time()) {
                $base = time();
            }
            $rec['expires_at'] = date('c', $base + ($extra_days * 86400));
            $rec['duration_days'] = (int) ($rec['duration_days'] ?? 0) + $extra_days;
        }

        if ($extra_gb > 0) {
            $rec['volume_gb'] = (int) ($rec['volume_gb'] ?? 0) + $extra_gb;
        }

        $rec['status'] = 'active';
        unset($rec['disabled_at'], $rec['disable_reason'], $rec['marked_at'], $rec['mark_reason']);
        $rec['extended_at'] = date('c');

        $clients[$username] = $rec;
        self::save_protocol_clients($protocol, $clients);

        self::enable_client($protocol, $username);

        $clients = self::load_protocol_clients($protocol);
        $rec = $clients[$username] ?? $rec;

        global $sql;
        if ($sql instanceof mysqli) {
            $u = $sql->real_escape_string($username);
            $p = $sql->real_escape_string($protocol);
            $vol = $sql->real_escape_string((string) $rec['volume_gb']);
            $days = $sql->real_escape_string((string) $rec['duration_days']);
            $code = $sql->real_escape_string((string) ($rec['order_code'] ?? ''));
            $wc = (int) ($rec['wc_order_id'] ?? 0);
            $where = "(`from_id`='native-$u'";
            if ($code !== '') {
                $where .= " OR `code`='$code'";
            }
            if ($wc > 0) {
                $where .= " OR `from_id`='wc-$wc'";
            }
            $where .= ')';
            $sql->query("UPDATE `orders` SET `status`='active', `volume`='$vol', `date`='$days' WHERE `protocol`='$p' AND `type`='native' AND $where");
        }

        return array('ok' => true, 'client' => $rec);
    }

    public static function find_client_for_order(array $order)
    {
        if (($order['type'] ?? '') !== 'native') {
            return null;
        }
        $protocol = $order['protocol'] ?? '';
        if ($protocol === '') {
            return null;
        }

        $clients = self::load_protocol_clients($protocol);
        $code = $order['code'] ?? '';

        foreach ($clients as $username => $rec) {
            if (is_array($rec) && ($rec['order_code'] ?? '') === $code) {
                return array('username' => $username, 'protocol' => $protocol, 'client' => $rec);
            }
        }

        $from = $order['from_id'] ?? '';
        if (strpos($from, 'wc-') === 0) {
            $wc_id = (int) substr($from, 3);
            foreach ($clients as $username => $rec) {
                if (is_array($rec) && (int) ($rec['wc_order_id'] ?? 0) === $wc_id) {
                    return array('username' => $username, 'protocol' => $protocol, 'client' => $rec);
                }
            }
        }

        if (strpos($from, 'native-') === 0) {
            $username = substr($from, 7);
            if (isset($clients[$username])) {
                return array('username' => $username, 'protocol' => $protocol, 'client' => $clients[$username]);
            }
        }

        return null;
    }

    private static function update_orders_by_client($protocol, $username, $status)
    {
        global $sql;
        if (!$sql instanceof mysqli) {
            return;
        }
        $clients = self::load_protocol_clients($protocol);
        $rec = $clients[$username] ?? array();
        $u = $sql->real_escape_string($username);
        $p = $sql->real_escape_string($protocol);
        $st = $sql->real_escape_string($status);
        $code = $sql->real_escape_string((string) ($rec['order_code'] ?? ''));
        $wc = (int) ($rec['wc_order_id'] ?? 0);
        $where = "(`from_id`='native-$u'";
        if ($code !== '') {
            $where .= " OR `code`='$code'";
        }
        if ($wc > 0) {
            $where .= " OR `from_id`='wc-$wc'";
        }
        $where .= ')';
        $sql->query("UPDATE `orders` SET `status`='$st' WHERE `protocol`='$p' AND `type`='native' AND $where");
    }

    private static function sync_orders_status()
    {
        global $sql;
        if (!$sql instanceof mysqli) {
            return;
        }

        $res = $sql->query("SELECT * FROM `orders` WHERE `type`='native' AND `status`='active'");
        if (!$res) {
            return;
        }

        while ($row = $res->fetch_assoc()) {
            $info = self::find_client_for_order($row);
            if (!$info) {
                continue;
            }
            $reason = self::check_client($info['protocol'], $info['username'], $info['client']);
            if ($reason) {
                self::disable_client($info['protocol'], $info['username'], $reason);
            }
        }
    }

    public static function last_run_file()
    {
        return USK_ROOT . '/data/clients/limits-last-run.json';
    }

    public static function save_last_run(array $report)
    {
        $dir = self::clients_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $report['ran_at'] = date('c');
        file_put_contents(self::last_run_file(), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function get_last_run()
    {
        $f = self::last_run_file();
        if (!file_exists($f)) {
            return null;
        }
        return json_decode(file_get_contents($f), true);
    }

    /** @deprecated use remove_from_server */
    public static function revoke($protocol, $username, $reason = 'manual')
    {
        self::remove_from_server($protocol, $username);
    }
}
