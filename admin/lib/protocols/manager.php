<?php

class USK_ProtocolManager
{
    public static function list()
    {
        return array(
            'wireguard' => array('name' => 'WireGuard', 'port' => 51820, 'icon' => 'fa-shield'),
            'openvpn'   => array('name' => 'OpenVPN', 'port' => 1194, 'icon' => 'fa-lock'),
            'xray'      => array('name' => 'Xray (VLESS/VMess)', 'port' => 443, 'icon' => 'fa-bolt'),
            'l2tp'      => array('name' => 'L2TP/IPsec', 'port' => 1701, 'icon' => 'fa-network-wired'),
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

    public static function install($proto)
    {
        $allowed = array_keys(self::list());
        if (!in_array($proto, $allowed, true)) {
            return array('ok' => false, 'msg' => 'invalid_protocol');
        }
        $script = self::bin_path($proto);
        if (!file_exists($script)) {
            return array('ok' => false, 'msg' => 'script_missing');
        }
        $cmd = 'sudo bash ' . escapeshellarg($script) . ' 2>&1';
        $out = shell_exec($cmd);
        $ok = (strpos($out, 'USK_OK') !== false);
        self::set_status($proto, array(
            'installed' => $ok,
            'status' => $ok ? 'active' : 'failed',
            'updated_at' => date('c'),
            'log' => substr($out, -2000),
        ));
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
