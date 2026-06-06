<?php

/**
 * Admin panel public URL: custom domain + HTTP port (nginx).
 * Separate from connect-host (VPN configs) and config auto-detection.
 */
class USK_PanelAccess
{
    private static function settings_file()
    {
        $dir = USK_ROOT . '/data/settings';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/panel-access.json';
    }

    public static function defaults()
    {
        return array(
            'domain_enabled' => false,
            'panel_domain' => '',
            'panel_port' => 8082,
            'hint' => '',
            'last_applied_url' => '',
            'last_apply_error' => '',
            'last_applied_at' => null,
            'updated_at' => null,
        );
    }

    public static function get()
    {
        $file = self::settings_file();
        if (!is_file($file)) {
            $cfg = self::defaults();
            $cfg['panel_port'] = self::detect_port();
            return $cfg;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return self::defaults();
        }
        return array_merge(self::defaults(), $data);
    }

    public static function save(array $input)
    {
        $cfg = self::get();
        $cfg['domain_enabled'] = !empty($input['domain_enabled']);
        $cfg['panel_domain'] = self::sanitize_domain((string) ($input['panel_domain'] ?? ''));
        $cfg['panel_port'] = self::sanitize_port($input['panel_port'] ?? $cfg['panel_port']);
        $cfg['hint'] = trim((string) ($input['hint'] ?? ''));
        $cfg['updated_at'] = date('c');
        file_put_contents(
            self::settings_file(),
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $cfg;
    }

    public static function sanitize_domain($raw)
    {
        return USK_ConnectHost::sanitize($raw);
    }

    public static function sanitize_port($raw)
    {
        $port = (int) $raw;
        if ($port < 1024 || $port > 65535) {
            return 8082;
        }
        return $port;
    }

    public static function apply_script()
    {
        return USK_ROOT . '/bin/apply-panel-access.sh';
    }

    public static function detect_port()
    {
        $fromNginx = self::detect_nginx_port();
        if ($fromNginx > 0) {
            return $fromNginx;
        }
        if (!empty($_SERVER['SERVER_PORT'])) {
            $p = (int) $_SERVER['SERVER_PORT'];
            if ($p >= 1024 && $p <= 65535) {
                return $p;
            }
        }
        global $config;
        $domain = (string) ($config['domain'] ?? '');
        if ($domain !== '' && preg_match('#:(\d+)(?:/|$)#', $domain, $m)) {
            return self::sanitize_port($m[1]);
        }
        return 8082;
    }

    public static function detect_nginx_port()
    {
        $site = self::detect_nginx_site_file();
        if ($site === '' || !is_readable($site)) {
            return 0;
        }
        $content = file_get_contents($site);
        if ($content !== false && preg_match('/^\s*listen\s+(\d+)\s*;/m', $content, $m)) {
            return self::sanitize_port($m[1]);
        }
        return 0;
    }

    public static function detect_nginx_site_file()
    {
        $applied = USK_ROOT . '/data/settings/panel-access-applied.json';
        if (is_file($applied)) {
            $data = json_decode(file_get_contents($applied), true);
            if (!empty($data['nginx_site']) && is_file($data['nginx_site'])) {
                return (string) $data['nginx_site'];
            }
        }
        $candidates = array(
            '/etc/nginx/sites-available/unlimitsky-client',
        );
        $root = USK_ROOT;
        $out = trim((string) shell_exec(
            'grep -rl ' . escapeshellarg('root ' . $root . ';') . ' /etc/nginx/sites-available/ 2>/dev/null | head -1'
        ));
        if ($out !== '' && is_file($out)) {
            $candidates[] = $out;
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }

    public static function current_public_url()
    {
        global $config;
        $domain = trim((string) ($config['domain'] ?? ''));
        if ($domain !== '' && strpos($domain, '[*') !== 0) {
            return rtrim($domain, '/');
        }
        $cfg = self::get();
        return self::build_public_url(
            !empty($cfg['domain_enabled']) ? ($cfg['panel_domain'] ?? '') : '',
            (int) ($cfg['panel_port'] ?? self::detect_port())
        );
    }

    public static function admin_login_url()
    {
        return self::current_public_url() . '/admin/login.php';
    }

    public static function build_public_url($panelDomain, $port)
    {
        $port = self::sanitize_port($port);
        $host = self::sanitize_domain($panelDomain);
        if ($host === '') {
            $host = USK_ConnectHost::detect_ip();
        }
        return 'http://' . $host . ':' . $port;
    }

    public static function can_apply_from_web()
    {
        return is_file(self::apply_script());
    }

    /**
     * @return array{ok:bool,error?:string,public_url?:string,admin_url?:string,port?:int}
     */
    public static function apply(array $cfg = null)
    {
        if ($cfg === null) {
            $cfg = self::get();
        }

        $port = self::sanitize_port($cfg['panel_port'] ?? 8082);
        $domain = !empty($cfg['domain_enabled']) ? self::sanitize_domain($cfg['panel_domain'] ?? '') : '';
        if (!empty($cfg['domain_enabled']) && $domain === '') {
            return array('ok' => false, 'error' => 'panel_domain_required');
        }

        $script = self::apply_script();
        if (!is_file($script)) {
            return array('ok' => false, 'error' => 'apply_script_missing');
        }

        $cmd = 'sudo -n bash ' . escapeshellarg($script) . ' '
            . escapeshellarg(USK_ROOT) . ' '
            . escapeshellarg((string) $port) . ' '
            . escapeshellarg($domain) . ' 2>&1';
        $out = shell_exec($cmd);
        if ($out === null || trim($out) === '') {
            return array('ok' => false, 'error' => 'apply_failed_empty', 'log' => '');
        }

        if (strpos($out, 'USK_ERR:') !== false) {
            $err = 'apply_failed';
            if (preg_match('/USK_ERR:\s*(.+)/', $out, $m)) {
                $err = trim($m[1]);
            }
            self::record_apply_error($err, $out);
            return array('ok' => false, 'error' => $err, 'log' => $out);
        }

        if (!preg_match('/USK_JSON:(.+)$/s', $out, $m)) {
            self::record_apply_error('invalid_apply_output', $out);
            return array('ok' => false, 'error' => 'invalid_apply_output', 'log' => $out);
        }

        $data = json_decode(trim($m[1]), true);
        if (!is_array($data) || empty($data['ok'])) {
            self::record_apply_error('apply_parse_error', $out);
            return array('ok' => false, 'error' => 'apply_parse_error', 'log' => $out);
        }

        $saved = self::get();
        $saved['panel_port'] = (int) ($data['port'] ?? $port);
        $saved['last_applied_url'] = (string) ($data['public_url'] ?? '');
        $saved['last_apply_error'] = '';
        $saved['last_applied_at'] = date('c');
        file_put_contents(
            self::settings_file(),
            json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return array(
            'ok' => true,
            'public_url' => (string) ($data['public_url'] ?? ''),
            'admin_url' => (string) ($data['admin_url'] ?? ''),
            'port' => (int) ($data['port'] ?? $port),
            'panel_domain' => (string) ($data['panel_domain'] ?? $domain),
        );
    }

    private static function record_apply_error($code, $log = '')
    {
        $cfg = self::get();
        $cfg['last_apply_error'] = $code;
        $cfg['updated_at'] = date('c');
        file_put_contents(
            self::settings_file(),
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public static function error_label($code)
    {
        $map = array(
            'panel_domain_required' => 'settings_panel_access_err_domain',
            'apply_script_missing' => 'settings_panel_access_err_script',
            'nginx_site_not_found' => 'settings_panel_access_err_nginx',
            'invalid_port' => 'settings_panel_access_err_port',
            'nginx_test_failed' => 'settings_panel_access_err_nginx_test',
            'config_update_failed' => 'settings_panel_access_err_config',
            'config_domain_key_missing' => 'settings_panel_access_err_config',
            'config_missing' => 'settings_panel_access_err_config',
            'sudo_denied' => 'err_sudo_denied',
            'apply_failed_empty' => 'settings_panel_access_err_apply',
            'invalid_apply_output' => 'err_sudo_denied',
        );
        $key = isset($map[$code]) ? $map[$code] : 'settings_panel_access_err_apply';
        return __($key, $code);
    }
}
