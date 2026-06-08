<?php

class USK_ProtocolUsage
{
    /** @var array<string, array<string, int>>|null */
    private static $batchCache = null;

    /** @var array<string, mixed> */
    private static $lastCollectMeta = array();

    /** @var array<string, string>|null uuid => email */
    private static $xrayUuidEmailCache = null;

    public static function last_collect_meta()
    {
        return self::$lastCollectMeta;
    }

    /**
     * @param bool $preferLive When false (web UI), use last cron-synced usage_bytes only.
     */
    public static function client_usage_bytes($protocol, array $rec, $preferLive = false)
    {
        if (!$preferLive) {
            if (isset($rec['usage_bytes'])) {
                return (int) $rec['usage_bytes'];
            }
            if (isset($rec['meta']['usage_bytes'])) {
                return (int) $rec['meta']['usage_bytes'];
            }
            return 0;
        }

        $protocol = (string) $protocol;
        $live = null;

        if ($protocol === 'wireguard') {
            $live = USK_ProtocolLimits::wireguard_usage_bytes($rec);
        } elseif ($protocol === 'amnezia') {
            $live = self::amnezia_usage_bytes($rec);
        } elseif ($protocol === 'xray') {
            $live = self::xray_usage_bytes($rec);
        } elseif ($protocol === 'openvpn') {
            $live = self::openvpn_usage_bytes($rec);
        }

        if ($live !== null) {
            if (self::is_cumulative_counter_protocol($protocol)) {
                return self::cumulative_total($rec, (int) $live);
            }
            return (int) $live;
        }

        if (isset($rec['usage_bytes'])) {
            return (int) $rec['usage_bytes'];
        }
        if (isset($rec['meta']['usage_bytes'])) {
            return (int) $rec['meta']['usage_bytes'];
        }

        return 0;
    }

    public static function usage_stats($protocol, array $rec, $volumeGb)
    {
        $volumeGb = (int) $volumeGb;
        $protocol = (string) $protocol;
        $usedBytes = self::client_usage_bytes($protocol, $rec, false);
        $limitBytes = $volumeGb > 0 ? ($volumeGb * 1073741824) : 0;
        $usedGb = round($usedBytes / 1073741824, 2);
        $remainingGb = $volumeGb > 0 ? max(0, round($volumeGb - $usedGb, 2)) : null;
        $percent = ($volumeGb > 0) ? min(100, round(($usedGb / $volumeGb) * 100, 1)) : null;
        $metered = in_array($protocol, array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco'), true);
        $syncedAt = trim((string) ($rec['usage_synced_at'] ?? ($rec['meta']['usage_synced_at'] ?? '')));

        require_once __DIR__ . '/connections.php';
        $maxConn = USK_ProtocolConnections::max_connections_for($rec);
        $connTracked = in_array($protocol, array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco'), true);
        $slotsLabel = $connTracked ? USK_ProtocolConnections::slots_label_for($rec, $protocol) : null;

        return array(
            'tracked' => $metered,
            'needs_sync' => $metered && $syncedAt === '' && $volumeGb > 0,
            'synced_at' => $syncedAt,
            'used_bytes' => $usedBytes,
            'used_gb' => $usedGb,
            'remaining_gb' => $remainingGb,
            'limit_gb' => $volumeGb,
            'percent' => $percent,
            'exceeded' => $limitBytes > 0 && $usedBytes >= $limitBytes,
            'used_label' => USK_ProtocolLimits::format_bytes($usedBytes),
            'max_connections' => $maxConn,
            'active_connections' => 0,
            'connections_synced_at' => '',
            'connections_tracked' => $connTracked,
            'connections_display' => $slotsLabel,
            'connections_near_limit' => false,
            'connections_warning' => false,
        );
    }

    /** Cron-only: batch-read live stats and persist to client JSON files. */
    public static function sync_all()
    {
        self::$batchCache = null;
        self::$lastCollectMeta = array();
        $maps = self::batch_usage_maps();
        self::$lastCollectMeta['map_counts'] = array(
            'wireguard' => count($maps['wireguard'] ?? array()),
            'amnezia' => count($maps['amnezia'] ?? array()),
            'xray' => count($maps['xray'] ?? array()),
            'openvpn' => count($maps['openvpn'] ?? array()),
            'cisco' => count($maps['cisco'] ?? array()),
            'l2tp' => count($maps['l2tp'] ?? array()),
        );
        $connMaps = $maps['_connections'] ?? array();

        $updated = 0;
        $synced = 0;
        $connUpdated = 0;
        $panelCounts = array();
        $matchFailures = array();
        foreach (array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco') as $protocol) {
            $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
            $panelCounts[$protocol] = count($clients);
            $changed = false;
            foreach ($clients as $username => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                if ($protocol === 'openvpn') {
                    $ovpn = self::sync_openvpn_client($username, $rec, $maps['openvpn'] ?? array());
                    if ($ovpn === null) {
                        continue;
                    }
                    $prev = self::record_usage_bytes($rec);
                    $newBytes = (int) $ovpn['usage_bytes'];
                    $clients[$username]['usage_bytes'] = $newBytes;
                    $clients[$username]['ovpn_session_bytes'] = (int) $ovpn['ovpn_session_bytes'];
                    $connActive = self::connections_from_maps($protocol, $username, $rec, $connMaps);
                    if ($connActive !== null) {
                        $prevConn = (int) ($rec['active_connections'] ?? 0);
                        if (self::should_persist_connections($rec, $connActive, $prevConn)) {
                            $clients[$username]['active_connections'] = $connActive;
                            $clients[$username]['connections_synced_at'] = date('c');
                            $changed = true;
                            $connUpdated++;
                        }
                    }
                    if (self::should_persist_usage($rec, $newBytes, $prev)) {
                        $clients[$username]['usage_synced_at'] = date('c');
                        $changed = true;
                        $synced++;
                        if ($newBytes > $prev) {
                            $updated++;
                        }
                    } elseif ($newBytes !== $prev || !self::record_has_usage_bytes($rec)) {
                        $changed = true;
                    }
                    continue;
                }

                $bytes = self::bytes_from_maps($protocol, $username, $rec, $maps);
                $connActive = self::connections_from_maps($protocol, $username, $rec, $connMaps);
                if ($bytes === null && $connActive === null) {
                    if (count($matchFailures) < 8) {
                        $matchFailures[] = array(
                            'protocol' => $protocol,
                            'username' => (string) $username,
                            'uuid' => (string) ($rec['uuid'] ?? ($rec['meta']['uuid'] ?? '')),
                        );
                    }
                    continue;
                }

                $prev = self::record_usage_bytes($rec);
                if ($bytes !== null) {
                    if (self::is_cumulative_counter_protocol($protocol)) {
                        $acc = self::accumulate_cumulative_bytes($rec, (int) $bytes);
                        $newBytes = (int) $acc['usage_bytes'];
                        $newRaw = (int) $acc['usage_raw_bytes'];
                        $prevRaw = array_key_exists('usage_raw_bytes', $rec) ? (int) $rec['usage_raw_bytes'] : null;
                        $rawChanged = ($prevRaw === null) || ($prevRaw !== $newRaw);
                        if ($rawChanged || self::should_persist_usage($rec, $newBytes, $prev)) {
                            $clients[$username]['usage_bytes'] = $newBytes;
                            $clients[$username]['usage_raw_bytes'] = $newRaw;
                            $clients[$username]['usage_synced_at'] = date('c');
                            $changed = true;
                            $synced++;
                            if ($newBytes > $prev) {
                                $updated++;
                            }
                        }
                    } else {
                        $newBytes = self::merge_usage_bytes($protocol, $prev, (int) $bytes);
                        if (self::should_persist_usage($rec, $newBytes, $prev)) {
                            $clients[$username]['usage_bytes'] = $newBytes;
                            $clients[$username]['usage_synced_at'] = date('c');
                            $changed = true;
                            $synced++;
                            if ($newBytes > $prev) {
                                $updated++;
                            }
                        }
                    }
                }

                if ($connActive !== null) {
                    $prevConn = (int) ($rec['active_connections'] ?? 0);
                    if (self::should_persist_connections($rec, $connActive, $prevConn)) {
                        $clients[$username]['active_connections'] = $connActive;
                        $clients[$username]['connections_synced_at'] = date('c');
                        $changed = true;
                        $connUpdated++;
                    }
                }

                if ($bytes === null && $connActive !== null && self::collect_maps_ok()) {
                    $prev = self::record_usage_bytes($rec);
                    $newBytes = $prev;
                    if (self::should_persist_usage($rec, $newBytes, $prev) || empty($rec['usage_synced_at'])) {
                        $clients[$username]['usage_bytes'] = $newBytes;
                        $clients[$username]['usage_synced_at'] = date('c');
                        $changed = true;
                        $synced++;
                    }
                }
            }
            if ($changed) {
                USK_ProtocolLimits::save_protocol_clients($protocol, $clients);
            }
        }

        self::$lastCollectMeta['usage_synced'] = $synced;
        self::$lastCollectMeta['connections_synced'] = $connUpdated;
        self::$lastCollectMeta['panel_clients'] = $panelCounts;
        if ($matchFailures !== array()) {
            self::$lastCollectMeta['match_failures'] = $matchFailures;
        }
        self::$batchCache = null;
        return $updated;
    }

    private static function is_metered_protocol($protocol)
    {
        return in_array((string) $protocol, array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco'), true);
    }

    private static function collect_maps_ok()
    {
        if (self::$lastCollectMeta === array()) {
            return false;
        }
        if (empty(self::$lastCollectMeta['sudo_ok']) && (self::$lastCollectMeta['source'] ?? '') === 'collect_script') {
            return false;
        }
        if (isset(self::$lastCollectMeta['parse_ok']) && self::$lastCollectMeta['parse_ok'] === false) {
            return false;
        }
        return true;
    }

    /** Ensure every panel client has a map entry (0 bytes baseline) like collect-usage-stats.sh. */
    private static function expand_maps_with_panel_clients(array $maps)
    {
        if (!isset($maps['_connections']) || !is_array($maps['_connections'])) {
            $maps['_connections'] = array();
        }
        foreach (array('wireguard', 'amnezia', 'xray', 'openvpn', 'cisco', 'l2tp') as $cp) {
            if (!isset($maps['_connections'][$cp]) || !is_array($maps['_connections'][$cp])) {
                $maps['_connections'][$cp] = array();
            }
        }

        foreach (array('wireguard', 'amnezia', 'xray', 'openvpn', 'cisco', 'l2tp') as $protocol) {
            if (!isset($maps[$protocol]) || !is_array($maps[$protocol])) {
                $maps[$protocol] = array();
            }
            $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
            foreach ($clients as $username => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                if ($protocol === 'wireguard' || $protocol === 'amnezia') {
                    $pub = self::client_public_key($rec);
                    if ($pub !== '' && !array_key_exists($pub, $maps[$protocol])) {
                        $maps[$protocol][$pub] = 0;
                    }
                    continue;
                }
                foreach (self::usage_name_candidates($username, $rec) as $name) {
                    if ($name !== '' && !array_key_exists($name, $maps[$protocol])) {
                        $maps[$protocol][$name] = 0;
                    }
                }
            }
        }

        return $maps;
    }

    /** @return array<string, mixed> */
    private static function batch_usage_maps()
    {
        if (self::$batchCache !== null) {
            return self::$batchCache;
        }

        $fromSudo = self::batch_usage_maps_via_sudo();
        if (is_array($fromSudo)) {
            self::$batchCache = self::expand_maps_with_panel_clients(self::merge_node_usage_maps($fromSudo));
            return self::$batchCache;
        }

        self::$batchCache = self::expand_maps_with_panel_clients(array(
            'wireguard' => self::batch_wg_dump('wg0', 'wg'),
            'amnezia' => self::batch_wg_dump('awg0', 'awg'),
            'xray' => self::batch_xray_user_bytes(),
            'openvpn' => self::batch_openvpn_user_bytes(),
        ));
        if (self::$lastCollectMeta === array()) {
            self::$lastCollectMeta = array(
                'sudo_ok' => false,
                'source' => 'php_fallback',
                'wg_peers' => count(self::$batchCache['wireguard']),
                'xray_users' => count(self::$batchCache['xray']),
                'ovpn_users' => count(self::$batchCache['openvpn']),
            );
        }

        return self::$batchCache;
    }

    /** Merge live stats collected over SSH from Node VPS servers. */
    private static function merge_node_usage_maps(array $maps)
    {
        if (!is_file(USK_ROOT . '/admin/lib/nodes.php')) {
            return $maps;
        }
        require_once USK_ROOT . '/admin/lib/nodes.php';
        require_once USK_ROOT . '/admin/lib/node-ssh.php';
        if (!class_exists('USK_Nodes') || !class_exists('USK_NodeSsh') || !USK_NodeSsh::sshpass_available()) {
            return $maps;
        }

        $nodeClients = array();
        foreach (array('xray', 'wireguard', 'openvpn') as $protocol) {
            $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
            foreach ($clients as $username => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $nodeId = trim((string) ($rec['node_id'] ?? ''));
                if ($nodeId === '') {
                    continue;
                }
                if (!isset($nodeClients[$nodeId])) {
                    $nodeClients[$nodeId] = array();
                }
                $nodeClients[$nodeId][] = array('protocol' => $protocol, 'username' => $username, 'rec' => $rec);
            }
        }

        if ($nodeClients === array()) {
            return $maps;
        }

        if (!isset($maps['_connections']) || !is_array($maps['_connections'])) {
            $maps['_connections'] = array(
                'wireguard' => array(),
                'amnezia' => array(),
                'xray' => array(),
                'openvpn' => array(),
            );
        }

        $nodesFetched = 0;
        foreach ($nodeClients as $nodeId => $entries) {
            $node = USK_Nodes::get($nodeId);
            if (!$node) {
                continue;
            }
            $remote = USK_NodeSsh::run_script($node, 'collect-usage-stats.sh', array(), 90);
            if (empty($remote['ok'])) {
                self::$lastCollectMeta['node_errors'][$nodeId] = $remote['error'] ?? 'remote_failed';
                continue;
            }
            $log = (string) ($remote['log'] ?? '');
            if (!preg_match('/(\{.*"ok"\s*:\s*true.*\})/s', $log, $m)) {
                self::$lastCollectMeta['node_errors'][$nodeId] = 'invalid_json';
                continue;
            }
            $data = json_decode($m[1], true);
            if (!is_array($data) || empty($data['ok'])) {
                self::$lastCollectMeta['node_errors'][$nodeId] = 'parse_failed';
                continue;
            }
            $nodesFetched++;
            $remoteMaps = array(
                'wireguard' => self::normalize_int_map($data['wireguard'] ?? array()),
                'amnezia' => self::normalize_int_map($data['amnezia'] ?? array()),
                'xray' => self::normalize_int_map($data['xray'] ?? array()),
                'openvpn' => self::normalize_int_map($data['openvpn'] ?? array()),
            );
            $remoteConn = array(
                'wireguard' => self::normalize_int_map($data['connections']['wireguard'] ?? array()),
                'amnezia' => self::normalize_int_map($data['connections']['amnezia'] ?? array()),
                'xray' => self::normalize_int_map($data['connections']['xray'] ?? array()),
                'openvpn' => self::normalize_int_map($data['connections']['openvpn'] ?? array()),
            );

            foreach ($entries as $entry) {
                $protocol = $entry['protocol'];
                $username = $entry['username'];
                $rec = $entry['rec'];
                if ($protocol === 'wireguard') {
                    $pub = $rec['public_key'] ?? ($rec['meta']['public_key'] ?? '');
                    if ($pub !== '' && isset($remoteMaps['wireguard'][$pub])) {
                        $maps['wireguard'][$pub] = (int) $remoteMaps['wireguard'][$pub];
                        if (isset($remoteConn['wireguard'][$pub])) {
                            $maps['_connections']['wireguard'][$pub] = (int) $remoteConn['wireguard'][$pub];
                        }
                    }
                    continue;
                }
                foreach (self::usage_name_candidates($username, $rec) as $name) {
                    if (isset($remoteMaps[$protocol][$name])) {
                        $maps[$protocol][$name] = (int) $remoteMaps[$protocol][$name];
                    }
                    if (isset($remoteConn[$protocol][$name])) {
                        $maps['_connections'][$protocol][$name] = (int) $remoteConn[$protocol][$name];
                    }
                }
            }
        }

        self::$lastCollectMeta['nodes_fetched'] = $nodesFetched;
        return $maps;
    }

    /** @return array<string,array<string,int>>|null */
    private static function batch_usage_maps_via_sudo()
    {
        $script = USK_ROOT . '/bin/collect-usage-stats.sh';
        if (!is_file($script)) {
            return null;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $cmd = '/bin/bash ' . escapeshellarg($script) . ' 2>/dev/null';
        } else {
            $cmd = 'sudo -n /bin/bash ' . escapeshellarg($script) . ' 2>/dev/null';
        }
        $raw = self::shell_with_timeout($cmd, 45);
        if ($raw === '') {
            self::$lastCollectMeta = array(
                'sudo_ok' => false,
                'source' => 'collect_script',
                'collect_error' => 'empty_output',
                'collect_uid' => function_exists('posix_geteuid') ? (int) posix_geteuid() : -1,
            );
            return null;
        }

        $data = self::decode_collect_json($raw);
        if (!is_array($data) || empty($data['ok'])) {
            self::$lastCollectMeta = array(
                'sudo_ok' => true,
                'parse_ok' => false,
                'source' => 'collect_script',
                'collect_tail' => substr(trim($raw), -400),
            );
            return self::maybe_fix_xray_stats_and_retry($script);
        }

        if (isset($data['_meta']) && is_array($data['_meta'])) {
            self::$lastCollectMeta = array_merge(array('sudo_ok' => true, 'parse_ok' => true, 'source' => 'collect_script'), $data['_meta']);
        } else {
            self::$lastCollectMeta = array('sudo_ok' => true, 'parse_ok' => true, 'source' => 'collect_script');
        }

        $maps = array(
            'wireguard' => self::normalize_int_map($data['wireguard'] ?? array()),
            'amnezia' => self::normalize_int_map($data['amnezia'] ?? array()),
            'xray' => self::normalize_int_map($data['xray'] ?? array()),
            'openvpn' => self::normalize_int_map($data['openvpn'] ?? array()),
            'cisco' => self::normalize_int_map($data['cisco'] ?? array()),
            'l2tp' => self::normalize_int_map($data['l2tp'] ?? array()),
            '_connections' => array(
                'wireguard' => self::normalize_int_map($data['connections']['wireguard'] ?? array()),
                'amnezia' => self::normalize_int_map($data['connections']['amnezia'] ?? array()),
                'xray' => self::normalize_int_map($data['connections']['xray'] ?? array()),
                'openvpn' => self::normalize_int_map($data['connections']['openvpn'] ?? array()),
                'cisco' => self::normalize_int_map($data['connections']['cisco'] ?? array()),
                'l2tp' => self::normalize_int_map($data['connections']['l2tp'] ?? array()),
            ),
        );

        $cfgClients = (int) (self::$lastCollectMeta['xray_cfg_clients'] ?? 0);
        $apiOk = !empty(self::$lastCollectMeta['xray_api_ok']);
        if ($cfgClients > 0 && !$apiOk && empty(self::$lastCollectMeta['retried'])) {
            $retry = self::maybe_fix_xray_stats_and_retry($script);
            if (is_array($retry)) {
                return $retry;
            }
        }

        return $maps;
    }

    /** @return array<string,array<string,int>>|null */
    private static function maybe_fix_xray_stats_and_retry($script)
    {
        $fix = USK_ROOT . '/bin/xray-fix-stats-api.sh';
        if (is_file($fix)) {
            self::shell_with_timeout('sudo -n /bin/bash ' . escapeshellarg($fix) . ' 2>&1', 60);
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $cmd = '/bin/bash ' . escapeshellarg($script) . ' 2>/dev/null';
        } else {
            $cmd = 'sudo -n /bin/bash ' . escapeshellarg($script) . ' 2>/dev/null';
        }
        $raw = self::shell_with_timeout($cmd, 45);
        if ($raw === '') {
            return null;
        }
        $data = self::decode_collect_json($raw);
        if (!is_array($data) || empty($data['ok'])) {
            return null;
        }

        if (isset($data['_meta']) && is_array($data['_meta'])) {
            self::$lastCollectMeta = array_merge(array('sudo_ok' => true, 'parse_ok' => true, 'source' => 'collect_script', 'retried' => true), $data['_meta']);
        } else {
            self::$lastCollectMeta = array('sudo_ok' => true, 'parse_ok' => true, 'source' => 'collect_script', 'retried' => true);
        }

        return array(
            'wireguard' => self::normalize_int_map($data['wireguard'] ?? array()),
            'amnezia' => self::normalize_int_map($data['amnezia'] ?? array()),
            'xray' => self::normalize_int_map($data['xray'] ?? array()),
            'openvpn' => self::normalize_int_map($data['openvpn'] ?? array()),
            'cisco' => self::normalize_int_map($data['cisco'] ?? array()),
            'l2tp' => self::normalize_int_map($data['l2tp'] ?? array()),
            '_connections' => array(
                'wireguard' => self::normalize_int_map($data['connections']['wireguard'] ?? array()),
                'amnezia' => self::normalize_int_map($data['connections']['amnezia'] ?? array()),
                'xray' => self::normalize_int_map($data['connections']['xray'] ?? array()),
                'openvpn' => self::normalize_int_map($data['connections']['openvpn'] ?? array()),
                'cisco' => self::normalize_int_map($data['connections']['cisco'] ?? array()),
                'l2tp' => self::normalize_int_map($data['connections']['l2tp'] ?? array()),
            ),
        );
    }

    /** @return array<string,mixed>|null */
    private static function decode_collect_json($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['ok'])) {
            return $data;
        }
        if (preg_match('/(\{"wireguard".*"ok"\s*:\s*true[^}]*\})\s*$/s', $raw, $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data) && !empty($data['ok'])) {
                return $data;
            }
        }
        if (preg_match('/(\{[^{}]*"ok"\s*:\s*true[^{}]*\})/s', $raw, $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data) && !empty($data['ok'])) {
                return $data;
            }
        }
        $start = strrpos($raw, '{"wireguard"');
        if ($start === false) {
            $start = strrpos($raw, '{');
        }
        if ($start !== false) {
            $data = json_decode(substr($raw, $start), true);
            if (is_array($data) && !empty($data['ok'])) {
                return $data;
            }
        }
        return null;
    }

    private static function record_has_usage_bytes(array $rec)
    {
        if (!array_key_exists('usage_bytes', $rec)) {
            return false;
        }
        return $rec['usage_bytes'] !== null && $rec['usage_bytes'] !== '';
    }

    private static function record_usage_bytes(array $rec)
    {
        return self::record_has_usage_bytes($rec) ? (int) $rec['usage_bytes'] : 0;
    }

    /**
     * Cumulative counters reset to 0 when the service restarts (Xray/WireGuard)
     * or when a session ends and reconnects (Cisco/ocserv session bytes, L2TP
     * ppp interface counters). The reset-safe accumulator handles all of them.
     */
    private static function is_cumulative_counter_protocol($protocol)
    {
        return in_array((string) $protocol, array('xray', 'wireguard', 'amnezia', 'cisco', 'l2tp'), true);
    }

    /**
     * Reset-safe running total for cumulative counters.
     * Adds only the delta since the last observed raw counter; when the raw
     * counter drops below the stored baseline (service restart), the current
     * raw value is treated as fresh traffic so nothing is lost or double-counted.
     *
     * @return int
     */
    private static function cumulative_total(array $rec, $rawBytes)
    {
        $rawBytes = max(0, (int) $rawBytes);
        $prevTotal = self::record_usage_bytes($rec);
        $hasBaseline = array_key_exists('usage_raw_bytes', $rec)
            && $rec['usage_raw_bytes'] !== null && $rec['usage_raw_bytes'] !== '';

        if (!$hasBaseline) {
            if ($prevTotal <= 0) {
                return $prevTotal + $rawBytes;
            }
            return $prevTotal;
        }

        $prevRaw = (int) $rec['usage_raw_bytes'];
        if ($rawBytes >= $prevRaw) {
            return $prevTotal + ($rawBytes - $prevRaw);
        }
        return $prevTotal + $rawBytes;
    }

    /** @return array{usage_bytes:int,usage_raw_bytes:int} */
    private static function accumulate_cumulative_bytes(array $rec, $rawBytes)
    {
        return array(
            'usage_bytes' => self::cumulative_total($rec, $rawBytes),
            'usage_raw_bytes' => max(0, (int) $rawBytes),
        );
    }

    /** @return int */
    private static function merge_usage_bytes($protocol, $prevBytes, $incomingBytes)
    {
        $prevBytes = (int) $prevBytes;
        $incomingBytes = (int) $incomingBytes;
        if ($protocol === 'xray' && self::xray_traffic_mode_is_delta()) {
            if ($incomingBytes <= 0) {
                return $prevBytes;
            }
            return $prevBytes + $incomingBytes;
        }
        return max($prevBytes, $incomingBytes);
    }

    private static function xray_traffic_mode_is_delta()
    {
        $mode = trim((string) (self::$lastCollectMeta['xray_traffic_mode'] ?? 'cumulative'));
        return $mode === 'delta';
    }

    private static function should_persist_usage(array $rec, $newBytes, $prevBytes)
    {
        if (!self::record_has_usage_bytes($rec)) {
            return true;
        }
        if (empty($rec['usage_synced_at'])) {
            return true;
        }
        if ((int) $newBytes !== (int) $prevBytes) {
            return true;
        }
        return (int) $newBytes > (int) $prevBytes;
    }

    private static function should_persist_connections(array $rec, $newConn, $prevConn)
    {
        if (!array_key_exists('active_connections', $rec)) {
            return true;
        }
        if (empty($rec['connections_synced_at'])) {
            return true;
        }
        return (int) $newConn !== (int) $prevConn;
    }

    /** @return array<string,int> */
    private static function normalize_int_map($raw)
    {
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $key => $val) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $out[$key] = (int) $val;
        }
        return $out;
    }

    private static function bytes_from_maps($protocol, $username, array $rec, array $maps)
    {
        if ($protocol === 'wireguard') {
            $pub = self::client_public_key($rec);
            if ($pub !== '' && array_key_exists($pub, $maps['wireguard'] ?? array())) {
                return (int) $maps['wireguard'][$pub];
            }
            return null;
        }
        if ($protocol === 'amnezia') {
            $pub = self::client_public_key($rec);
            if ($pub !== '' && array_key_exists($pub, $maps['amnezia'] ?? array())) {
                return (int) $maps['amnezia'][$pub];
            }
            return null;
        }
        if ($protocol === 'xray') {
            return self::lookup_named_map($maps['xray'] ?? array(), $protocol, $username, $rec);
        }
        if ($protocol === 'openvpn') {
            return self::lookup_named_map($maps['openvpn'] ?? array(), $protocol, $username, $rec);
        }
        if ($protocol === 'cisco') {
            return self::lookup_named_map($maps['cisco'] ?? array(), $protocol, $username, $rec);
        }
        if ($protocol === 'l2tp') {
            return self::lookup_named_map($maps['l2tp'] ?? array(), $protocol, $username, $rec);
        }

        return null;
    }

    private static function client_public_key(array $rec)
    {
        return trim((string) ($rec['public_key'] ?? ($rec['meta']['public_key'] ?? '')));
    }

    /** @return int|null */
    private static function lookup_named_map(array $map, $protocol, $username, array $rec)
    {
        if ($map === array()) {
            return null;
        }
        $zeroMatch = null;
        foreach (self::usage_name_candidates($username, $rec) as $name) {
            if (array_key_exists($name, $map)) {
                $val = (int) $map[$name];
                if ($val > 0) {
                    return $val;
                }
                $zeroMatch = 0;
            }
        }
        $uuid = trim((string) ($rec['uuid'] ?? ($rec['meta']['uuid'] ?? '')));
        if ($uuid !== '') {
            foreach ($map as $key => $val) {
                $key = (string) $key;
                if ($key === $uuid || stripos($key, $uuid) !== false) {
                    $val = (int) $val;
                    if ($val > 0) {
                        return $val;
                    }
                    $zeroMatch = 0;
                }
            }
        }
        $user = trim((string) $username);
        if ($user !== '') {
            foreach ($map as $key => $val) {
                if ((string) $key === $user) {
                    $val = (int) $val;
                    if ($val > 0) {
                        return $val;
                    }
                    $zeroMatch = 0;
                }
            }
        }
        return $zeroMatch;
    }

    private static function connections_from_maps($protocol, $username, array $rec, array $connMaps)
    {
        $hasConnData = is_array($connMaps) && $connMaps !== array();
        if ($protocol === 'wireguard') {
            $pub = self::client_public_key($rec);
            if ($pub !== '' && array_key_exists($pub, $connMaps['wireguard'] ?? array())) {
                return max(0, (int) $connMaps['wireguard'][$pub]);
            }
            return $hasConnData ? 0 : null;
        }
        if ($protocol === 'amnezia') {
            $pub = self::client_public_key($rec);
            if ($pub !== '' && array_key_exists($pub, $connMaps['amnezia'] ?? array())) {
                return max(0, (int) $connMaps['amnezia'][$pub]);
            }
            return $hasConnData ? 0 : null;
        }
        if ($protocol === 'xray' || $protocol === 'openvpn' || $protocol === 'cisco' || $protocol === 'l2tp') {
            $sub = $connMaps[$protocol] ?? array();
            if (!is_array($sub)) {
                return $hasConnData ? 0 : null;
            }
            foreach (self::usage_name_candidates($username, $rec) as $name) {
                if (array_key_exists($name, $sub)) {
                    return max(0, (int) $sub[$name]);
                }
            }
            $uuid = trim((string) ($rec['uuid'] ?? ($rec['meta']['uuid'] ?? '')));
            if ($uuid !== '') {
                foreach ($sub as $key => $val) {
                    if ((string) $key === $uuid || stripos((string) $key, $uuid) !== false) {
                        return max(0, (int) $val);
                    }
                }
            }
            return $hasConnData ? 0 : null;
        }

        return null;
    }

    /** @return string[] */
    private static function usage_name_candidates($username, array $rec)
    {
        $names = array(
            trim((string) $username),
            trim((string) ($rec['xray_email'] ?? '')),
            trim((string) ($rec['usage_id'] ?? '')),
            trim((string) ($rec['username'] ?? '')),
            trim((string) ($rec['email'] ?? '')),
            trim((string) ($rec['meta']['xray_email'] ?? '')),
            trim((string) ($rec['meta']['usage_id'] ?? '')),
            trim((string) ($rec['meta']['username'] ?? '')),
            trim((string) ($rec['meta']['email'] ?? '')),
        );
        $uuid = trim((string) ($rec['uuid'] ?? ($rec['meta']['uuid'] ?? '')));
        if ($uuid !== '') {
            $names[] = $uuid;
            $email = self::xray_email_for_uuid($uuid);
            if ($email !== '') {
                $names[] = $email;
            }
        }
        if (!empty($rec['order_code'])) {
            $code = trim((string) $rec['order_code']);
            if ($code !== '') {
                $names[] = base64_encode($code);
                $names[] = base64_encode($code) . '_admin';
            }
        }
        $out = array();
        foreach ($names as $name) {
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @param array<string,int> $ovpnMap
     * @return array{usage_bytes:int,ovpn_session_bytes:int}|null
     */
    private static function sync_openvpn_client($username, array $rec, array $ovpnMap)
    {
        if ($ovpnMap === array() && !self::openvpn_status_available()) {
            return null;
        }

        $sessionBytes = null;
        foreach (self::usage_name_candidates($username, $rec) as $name) {
            if (array_key_exists($name, $ovpnMap)) {
                $sessionBytes = (int) $ovpnMap[$name];
                break;
            }
        }
        if ($sessionBytes === null) {
            $sessionBytes = 0;
        }

        $lastSession = (int) ($rec['ovpn_session_bytes'] ?? 0);
        $total = (int) ($rec['usage_bytes'] ?? 0);

        if ($sessionBytes === 0 && $lastSession > 0) {
            $total += $lastSession;
            $lastSession = 0;
        } elseif ($sessionBytes > 0) {
            if ($sessionBytes < $lastSession) {
                $total += $lastSession;
                $lastSession = 0;
            }
            $total += max(0, $sessionBytes - $lastSession);
            $lastSession = $sessionBytes;
        }

        return array(
            'usage_bytes' => $total,
            'ovpn_session_bytes' => $lastSession,
        );
    }

    /** @return string[] */
    private static function openvpn_status_files()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $files = array();
        $configs = glob('/etc/openvpn/*.conf') ?: array();
        foreach ($configs as $cfg) {
            $lines = @file($cfg, FILE_IGNORE_NEW_LINES);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                if (preg_match('/^status\s+(\S+)/', $line, $m)) {
                    $files[] = $m[1];
                }
            }
        }

        foreach (array(
            '/var/log/openvpn/openvpn-udp-status.log',
            '/var/log/openvpn/openvpn-tcp-status.log',
            '/var/log/openvpn/openvpn-status.log',
            '/var/log/openvpn/status.log',
            '/run/openvpn-server/status.log',
            USK_ROOT . '/data/openvpn/status.log',
        ) as $file) {
            $files[] = $file;
        }

        $files = array_values(array_unique(array_filter($files, function ($f) {
            return is_string($f) && $f !== '' && is_readable($f);
        })));
        $cache = $files;
        return $cache;
    }

    private static function openvpn_status_available()
    {
        return count(self::openvpn_status_files()) > 0;
    }

    /** @return int|null */
    private static function openvpn_client_list_bytes(array $parts)
    {
        if (count($parts) < 6) {
            return null;
        }
        if (trim($parts[1]) === 'Common Name') {
            return null;
        }
        if (count($parts) >= 7) {
            return (int) $parts[4] + (int) $parts[5];
        }

        return (int) $parts[3] + (int) $parts[4];
    }

    /** @return array<string,int> */
    private static function parse_openvpn_status_map($file)
    {
        $map = array();
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!$lines) {
            return $map;
        }
        foreach ($lines as $line) {
            if (strpos($line, 'CLIENT_LIST') !== 0) {
                continue;
            }
            $parts = explode(',', $line);
            $bytes = self::openvpn_client_list_bytes($parts);
            if ($bytes === null) {
                continue;
            }
            $user = trim($parts[1]);
            if ($user === '') {
                continue;
            }
            if (!isset($map[$user])) {
                $map[$user] = 0;
            }
            $map[$user] += $bytes;
        }

        return $map;
    }

    private static function xray_email_for_uuid($uuid)
    {
        $uuid = trim((string) $uuid);
        if ($uuid === '') {
            return '';
        }
        $map = self::xray_uuid_email_map();
        return $map[$uuid] ?? '';
    }

    /** @return array<string, string> */
    private static function xray_uuid_email_map()
    {
        if (self::$xrayUuidEmailCache !== null) {
            return self::$xrayUuidEmailCache;
        }

        self::$xrayUuidEmailCache = array();
        $paths = array(
            USK_ROOT . '/data/clients/xray.json',
            '/usr/local/etc/xray/config.json',
            getenv('XRAY_CFG') ?: '',
        );
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || !is_readable($path)) {
                continue;
            }
            if (substr($path, -5) === '.json' && strpos($path, 'clients/xray') !== false) {
                $panel = json_decode((string) file_get_contents($path), true);
                if (is_array($panel)) {
                    foreach ($panel as $username => $rec) {
                        if (!is_array($rec)) {
                            continue;
                        }
                        $id = trim((string) ($rec['uuid'] ?? ($rec['id'] ?? '')));
                        $email = trim((string) ($rec['xray_email'] ?? ($rec['usage_id'] ?? ($rec['email'] ?? $username))));
                        if ($id !== '' && $email !== '') {
                            self::$xrayUuidEmailCache[$id] = $email;
                        }
                    }
                }
                if (self::$xrayUuidEmailCache !== array()) {
                    break;
                }
                continue;
            }
            $cfg = json_decode((string) file_get_contents($path), true);
            if (!is_array($cfg)) {
                continue;
            }
            foreach ($cfg['inbounds'] ?? array() as $inbound) {
                if (!is_array($inbound) || ($inbound['protocol'] ?? '') !== 'vless') {
                    continue;
                }
                foreach ($inbound['settings']['clients'] ?? array() as $client) {
                    if (!is_array($client)) {
                        continue;
                    }
                    $id = trim((string) ($client['id'] ?? ''));
                    $email = trim((string) ($client['email'] ?? ''));
                    if ($id !== '' && $email !== '') {
                        self::$xrayUuidEmailCache[$id] = $email;
                    }
                }
            }
            if (self::$xrayUuidEmailCache !== array()) {
                break;
            }
        }

        return self::$xrayUuidEmailCache;
    }

    /** @return array<string, int> public_key => bytes */
    private static function batch_wg_dump($interface, $cmd = 'wg')
    {
        $map = array();
        $raw = self::shell_with_timeout($cmd . ' show ' . escapeshellarg($interface) . ' dump 2>/dev/null', 5);
        if ($raw === '') {
            return $map;
        }
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[0] !== '') {
                $map[$parts[0]] = (int) $parts[5] + (int) $parts[6];
            }
        }
        return $map;
    }

    /** @return array<string, int> username => bytes */
    private static function batch_xray_user_bytes()
    {
        $map = array();
        $xray = self::xray_bin();
        if ($xray === '') {
            return $map;
        }

        $cmd = escapeshellarg($xray) . ' api statsquery --server=127.0.0.1:10085 -reset=false 2>/dev/null';
        $raw = self::shell_with_timeout($cmd, 8);
        if ($raw !== '') {
            $data = json_decode(trim($raw), true);
            if (is_array($data)) {
                $stats = $data['stat'] ?? ($data['stats'] ?? array());
                if (is_array($stats)) {
                    foreach ($stats as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $name = (string) ($row['name'] ?? '');
                        if ($name === '' || strpos($name, 'user>>>') !== 0) {
                            continue;
                        }
                        $parts = explode('>>>', $name);
                        if (count($parts) < 4) {
                            continue;
                        }
                        $user = $parts[1];
                        if ($user === '') {
                            continue;
                        }
                        if (!isset($map[$user])) {
                            $map[$user] = 0;
                        }
                        $map[$user] += (int) ($row['value'] ?? 0);
                    }
                }
            }
        }

        foreach (self::xray_uuid_email_map() as $uuid => $email) {
            if ($email === '') {
                continue;
            }
            if (isset($map[$email]) && $map[$email] > 0) {
                $map[$uuid] = $map[$email];
                continue;
            }
            $bytes = self::xray_email_stats_total($xray, $email);
            if ($bytes !== null) {
                $map[$email] = $bytes;
                $map[$uuid] = $bytes;
            }
        }

        return $map;
    }

    private static function xray_email_stats_total($xray, $email)
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }
        $total = 0;
        $found = false;
        foreach (array('downlink', 'uplink') as $dir) {
            $statName = 'user>>>' . $email . '>>>traffic>>>' . $dir;
            $cmd = escapeshellarg($xray) . ' api stats --server=127.0.0.1:10085 -name '
                . escapeshellarg($statName) . ' -reset=false 2>/dev/null';
            $raw = self::shell_with_timeout($cmd, 3);
            if ($raw === '') {
                continue;
            }
            $data = json_decode(trim($raw), true);
            if (!is_array($data)) {
                continue;
            }
            $val = $data['stat']['value'] ?? null;
            if ($val === null) {
                continue;
            }
            $found = true;
            $total += (int) $val;
        }
        return $found ? $total : null;
    }

    /** @return array<string, int> username => bytes */
    private static function batch_openvpn_user_bytes()
    {
        $map = array();
        foreach (self::openvpn_status_files() as $file) {
            foreach (self::parse_openvpn_status_map($file) as $user => $bytes) {
                if (!isset($map[$user])) {
                    $map[$user] = 0;
                }
                $map[$user] += (int) $bytes;
            }
        }

        $registry = USK_ProtocolLimits::load_registry_clients('openvpn');
        foreach ($registry as $username => $rec) {
            if (!array_key_exists($username, $map)) {
                $map[$username] = 0;
            }
        }

        return $map;
    }

    public static function amnezia_usage_bytes(array $rec)
    {
        $pub = $rec['public_key'] ?? ($rec['meta']['public_key'] ?? '');
        if ($pub === '') {
            return null;
        }
        return self::interface_peer_bytes('awg0', $pub, 'awg');
    }

    public static function xray_usage_bytes(array $rec)
    {
        $maps = self::batch_usage_maps();
        foreach (self::usage_name_candidates((string) ($rec['username'] ?? ''), $rec) as $name) {
            if (isset($maps['xray'][$name])) {
                return (int) $maps['xray'][$name];
            }
        }

        $xray = self::xray_bin();
        if ($xray === '') {
            return null;
        }

        foreach (self::usage_name_candidates((string) ($rec['username'] ?? ''), $rec) as $name) {
            $total = self::xray_email_stats_total($xray, $name);
            if ($total !== null) {
                return $total;
            }
        }

        return null;
    }

    public static function openvpn_usage_bytes(array $rec)
    {
        if (isset($rec['usage_bytes'])) {
            return (int) $rec['usage_bytes'];
        }

        $maps = self::batch_usage_maps();
        foreach (self::usage_name_candidates((string) ($rec['username'] ?? ''), $rec) as $name) {
            if (isset($maps['openvpn'][$name])) {
                return (int) $maps['openvpn'][$name];
            }
        }

        return null;
    }

    private static function parse_openvpn_status_file($file, $username)
    {
        foreach (self::parse_openvpn_status_map($file) as $user => $bytes) {
            if ($user === $username) {
                return (int) $bytes;
            }
        }

        return null;
    }

    private static function interface_peer_bytes($interface, $publicKey, $cmd = 'wg')
    {
        $maps = self::batch_usage_maps();
        $key = ($cmd === 'awg') ? 'amnezia' : 'wireguard';
        if (isset($maps[$key][$publicKey])) {
            return (int) $maps[$key][$publicKey];
        }

        $raw = self::shell_with_timeout($cmd . ' show ' . escapeshellarg($interface) . ' dump 2>/dev/null', 5);
        if ($raw === '') {
            return null;
        }
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[0] === $publicKey) {
                return (int) $parts[5] + (int) $parts[6];
            }
        }
        return null;
    }

    private static function xray_bin()
    {
        $paths = array('/usr/local/bin/xray', '/usr/bin/xray');
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = trim((string) self::shell_with_timeout('command -v xray 2>/dev/null', 2));
        return $which !== '' ? $which : '';
    }

    private static function shell_with_timeout($cmd, $seconds = 5)
    {
        $seconds = max(1, (int) $seconds);
        $wrapped = 'timeout ' . $seconds . ' ' . $cmd;
        $out = @shell_exec($wrapped);
        if ($out !== null && trim($out) !== '') {
            return (string) $out;
        }
        $out = @shell_exec($cmd);
        return $out !== null ? (string) $out : '';
    }

    public static function qr_png_b64($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (!self::command_exists('qrencode')) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'uskqr');
        if ($tmp === false) {
            return '';
        }
        $png = $tmp . '.png';
        @unlink($tmp);
        $cmd = 'qrencode -t PNG -o ' . escapeshellarg($png) . ' ' . escapeshellarg($text) . ' 2>/dev/null';
        self::shell_with_timeout($cmd, 10);
        if (!is_file($png)) {
            return '';
        }
        $data = file_get_contents($png);
        @unlink($png);
        return $data !== false ? base64_encode($data) : '';
    }

    private static function command_exists($cmd)
    {
        $out = trim(self::shell_with_timeout('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', 2));
        return $out !== '';
    }
}
