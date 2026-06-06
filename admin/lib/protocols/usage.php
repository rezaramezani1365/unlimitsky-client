<?php

class USK_ProtocolUsage
{
    public static function client_usage_bytes($protocol, array $rec)
    {
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
        $usedBytes = self::client_usage_bytes($protocol, $rec);
        $limitBytes = $volumeGb > 0 ? ($volumeGb * 1073741824) : 0;
        $usedGb = round($usedBytes / 1073741824, 2);
        $remainingGb = $volumeGb > 0 ? max(0, round($volumeGb - $usedGb, 2)) : null;
        $percent = ($volumeGb > 0) ? min(100, round(($usedGb / $volumeGb) * 100, 1)) : null;

        return array(
            'tracked' => true,
            'used_bytes' => $usedBytes,
            'used_gb' => $usedGb,
            'remaining_gb' => $remainingGb,
            'limit_gb' => $volumeGb,
            'percent' => $percent,
            'exceeded' => $limitBytes > 0 && $usedBytes >= $limitBytes,
            'used_label' => USK_ProtocolLimits::format_bytes($usedBytes),
        );
    }

    public static function sync_all()
    {
        $updated = 0;
        foreach (array('wireguard', 'openvpn', 'xray', 'l2tp', 'cisco', 'amnezia') as $protocol) {
            $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
            $changed = false;
            foreach ($clients as $username => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                $bytes = self::live_usage_bytes($protocol, $rec);
                if ($bytes === null) {
                    continue;
                }
                if ((int) ($rec['usage_bytes'] ?? 0) !== (int) $bytes) {
                    $clients[$username]['usage_bytes'] = (int) $bytes;
                    $clients[$username]['usage_synced_at'] = date('c');
                    $changed = true;
                    $updated++;
                }
            }
            if ($changed) {
                USK_ProtocolLimits::save_protocol_clients($protocol, $clients);
            }
        }
        return $updated;
    }

    private static function live_usage_bytes($protocol, array $rec)
    {
        if ($protocol === 'wireguard') {
            return USK_ProtocolLimits::wireguard_usage_bytes($rec);
        }
        if ($protocol === 'amnezia') {
            return self::amnezia_usage_bytes($rec);
        }
        if ($protocol === 'xray') {
            return self::xray_usage_bytes($rec);
        }
        if ($protocol === 'openvpn') {
            return self::openvpn_usage_bytes($rec);
        }
        return null;
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

        $xray = self::xray_bin();
        if ($xray === '') {
            return null;
        }

        $pattern = 'user>>>' . $email . '>>>traffic>>>.*';
        $cmd = escapeshellarg($xray) . ' api statsquery --server=127.0.0.1:10085 --pattern '
            . escapeshellarg($pattern) . ' 2>/dev/null';
        $raw = @shell_exec($cmd);
        if ($raw === null || trim($raw) === '') {
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
        $raw = @shell_exec($cmd . ' show ' . escapeshellarg($interface) . ' dump 2>/dev/null');
        if (!$raw) {
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
        $which = trim((string) @shell_exec('command -v xray 2>/dev/null'));
        return $which !== '' ? $which : '';
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
        @shell_exec($cmd);
        if (!is_file($png)) {
            return '';
        }
        $data = file_get_contents($png);
        @unlink($png);
        return $data !== false ? base64_encode($data) : '';
    }

    private static function command_exists($cmd)
    {
        $out = trim((string) @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null'));
        return $out !== '';
    }
}
