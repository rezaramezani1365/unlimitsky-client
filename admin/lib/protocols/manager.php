<?php

class USK_ProtocolManager
{
    public static function list()
    {
        return array(
            'wireguard' => array(
                'name' => 'WireGuard',
                'port' => 51820,
                'icon' => 'fa-shield',
                'port_fields' => array(
                    array('key' => 'port', 'label' => 'Listen port (UDP)', 'default' => 51820),
                ),
            ),
            'openvpn' => array(
                'name' => 'OpenVPN',
                'port' => 1194,
                'icon' => 'fa-lock',
                'port_fields' => array(
                    array('key' => 'udp_port', 'label' => 'UDP port', 'default' => 1194),
                    array('key' => 'tcp_port', 'label' => 'TCP port', 'default' => 443),
                ),
            ),
            'cisco' => array(
                'name' => 'Cisco AnyConnect',
                'port' => 4443,
                'icon' => 'fa-building',
                'port_fields' => array(
                    array('key' => 'port', 'label' => 'Port (TCP/UDP)', 'default' => 4443),
                ),
            ),
            'l2tp' => array(
                'name' => 'L2TP/IPsec',
                'port' => 1701,
                'icon' => 'fa-network-wired',
                'port_fields' => array(),
                'fixed_ports' => '500, 4500, 1701 (UDP)',
            ),
            'xray' => array(
                'name' => 'Xray (VLESS/VMess)',
                'port' => 2053,
                'icon' => 'fa-bolt',
                'port_fields' => array(
                    array('key' => 'vless_port', 'label' => 'VLESS port (TCP)', 'default' => 2053),
                    array('key' => 'vmess_port', 'label' => 'VMess port (TCP)', 'default' => 8443),
                ),
            ),
        );
    }

    public static function status_file($proto)
    {
        return USK_ROOT . '/data/protocols/' . $proto . '.json';
    }

    public static function read_status($proto)
    {
        $f = self::status_file($proto);
        if (!file_exists($f)) {
            return array('installed' => false, 'status' => 'not_installed');
        }
        $d = json_decode(file_get_contents($f), true);
        return is_array($d) ? $d : array('installed' => false, 'status' => 'not_installed');
    }

    public static function probe_marker($proto)
    {
        $marker = USK_ROOT . '/data/protocol-installed/' . preg_replace('/[^a-z]/', '', $proto);
        return is_file($marker);
    }

    public static function probe_installed($proto)
    {
        if (self::probe_marker($proto)) {
            return true;
        }
        switch ($proto) {
            case 'l2tp':
                return is_file('/etc/xl2tpd/xl2tpd.conf')
                    && is_file('/etc/ppp/options.xl2tpd')
                    && is_file('/etc/unlimitsky-l2tp.psk');
            case 'wireguard':
                return is_file('/etc/wireguard/wg0.conf');
            case 'openvpn':
                return is_file('/etc/openvpn/server-udp.conf')
                    || is_file('/etc/openvpn/server.conf');
            case 'xray':
                return is_file('/usr/local/etc/xray/config.json')
                    || is_file('/etc/xray/config.json');
            case 'cisco':
                return is_file('/etc/ocserv/ocserv.conf');
            default:
                return false;
        }
    }

    public static function default_status_fields($proto)
    {
        $out = array();
        if ($proto === 'l2tp') {
            $out['port'] = 1701;
            $out['firewall_note'] = 'Open UDP 500, 4500, and 1701 in your VPS cloud firewall (security group).';
        }
        return $out;
    }

    public static function sync_probe_status($proto)
    {
        if (!self::probe_installed($proto)) {
            return false;
        }
        $st = self::read_status($proto);
        if (!empty($st['installed'])) {
            return true;
        }
        self::set_status($proto, array_merge($st, self::default_status_fields($proto), array(
            'installed' => true,
            'status' => 'active',
            'updated_at' => date('c'),
            'synced_from_system' => true,
        )));
        return true;
    }

    public static function sync_all_probe_status()
    {
        foreach (array_keys(self::list()) as $proto) {
            self::sync_probe_status($proto);
        }
    }

    public static function get_status($proto)
    {
        if (self::probe_installed($proto)) {
            self::sync_probe_status($proto);
        }
        return self::read_status($proto);
    }

    public static function set_status($proto, $data)
    {
        $dir = USK_ROOT . '/data/protocols';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(self::status_file($proto), json_encode($data, JSON_PRETTY_PRINT));
    }

    public static function bin_path($proto)
    {
        return USK_ROOT . '/bin/install-' . $proto . '.sh';
    }

    public static function parse_ports($proto, array $input)
    {
        $meta = self::list()[$proto] ?? null;
        if (!$meta) {
            return array();
        }
        $ports = array();
        foreach ($meta['port_fields'] ?? array() as $field) {
            $key = $field['key'];
            $raw = $input['port_' . $key] ?? ($input[$key] ?? null);
            if ($raw === null || $raw === '') {
                $st = self::get_status($proto);
                if (isset($st[$key])) {
                    $val = (int) $st[$key];
                } else {
                    $val = (int) $field['default'];
                }
            } else {
                $val = (int) $raw;
            }
            if ($val < 1 || $val > 65535) {
                $val = (int) $field['default'];
            }
            $ports[$key] = $val;
        }
        return $ports;
    }

    public static function port_defaults_for_create($proto)
    {
        $meta = self::list()[$proto] ?? null;
        if (!$meta) {
            return array();
        }
        $st = self::get_status($proto);
        $out = array();
        foreach ($meta['port_fields'] ?? array() as $field) {
            $key = $field['key'];
            $out[$key] = isset($st[$key]) ? (int) $st[$key] : (int) $field['default'];
        }
        if (!empty($meta['fixed_ports'])) {
            $out['fixed_ports'] = $meta['fixed_ports'];
        }
        return $out;
    }

    public static function build_install_argv($proto, array $ports)
    {
        switch ($proto) {
            case 'xray':
                return escapeshellarg($ports['vless_port'] ?? 2053) . ' ' . escapeshellarg($ports['vmess_port'] ?? 8443);
            case 'openvpn':
                return escapeshellarg($ports['udp_port'] ?? 1194) . ' ' . escapeshellarg($ports['tcp_port'] ?? 443);
            case 'l2tp':
                return escapeshellarg(USK_ROOT);
            default:
                return isset($ports['port']) ? escapeshellarg($ports['port']) : '';
        }
    }

    private static function write_install_log($proto, $cmd, $out)
    {
        $dir = USK_ROOT . '/data/protocols';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $log = date('c') . "\nCMD: " . $cmd . "\n---\n" . (string) $out . "\n";
        @file_put_contents($dir . '/' . $proto . '-last.log', $log);
    }

    public static function install($proto, array $ports = array())
    {
        $allowed = array_keys(self::list());
        if (!in_array($proto, $allowed, true)) {
            return array('ok' => false, 'msg' => 'invalid_protocol');
        }
        $script = self::bin_path($proto);
        if (!file_exists($script)) {
            return array('ok' => false, 'msg' => 'script_missing');
        }

        if (empty($ports)) {
            $ports = self::parse_ports($proto, array());
        }

        $argv = self::build_install_argv($proto, $ports);
        $cmd = 'sudo -n bash ' . escapeshellarg($script);
        if ($argv !== '') {
            $cmd .= ' ' . $argv;
        }
        $cmd .= ' 2>&1';
        $prev = self::read_status($proto);
        $out = shell_exec($cmd);
        self::write_install_log($proto, $cmd, $out);
        if ($out === null || trim((string) $out) === '') {
            $out = 'USK_ERR: sudo_denied or empty output — check /etc/sudoers.d/unlimitsky for www-data';
        }
        $scriptOk = (strpos((string) $out, 'USK_OK') !== false);
        $systemOk = self::probe_installed($proto);
        $ok = $scriptOk || $systemOk;
        $warn = !$scriptOk && $systemOk;

        $status = array_merge($prev, self::default_status_fields($proto), array(
            'installed' => $ok,
            'status' => $ok ? 'active' : 'failed',
            'updated_at' => date('c'),
            'log' => substr((string) $out, -2000),
        ));
        if ($warn) {
            $status['last_install_warning'] = substr((string) $out, -500);
        } else {
            unset($status['last_install_warning']);
        }

        foreach ($ports as $k => $v) {
            $status[$k] = (int) $v;
        }

        if ($proto === 'xray' && preg_match('/USK_META:vless_port=(\d+);vmess_port=(\d+)/', $out, $m)) {
            $status['vless_port'] = (int) $m[1];
            $status['vmess_port'] = (int) $m[2];
            $status['firewall_note'] = 'Open TCP ' . $m[1] . ' and ' . $m[2] . ' in your VPS cloud firewall (security group).';
        } elseif ($proto === 'cisco' && preg_match('/USK_META:port=(\d+)/', $out, $m)) {
            $status['port'] = (int) $m[1];
            $status['firewall_note'] = 'Open TCP/UDP ' . $m[1] . ' in your VPS cloud firewall (security group).';
        } elseif ($proto === 'openvpn' && preg_match('/USK_META:udp_port=(\d+);tcp_port=(\d+)/', $out, $m)) {
            $status['udp_port'] = (int) $m[1];
            $status['tcp_port'] = (int) $m[2];
            $status['port'] = (int) $m[1];
            $status['firewall_note'] = 'Open UDP ' . $m[1] . ' and TCP ' . $m[2] . ' in your VPS cloud firewall.';
        } elseif ($proto === 'l2tp' && $ok) {
            $status['port'] = 1701;
            $status['firewall_note'] = 'Open UDP 500, 4500, and 1701 in your VPS cloud firewall (security group).';
        } elseif (isset($status['port'])) {
            $p = (int) $status['port'];
            $protoLabel = $proto === 'wireguard' ? 'UDP' : ($proto === 'openvpn' ? 'UDP' : 'TCP');
            if ($proto === 'wireguard' || $proto === 'openvpn') {
                $status['firewall_note'] = 'Open ' . $protoLabel . ' ' . $p . ' in your VPS cloud firewall (security group).';
            }
        }

        self::set_status($proto, $status);
        return array(
            'ok' => $ok,
            'warn' => $warn,
            'msg' => $ok ? ($warn ? 'installed_with_warning' : 'installed') : 'failed',
            'log' => $out,
        );
    }

    public static function installed_protocols()
    {
        $out = array();
        foreach (self::list() as $k => $meta) {
            $s = self::get_status($k);
            if (!empty($s['installed'])) {
                $out[$k] = $meta;
            }
        }
        return $out;
    }
}
