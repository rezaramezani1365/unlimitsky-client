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
        $metered = in_array($protocol, array('wireguard', 'openvpn', 'xray'), true);
        $syncedAt = trim((string) ($rec['usage_synced_at'] ?? ($rec['meta']['usage_synced_at'] ?? '')));

        require_once __DIR__ . '/connections.php';
        $maxConn = USK_ProtocolConnections::max_connections_for($rec);
        $activeConn = max(0, (int) ($rec['active_connections'] ?? ($rec['meta']['active_connections'] ?? 0)));
        $connSyncedAt = trim((string) ($rec['connections_synced_at'] ?? ($rec['meta']['connections_synced_at'] ?? '')));
        $connTracked = in_array($protocol, array('wireguard', 'openvpn', 'xray'), true);

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
            'active_connections' => $activeConn,
            'connections_synced_at' => $connSyncedAt,
            'connections_tracked' => $connTracked,
            'connections_display' => $connTracked ? ($activeConn . ' / ' . $maxConn) : null,
            'connections_near_limit' => $connTracked && $activeConn >= $maxConn,
            'connections_warning' => $connTracked && $maxConn > 0 && $activeConn >= max(1, $maxConn - 1) && $activeConn < $maxConn,
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
        );
        $connMaps = $maps['_connections'] ?? array();

        $updated = 0;
        $synced = 0;
        $connUpdated = 0;
        foreach (array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco') as $protocol) {
            $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
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
                    $prev = (int) ($rec['usage_bytes'] ?? 0);
                    $hadSync = !empty($rec['usage_synced_at']);
                    $newBytes = (int) $ovpn['usage_bytes'];
                    $clients[$username]['usage_bytes'] = $newBytes;
                    $clients[$username]['ovpn_session_bytes'] = (int) $ovpn['ovpn_session_bytes'];
                    $connActive = self::connections_from_maps($protocol, $username, $rec, $connMaps);
                    if ($connActive !== null) {
                        $prevConn = (int) ($rec['active_connections'] ?? 0);
                        $clients[$username]['active_connections'] = $connActive;
                        $clients[$username]['connections_synced_at'] = date('c');
                        if ($connActive !== $prevConn || empty($rec['connections_synced_at'])) {
                            $changed = true;
                            $connUpdated++;
                        }
                    }
                    if ($newBytes !== $prev) {
                        $clients[$username]['usage_synced_at'] = date('c');
                        $changed = true;
                        $updated++;
                        $synced++;
                    } elseif (!$hadSync) {
                        $clients[$username]['usage_synced_at'] = date('c');
                        $changed = true;
                        $synced++;
                    } else {
                        $changed = true;
                    }
                    continue;
                }
                $bytes = self::bytes_from_maps($protocol, $username, $rec, $maps);
                if ($bytes === null) {
                    continue;
                }
                $prev = (int) ($rec['usage_bytes'] ?? 0);
                $newBytes = max($prev, (int) $bytes);
                $hadSync = !empty($rec['usage_synced_at']);
                if ($newBytes !== $prev) {
                    $clients[$username]['usage_bytes'] = $newBytes;
                    $clients[$username]['usage_synced_at'] = date('c');
                    $changed = true;
                    $updated++;
                    $synced++;
                } elseif (!$hadSync) {
                    $clients[$username]['usage_synced_at'] = date('c');
                    $changed = true;
                    $synced++;
                }
                $connActive = self::connections_from_maps($protocol, $username, $rec, $connMaps);
                if ($connActive !== null) {
                    $prevConn = (int) ($rec['active_connections'] ?? 0);
                    if ($connActive !== $prevConn || empty($rec['connections_synced_at'])) {
                        $clients[$username]['active_connections'] = $connActive;
                        $clients[$username]['connections_synced_at'] = date('c');
                        $changed = true;
                        $connUpdated++;
                    }
                }
            }
            if ($changed) {
                USK_ProtocolLimits::save_protocol_clients($protocol, $clients);
            }
        }

        self::$lastCollectMeta['usage_synced'] = $synced;
        self::$lastCollectMeta['connections_synced'] = $connUpdated;
        self::$batchCache = null;
        return $updated;
    }

    /** @return array<string, mixed> */
    private static function batch_usage_maps()
    {
        if (self::$batchCache !== null) {
            return self::$batchCache;
        }

        $fromSudo = self::batch_usage_maps_via_sudo();
        if (is_array($fromSudo)) {
            self::$batchCache = $fromSudo;
            return self::$batchCache;
        }

        self::$batchCache = array(
            'wireguard' => self::batch_wg_dump('wg0', 'wg'),
            'amnezia' => self::batch_wg_dump('awg0', 'awg'),
            'xray' => self::batch_xray_user_bytes(),
            'openvpn' => self::batch_openvpn_user_bytes(),
        );
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

    /** @return array<string,array<string,int>>|null */
    private static function batch_usage_maps_via_sudo()
    {
        $script = USK_ROOT . '/bin/collect-usage-stats.sh';
        if (!is_file($script)) {
            return null;
        }

        $cmd = 'sudo -n /bin/bash ' . escapeshellarg($script) . ' 2>/dev/null';
        $raw = self::shell_with_timeout($cmd, 30);
        if ($raw === '') {
            self::$lastCollectMeta = array('sudo_ok' => false, 'source' => 'collect_script');
            return null;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data) || empty($data['ok'])) {
            self::$lastCollectMeta = array('sudo_ok' => true, 'parse_ok' => false);
            return null;
        }

        if (isset($data['_meta']) && is_array($data['_meta'])) {
            self::$lastCollectMeta = array_merge(array('sudo_ok' => true, 'source' => 'collect_script'), $data['_meta']);
        } else {
            self::$lastCollectMeta = array('sudo_ok' => true, 'source' => 'collect_script');
        }

        return array(
            'wireguard' => self::normalize_int_map($data['wireguard'] ?? array()),
            'amnezia' => self::normalize_int_map($data['amnezia'] ?? array()),
            'xray' => self::normalize_int_map($data['xray'] ?? array()),
            'openvpn' => self::normalize_int_map($data['openvpn'] ?? array()),
            '_connections' => array(
                'wireguard' => self::normalize_int_map($data['connections']['wireguard'] ?? array()),
                'amnezia' => self::normalize_int_map($data['connections']['amnezia'] ?? array()),
                'xray' => self::normalize_int_map($data['connections']['xray'] ?? array()),
                'openvpn' => self::normalize_int_map($data['connections']['openvpn'] ?? array()),
            ),
        );
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
            $pub = $rec['public_key'] ?? ($rec['meta']['public_key'] ?? '');
            return ($pub !== '' && isset($maps['wireguard'][$pub])) ? (int) $maps['wireguard'][$pub] : null;
        }
        if ($protocol === 'amnezia') {
            $pub = $rec['public_key'] ?? ($rec['meta']['public_key'] ?? '');
            return ($pub !== '' && isset($maps['amnezia'][$pub])) ? (int) $maps['amnezia'][$pub] : null;
        }
        if ($protocol === 'xray') {
            foreach (self::usage_name_candidates($username, $rec) as $name) {
                if (isset($maps['xray'][$name])) {
                    return (int) $maps['xray'][$name];
                }
            }
            return null;
        }
        if ($protocol === 'openvpn') {
            foreach (self::usage_name_candidates($username, $rec) as $name) {
                if (array_key_exists($name, $maps['openvpn'])) {
                    return (int) $maps['openvpn'][$name];
                }
            }
            return null;
        }

        return null;
    }

    private static function connections_from_maps($protocol, $username, array $rec, array $connMaps)
    {
        if ($protocol === 'wireguard') {
            $pub = $rec['public_key'] ?? ($rec['meta']['public_key'] ?? '');
            if ($pub !== '' && isset($connMaps['wireguard'][$pub])) {
                return max(0, (int) $connMaps['wireguard'][$pub]);
            }
            return isset($connMaps['wireguard']) ? 0 : null;
        }
        if ($protocol === 'amnezia') {
            $pub = $rec['public_key'] ?? ($rec['meta']['public_key'] ?? '');
            if ($pub !== '' && isset($connMaps['amnezia'][$pub])) {
                return max(0, (int) $connMaps['amnezia'][$pub]);
            }
            return isset($connMaps['amnezia']) ? 0 : null;
        }
        if ($protocol === 'xray' || $protocol === 'openvpn') {
            foreach (self::usage_name_candidates($username, $rec) as $name) {
                if (isset($connMaps[$protocol][$name])) {
                    return max(0, (int) $connMaps[$protocol][$name]);
                }
            }
            return isset($connMaps[$protocol]) ? 0 : null;
        }

        return null;
    }

    /** @return string[] */
    private static function usage_name_candidates($username, array $rec)
    {
        $names = array(
            trim((string) $username),
            trim((string) ($rec['username'] ?? '')),
            trim((string) ($rec['email'] ?? '')),
        );
        $uuid = trim((string) ($rec['uuid'] ?? ($rec['meta']['uuid'] ?? '')));
        if ($uuid !== '') {
            $names[] = $uuid;
            $email = self::xray_email_for_uuid($uuid);
            if ($email !== '') {
                $names[] = $email;
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
            '/usr/local/etc/xray/config.json',
            getenv('XRAY_CFG') ?: '',
        );
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || !is_readable($path)) {
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

        $cmd = escapeshellarg($xray) . ' api statsquery --server=127.0.0.1:10085 2>/dev/null';
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
                . escapeshellarg($statName) . ' 2>/dev/null';
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
