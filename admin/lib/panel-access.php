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
            'https_enabled' => false,
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
        $cfg['https_enabled'] = !empty($input['https_enabled']);
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
        if (!class_exists('USK_ConnectHost')) {
            require_once __DIR__ . '/connect-host.php';
        }
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

    /** True when host is an IPv4 address (not a custom domain name). */
    public static function is_ip_host($host)
    {
        $host = trim((string) $host);
        return $host !== '' && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $host) === 1;
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

    public static function is_domain_locked()
    {
        $cfg = self::get();
        if (empty($cfg['domain_enabled'])) {
            return false;
        }
        if (self::sanitize_domain($cfg['panel_domain'] ?? '') === '') {
            return false;
        }
        return !empty($cfg['last_applied_at']);
    }

    public static function allowed_panel_host()
    {
        $cfg = self::get();
        if (empty($cfg['domain_enabled'])) {
            return '';
        }
        return self::sanitize_domain($cfg['panel_domain'] ?? '');
    }

    /**
     * Block IP:port access when panel domain is active (nginx + PHP fallback).
     */
    public static function enforce_request_host()
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/install/') !== false) {
            return;
        }

        if (!self::is_domain_locked()) {
            return;
        }

        $allowed = self::allowed_panel_host();
        if ($allowed === '') {
            return;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('#:\d+$#', '', $host);

        if ($host === $allowed) {
            return;
        }
        if ($host === 'www.' . $allowed) {
            return;
        }

        self::deny_direct_access();
    }

    private static function deny_direct_access()
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $domain = self::allowed_panel_host();
        $cfg = self::get();
        $scheme = !empty($cfg['https_enabled']) ? 'https' : 'http';
        $url = self::build_public_url($domain, (int) ($cfg['panel_port'] ?? 8082), !empty($cfg['https_enabled']), true);
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>403</title></head><body style="font-family:tahoma;padding:40px;text-align:center;">';
        echo '<h2>403 — دسترسی مستقیم با IP غیرفعال است</h2>';
        echo '<p>فقط از دامنه پنل وارد شوید:</p>';
        echo '<p><a href="' . htmlspecialchars($url . '/admin/login.php', ENT_QUOTES, 'UTF-8') . '" dir="ltr">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a></p>';
        echo '</body></html>';
        exit;
    }

    public static function current_public_url()
    {
        global $config;
        $cfg = self::get();
        $port = (int) ($cfg['panel_port'] ?? self::detect_port());
        $panelDomain = self::sanitize_domain($cfg['panel_domain'] ?? '');

        if (!empty($cfg['domain_enabled']) && $panelDomain !== '') {
            return self::build_public_url(
                $panelDomain,
                $port,
                !empty($cfg['https_enabled']),
                true
            );
        }

        $stored = trim((string) ($config['domain'] ?? ''));
        if ($stored !== '' && strpos($stored, '[*') !== 0) {
            $parsed = parse_url($stored);
            $host = is_array($parsed) ? (string) ($parsed['host'] ?? '') : '';
            if ($host !== '') {
                $urlPort = !empty($parsed['port']) ? (int) $parsed['port'] : $port;
                $domainMode = !empty($cfg['domain_enabled'])
                    && $panelDomain !== ''
                    && !self::is_ip_host($host);
                return self::build_public_url(
                    $host,
                    $urlPort,
                    !empty($cfg['https_enabled']),
                    $domainMode
                );
            }
            return rtrim($stored, '/');
        }

        return self::build_public_url(
            $panelDomain,
            $port,
            !empty($cfg['https_enabled']),
            !empty($cfg['domain_enabled']) && $panelDomain !== ''
        );
    }

    /** Rebuild path/query of a URL using current panel-access scheme and host. */
    public static function normalize_public_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return $url;
        }

        $base = rtrim(self::current_public_url(), '/');
        if ($base === '') {
            return $url;
        }

        $path = (string) ($parsed['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? ('?' . $parsed['query']) : '';
        $fragment = isset($parsed['fragment']) && $parsed['fragment'] !== '' ? ('#' . $parsed['fragment']) : '';

        return $base . $path . $query . $fragment;
    }

    public static function admin_login_url()
    {
        return self::current_public_url() . '/admin/login.php';
    }

    public static function uses_public_http_mirror()
    {
        $applied = USK_ROOT . '/data/settings/panel-access-applied.json';
        if (is_file($applied)) {
            $data = json_decode(file_get_contents($applied), true);
            if (is_array($data) && array_key_exists('public_http_mirror', $data)) {
                return !empty($data['public_http_mirror']);
            }
        }
        $cfg = self::get();
        $port = (int) ($cfg['panel_port'] ?? self::detect_port());
        return !empty($cfg['domain_enabled'])
            && self::sanitize_domain($cfg['panel_domain'] ?? '') !== ''
            && !in_array($port, array(80, 443), true);
    }

    public static function build_public_url($panelDomain, $port, $https = false, $domainMode = false)
    {
        $port = self::sanitize_port($port);
        $host = self::sanitize_domain($panelDomain);
        if ($host === '') {
            if (!class_exists('USK_ConnectHost')) {
                require_once __DIR__ . '/connect-host.php';
            }
            $host = USK_ConnectHost::detect_ip();
            return 'http://' . $host . ':' . $port;
        }

        // IP access always needs explicit port (e.g. :8082) — mirror80 is for custom domains only.
        if (self::is_ip_host($host)) {
            $scheme = ($https && $port === 443) ? 'https' : 'http';
            if (($https && $port === 443) || (!$https && $port === 80)) {
                return $scheme . '://' . $host;
            }
            return $scheme . '://' . $host . ':' . $port;
        }

        $mirror80 = $domainMode && !in_array($port, array(80, 443), true);

        if ($domainMode && $https && ($port === 443 || $port === 80 || $mirror80)) {
            return 'https://' . $host;
        }
        if ($domainMode && ($port === 80 || $mirror80)) {
            return 'http://' . $host;
        }
        if ($domainMode) {
            return 'http://' . $host . ':' . $port;
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

        $https = !empty($cfg['https_enabled']) ? '1' : '0';
        $lockDomain = (!empty($cfg['domain_enabled']) && $domain !== '') ? '1' : '0';

        $script = self::apply_script();
        if (!is_file($script)) {
            return array('ok' => false, 'error' => 'apply_script_missing');
        }

        require_once __DIR__ . '/sudo-runner.php';
        $cmd = USK_SudoRunner::cmd_rel('bin/apply-panel-access.sh', array(
            USK_ROOT,
            (string) $port,
            $domain,
            $https,
            $lockDomain,
        )) . ' 2>&1';
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

    /**
     * Update config.php domain (same logic as apply-panel-access.sh).
     *
     * @return array{ok:bool,error?:string,public_url?:string}
     */
    public static function update_config_domain($publicUrl)
    {
        $publicUrl = rtrim(trim((string) $publicUrl), '/');
        if ($publicUrl === '' || strpos($publicUrl, '[*') === 0) {
            return array('ok' => false, 'error' => 'invalid_url');
        }

        $configFile = USK_ROOT . '/config.php';
        if (!is_file($configFile)) {
            return array('ok' => false, 'error' => 'config_missing');
        }

        $content = file_get_contents($configFile);
        if ($content === false || !preg_match("/(['\"]domain['\"]\s*=>\s*['\"])([^'\"]*)(['\"])/", $content)) {
            return array('ok' => false, 'error' => 'config_domain_key_missing');
        }

        $escaped = addslashes($publicUrl);
        $updated = preg_replace(
            "/(['\"]domain['\"]\s*=>\s*['\"])([^'\"]*)(['\"])/",
            '$1' . $escaped . '$3',
            $content,
            1
        );
        if ($updated === null || file_put_contents($configFile, $updated) === false) {
            return array('ok' => false, 'error' => 'config_write_failed');
        }
        @chmod($configFile, 0640);

        global $config;
        if (is_array($config)) {
            $config['domain'] = $publicUrl;
        }

        return array('ok' => true, 'public_url' => $publicUrl);
    }

    /** Public URL derived from saved panel-access settings (no nginx apply). */
    public static function public_url_from_settings(array $cfg = null)
    {
        if ($cfg === null) {
            $cfg = self::get();
        }
        $port = self::sanitize_port($cfg['panel_port'] ?? self::detect_port());
        $domain = !empty($cfg['domain_enabled']) ? self::sanitize_domain($cfg['panel_domain'] ?? '') : '';
        return self::build_public_url($domain, $port, !empty($cfg['https_enabled']), $domain !== '');
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
            'php_fpm_socket_not_found' => 'settings_panel_access_err_nginx',
            'sudo_denied' => 'err_sudo_denied',
            'apply_failed_empty' => 'settings_panel_access_err_apply',
            'invalid_apply_output' => 'err_sudo_denied',
        );
        $key = isset($map[$code]) ? $map[$code] : 'settings_panel_access_err_apply';
        return __($key, $code);
    }
}
