<?php

require_once dirname(__DIR__) . '/connect-host.php';

class USK_XrayLinks
{
    private static $realityCache = null;
    private static $portCache = null;

    public static function reality_params()
    {
        if (self::$realityCache !== null) {
            return self::$realityCache;
        }

        $paths = array(
            '/var/lib/unlimitsky/xray/reality.params',
            getenv('USK_DATA_ROOT') ? rtrim(getenv('USK_DATA_ROOT'), '/') . '/xray/reality.params' : '',
        );
        $out = array(
            'public_key' => '',
            'sni' => 'www.microsoft.com',
            'fingerprint' => 'chrome',
            'short_id' => '',
        );
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || !is_readable($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                if (strpos($line, '=') === false) {
                    continue;
                }
                list($k, $v) = explode('=', $line, 2);
                $k = strtoupper(trim($k));
                $v = trim($v);
                if ($k === 'REALITY_PUBLIC_KEY') {
                    $out['public_key'] = $v;
                } elseif ($k === 'REALITY_SNI') {
                    $out['sni'] = $v !== '' ? $v : $out['sni'];
                } elseif ($k === 'REALITY_FINGERPRINT') {
                    $out['fingerprint'] = $v !== '' ? $v : $out['fingerprint'];
                } elseif ($k === 'REALITY_SHORT_IDS') {
                    foreach (preg_split('/,/', $v) as $sid) {
                        $sid = trim($sid);
                        if ($sid !== '' && preg_match('/^[0-9a-fA-F]{2,16}$/', $sid)) {
                            $out['short_id'] = $sid;
                            break;
                        }
                    }
                }
            }
            if ($out['public_key'] !== '') {
                break;
            }
        }

        self::$realityCache = $out;
        return $out;
    }

    public static function vless_port()
    {
        if (self::$portCache !== null) {
            return self::$portCache;
        }
        $cfgPaths = array(
            '/usr/local/etc/xray/config.json',
            getenv('XRAY_CFG') ?: '',
        );
        $port = 443;
        foreach ($cfgPaths as $path) {
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
                $p = (int) ($inbound['port'] ?? 0);
                if ($p >= 1 && $p <= 65535) {
                    $port = $p;
                    break 2;
                }
            }
        }
        self::$portCache = $port;
        return $port;
    }

    public static function connect_host()
    {
        $host = USK_ConnectHost::resolve('');
        if ($host !== '') {
            return $host;
        }
        return USK_ConnectHost::detect_ip();
    }

    public static function build_uri($uuid, $label = 'user-vless', $host = null)
    {
        $uuid = trim((string) $uuid);
        if ($uuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            return '';
        }

        $reality = self::reality_params();
        if ($reality['public_key'] === '') {
            return '';
        }

        $host = $host !== null && $host !== '' ? (string) $host : self::connect_host();
        $port = self::vless_port();
        $sni = rawurlencode($reality['sni']);
        $fp = rawurlencode($reality['fingerprint']);
        $pbk = rawurlencode($reality['public_key']);
        $sid = rawurlencode($reality['short_id']);
        $name = rawurlencode(preg_replace('/[#?&]/', '_', (string) $label));

        return sprintf(
            'vless://%s@%s:%d?encryption=none&flow=xtls-rprx-vision&security=reality&sni=%s&fp=%s&pbk=%s&sid=%s&spx=%%2F&type=tcp#%s',
            $uuid,
            $host,
            $port,
            $sni,
            $fp,
            $pbk,
            $sid,
            $name
        );
    }

    public static function live_uri_for_client(array $rec, $username = '')
    {
        $uuid = trim((string) ($rec['uuid'] ?? ($rec['meta']['uuid'] ?? '')));
        if ($uuid === '') {
            return '';
        }
        $username = trim((string) ($username !== '' ? $username : ($rec['username'] ?? '')));
        $label = ($username !== '' ? $username : 'user') . '-vless';
        return self::build_uri($uuid, $label);
    }
}
