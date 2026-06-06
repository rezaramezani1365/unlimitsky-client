<?php

class USK_ProtocolUsage
{
    /** @var array<string, array<string, int>>|null */
    private static $batchCache = null;

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
        $metered = in_array($protocol, array('wireguard', 'openvpn', 'xray', 'amnezia'), true);
        $syncedAt = trim((string) ($rec['usage_synced_at'] ?? ($rec['meta']['usage_synced_at'] ?? '')));

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
        );
    }

    /** Cron-only: batch-read live stats and persist to client JSON files. */
    public static function sync_all()
    {
        self::$batchCache = null;
        $maps = self::batch_usage_maps();

        $updated = 0;
        foreach (array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco', 'amnezia') as $protocol) {
            $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
            $changed = false;
            foreach ($clients as $username => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $bytes = self::bytes_from_maps($protocol, $username, $rec, $maps);
                if ($bytes === null) {
                    continue;
                }
                $prev = (int) ($rec['usage_bytes'] ?? 0);
                $newBytes = max($prev, (int) $bytes);
                if ($newBytes !== $prev) {
                    $clients[$username]['usage_bytes'] = $newBytes;
                    $clients[$username]['usage_synced_at'] = date('c');
                    $changed = true;
                    $updated++;
                } elseif (empty($rec['usage_synced_at']) && (int) $bytes >= 0) {
                    $clients[$username]['usage_synced_at'] = date('c');
                    $changed = true;
                }
            }
            if ($changed) {
                USK_ProtocolLimits::save_protocol_clients($protocol, $clients);
            }
        }

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
            return null;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data) || empty($data['ok'])) {
            return null;
        }

        return array(
            'wireguard' => self::normalize_int_map($data['wireguard'] ?? array()),
            'amnezia' => self::normalize_int_map($data['amnezia'] ?? array()),
            'xray' => self::normalize_int_map($data['xray'] ?? array()),
            'openvpn' => self::normalize_int_map($data['openvpn'] ?? array()),
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
                if (isset($maps['openvpn'][$name])) {
                    return (int) $maps['openvpn'][$name];
                }
            }
            return null;
        }

        return null;
    }

    /** @return string[] */
    private static function usage_name_candidates($username, array $rec)
    {
        $names = array(
            trim((string) $username),
            trim((string) ($rec['username'] ?? '')),
        );
        $out = array();
        foreach ($names as $name) {
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
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

        $cmd = escapeshellarg($xray) . ' api statsquery --server=127.0.0.1:10085 --pattern '
            . escapeshellarg('user>>>') . ' 2>/dev/null';
        $raw = self::shell_with_timeout($cmd, 5);
        if ($raw === '') {
            return $map;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data)) {
            return $map;
        }

        $stats = $data['stat'] ?? ($data['stats'] ?? array());
        if (!is_array($stats)) {
            return $map;
        }

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

        return $map;
    }

    /** @return array<string, int> username => bytes */
    private static function batch_openvpn_user_bytes()
    {
        $map = array();
        $files = array(
            '/var/log/openvpn/openvpn-udp-status.log',
            '/var/log/openvpn/openvpn-tcp-status.log',
            '/var/log/openvpn/openvpn-status.log',
            '/var/log/openvpn/status.log',
            '/run/openvpn-server/status.log',
            USK_ROOT . '/data/openvpn/status.log',
        );

        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                if (strpos($line, 'CLIENT_LIST') !== 0) {
                    continue;
                }
                $parts = explode(',', $line);
                if (count($parts) < 7) {
                    continue;
                }
                $user = trim($parts[1]);
                if ($user === '') {
                    continue;
                }
                $bytes = (int) $parts[5] + (int) $parts[6];
                if (!isset($map[$user])) {
                    $map[$user] = 0;
                }
                $map[$user] += $bytes;
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
        $email = trim((string) ($rec['username'] ?? ''));
        if ($email === '') {
            return null;
        }

        $maps = self::batch_usage_maps();
        if (isset($maps['xray'][$email])) {
            return (int) $maps['xray'][$email];
        }

        $xray = self::xray_bin();
        if ($xray === '') {
            return null;
        }

        $pattern = 'user>>>' . $email . '>>>traffic>>>.*';
        $cmd = escapeshellarg($xray) . ' api statsquery --server=127.0.0.1:10085 --pattern '
            . escapeshellarg($pattern) . ' 2>/dev/null';
        $raw = self::shell_with_timeout($cmd, 3);
        if ($raw === '') {
            return null;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data)) {
            return null;
        }

        $total = 0;
        $stats = $data['stat'] ?? ($data['stats'] ?? array());
        if (!is_array($stats)) {
            return null;
        }
        foreach ($stats as $row) {
            if (!is_array($row)) {
                continue;
            }
            $total += (int) ($row['value'] ?? 0);
        }
        return $total;
    }

    public static function openvpn_usage_bytes(array $rec)
    {
        $username = trim((string) ($rec['username'] ?? ''));
        if ($username === '') {
            return null;
        }

        $maps = self::batch_usage_maps();
        if (isset($maps['openvpn'][$username])) {
            return (int) $maps['openvpn'][$username];
        }

        $files = array(
            '/var/log/openvpn/openvpn-udp-status.log',
            '/var/log/openvpn/openvpn-tcp-status.log',
            '/var/log/openvpn/openvpn-status.log',
            '/var/log/openvpn/status.log',
            '/run/openvpn-server/status.log',
            USK_ROOT . '/data/openvpn/status.log',
        );

        $total = null;
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $bytes = self::parse_openvpn_status_file($file, $username);
            if ($bytes !== null) {
                $total = ($total ?? 0) + $bytes;
            }
        }

        return $total;
    }

    private static function parse_openvpn_status_file($file, $username)
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!$lines) {
            return null;
        }

        foreach ($lines as $line) {
            if (strpos($line, 'CLIENT_LIST') !== 0) {
                continue;
            }
            $parts = explode(',', $line);
            if (count($parts) < 6) {
                continue;
            }
            if (trim($parts[1]) !== $username) {
                continue;
            }
            if (count($parts) >= 7) {
                return (int) $parts[5] + (int) $parts[6];
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
