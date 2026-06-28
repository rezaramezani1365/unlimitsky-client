<?php

class USK_ClientDns
{
    private static function settings_file()
    {
        $dir = USK_ROOT . '/data/settings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/client-dns.json';
    }

    public static function defaults()
    {
        return array(
            'enabled' => false,
            'default_dns' => '',
            'xray_dns' => '',
            'amnezia_dns' => '',
            'openvpn_dns' => '',
            'wireguard_dns' => '',
            'hint' => '',
            'updated_at' => null,
        );
    }

    public static function get()
    {
        $file = self::settings_file();
        if (!is_file($file)) {
            return self::defaults();
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return self::defaults();
        }
        return array_merge(self::defaults(), $data);
    }

    public static function save(array $input)
    {
        $cfg = self::defaults();
        $cfg['enabled'] = !empty($input['enabled']);
        foreach (array('default_dns', 'xray_dns', 'amnezia_dns', 'openvpn_dns', 'wireguard_dns', 'hint') as $key) {
            $cfg[$key] = self::sanitize((string) ($input[$key] ?? ''));
        }
        $cfg['updated_at'] = date('c');
        file_put_contents(
            self::settings_file(),
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $cfg;
    }

    public static function sanitize($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $part)) {
                $out[] = $part;
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9.-]+$/', $part)) {
                $out[] = $part;
            }
        }
        return implode(', ', $out);
    }

    public static function is_enabled()
    {
        $cfg = self::get();
        return !empty($cfg['enabled']);
    }

    /**
     * Effective DNS for provisioning: explicit (manual/API) overrides panel default.
     */
    public static function resolve($explicit = '', $protocol = '')
    {
        $explicit = self::sanitize($explicit);
        if ($explicit !== '') {
            return $explicit;
        }
        $cfg = self::get();
        if (empty($cfg['enabled'])) {
            return '';
        }
        $protocol = strtolower(trim((string) $protocol));
        $protoKey = $protocol . '_dns';
        if ($protocol !== '' && !empty($cfg[$protoKey])) {
            return self::sanitize($cfg[$protoKey]);
        }
        return self::sanitize($cfg['default_dns'] ?? '');
    }

    public static function display_for_protocol($protocol = '')
    {
        return self::resolve('', $protocol);
    }
}
