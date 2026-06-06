<?php

/**
 * Public hostname for VPN configs (domain pointing to VPS IP).
 * Stored separately from config.php domain (panel URL).
 */
class USK_ConnectHost
{
    private static function settings_file()
    {
        $dir = USK_ROOT . '/data/settings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/connect-host.json';
    }

    public static function defaults()
    {
        return array(
            'enabled' => false,
            'connect_host' => '',
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
        $cfg['connect_host'] = self::sanitize((string) ($input['connect_host'] ?? ''));
        $cfg['hint'] = trim((string) ($input['hint'] ?? ''));
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
        $raw = preg_replace('#^https?://#i', '', $raw);
        $raw = preg_replace('#/.*$#', '', $raw);
        $raw = preg_replace('#:\d+$#', '', $raw);
        $raw = strtolower(trim($raw, '. '));

        if (preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $raw)) {
            return $raw;
        }
        return '';
    }

    public static function detect_ip()
    {
        $ip = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print $1}'"));
        if ($ip !== '' && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $ip)) {
            return $ip;
        }
        $ip = trim((string) shell_exec("ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if(\$i==\"src\") print \$(i+1)}'"));
        if ($ip !== '' && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $ip)) {
            return $ip;
        }
        return '127.0.0.1';
    }

    /**
     * Host for new configs: API/manual override → panel domain setting → auto IP (empty = script detects).
     */
    public static function resolve($explicit = null)
    {
        if ($explicit !== null && $explicit !== '') {
            $clean = self::sanitize($explicit);
            if ($clean !== '') {
                return $clean;
            }
        }
        $cfg = self::get();
        if (!empty($cfg['enabled']) && ($cfg['connect_host'] ?? '') !== '') {
            return self::sanitize($cfg['connect_host']);
        }
        return '';
    }

    public static function display()
    {
        $cfg = self::get();
        if (!empty($cfg['enabled']) && ($cfg['connect_host'] ?? '') !== '') {
            return self::sanitize($cfg['connect_host']);
        }
        return self::detect_ip() . ' (' . __('settings_connect_host_auto_ip') . ')';
    }

    public static function is_enabled()
    {
        $cfg = self::get();
        return !empty($cfg['enabled']) && ($cfg['connect_host'] ?? '') !== '';
    }
}
