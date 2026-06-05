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
                    array('key' => 'port', 'label' => 'Port (UDP)', 'default' => 1194),
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

    public static function get_status($proto)
    {
        $f = self::status_file($proto);
        if (!file_exists($f)) {
            return array('installed' => false, 'status' => 'not_installed');
        }
        $d = json_decode(file_get_contents($f), true);
        return is_array($d) ? $d : array('installed' => false);
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
            case 'l2tp':
                return '';
            default:
                return isset($ports['port']) ? escapeshellarg($ports['port']) : '';
        }
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
        $out = shell_exec($cmd);
        $ok = (strpos($out, 'USK_OK') !== false);
        $status = array(
            'installed' => $ok,
            'status' => $ok ? 'active' : 'failed',
            'updated_at' => date('c'),
            'log' => substr($out, -2000),
        );

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
        } elseif (isset($status['port'])) {
            $p = (int) $status['port'];
            $protoLabel = $proto === 'wireguard' ? 'UDP' : ($proto === 'openvpn' ? 'UDP' : 'TCP');
            if ($proto === 'wireguard' || $proto === 'openvpn') {
                $status['firewall_note'] = 'Open ' . $protoLabel . ' ' . $p . ' in your VPS cloud firewall (security group).';
            }
        }

        self::set_status($proto, $status);
        return array('ok' => $ok, 'msg' => $ok ? 'installed' : 'failed', 'log' => $out);
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
