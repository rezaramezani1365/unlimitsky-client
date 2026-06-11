<?php

class USK_License
{
    const FREE_MAX_PLANS = 1;
    const CACHE_TTL = 14400;
    const GRACE_OFFLINE = 86400;
    const SIGN_SALT = 'USK-LIC-v2';
    const DEFAULT_VENDOR_HOST = 'vendor.iranip.online:8081';
    const DEFAULT_VENDOR_LICENSE_URL = 'http://vendor.iranip.online:8081/api/v1.php';

    private static $booted = false;

    /** @var array{url:string,token:string,source:string}|null */
    private static $peerVendorCache = null;

    const PRESENCE_SYNC_INTERVAL = 900;

    private static function file_path()
    {
        return dirname(__DIR__) . '/data/license.json';
    }

    private static function root_dir()
    {
        return defined('USK_ROOT') ? USK_ROOT : dirname(__DIR__, 2);
    }

    public static function instance_id()
    {
        $id_file = dirname(__DIR__) . '/data/instance.id';
        if (file_exists($id_file)) {
            return trim(file_get_contents($id_file));
        }
        $id = hash('sha256', php_uname('n') . '|' . (isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '') . '|' . self::root_dir());
        @file_put_contents($id_file, $id);
        return $id;
    }

    private static function api_token()
    {
        $peer = self::resolve_peer_vendor();
        return $peer['token'] ?? '';
    }

    private static function license_server_url()
    {
        $peer = self::resolve_peer_vendor();
        return $peer['url'] ?? '';
    }

    public static function vendor_configured()
    {
        return self::license_server_url() !== '' && self::api_token() !== '';
    }

    public static function vendor_config_source()
    {
        $peer = self::resolve_peer_vendor();
        return $peer['source'] ?? '';
    }

    public static function default_vendor_host()
    {
        return self::DEFAULT_VENDOR_HOST;
    }

    public static function default_vendor_license_url()
    {
        return self::DEFAULT_VENDOR_LICENSE_URL;
    }

    private static function is_placeholder_value($value)
    {
        $value = trim((string) $value);
        return $value === '' || strpos($value, '[*') === 0;
    }

    private static function resolve_vendor_url($url)
    {
        $url = trim((string) $url);
        if (self::is_placeholder_value($url)) {
            return self::DEFAULT_VENDOR_LICENSE_URL;
        }
        $original = $url;
        $url = self::normalize_license_api_url(self::fix_legacy_vendor_license_url($url));
        if ($url !== '' && $url !== self::normalize_license_api_url($original)) {
            self::maybe_migrate_stored_vendor_url($original, $url);
        }
        return $url;
    }

    /** Upgrade installs that saved vendor.iranip.online without :8081 (implicit port 80). */
    private static function fix_legacy_vendor_license_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $parsed = parse_url($url);
        if (!is_array($parsed)) {
            return $url;
        }
        $host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        if ($host !== 'vendor.iranip.online') {
            return $url;
        }
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 80;
        if ($port !== 80) {
            return $url;
        }
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : 'http://';
        $path = isset($parsed['path']) ? (string) $parsed['path'] : '';
        return $scheme . 'vendor.iranip.online:8081' . $path;
    }

    private static function maybe_migrate_stored_vendor_url($oldUrl, $newUrl)
    {
        $oldNorm = self::normalize_license_api_url(self::fix_legacy_vendor_license_url($oldUrl));
        if ($oldNorm === $newUrl) {
            return;
        }

        $jsonPath = self::root_dir() . '/data/license-vendor.json';
        if (is_readable($jsonPath)) {
            $data = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($data)) {
                $stored = trim((string) ($data['license_server'] ?? ($data['url'] ?? '')));
                if ($stored !== '' && self::normalize_license_api_url(self::fix_legacy_vendor_license_url($stored)) !== $newUrl) {
                    $data['license_server'] = $newUrl;
                    $data['updated_at'] = date('c');
                    @file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $configFile = self::root_dir() . '/config.php';
        if (!is_file($configFile) || !is_writable($configFile)) {
            return;
        }
        $content = file_get_contents($configFile);
        if ($content === false) {
            return;
        }
        $escapedNew = addslashes($newUrl);
        $updated = preg_replace(
            "/(['\"]license_server['\"]\s*=>\s*['\"])([^'\"]*)(['\"])/",
            '$1' . $escapedNew . '$3',
            $content,
            1
        );
        if ($updated !== null && $updated !== $content) {
            @file_put_contents($configFile, $updated);
            global $config;
            if (is_array($config)) {
                $config['license_server'] = $newUrl;
            }
        }
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function save_vendor_token($token)
    {
        $token = trim((string) $token);
        if ($token === '' || self::is_placeholder_value($token)) {
            return array('ok' => false, 'error' => 'vendor_token_required');
        }

        $dir = self::root_dir() . '/data';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return array('ok' => false, 'error' => 'vendor_token_write_failed');
        }

        $path = $dir . '/license-vendor.json';
        $payload = array(
            'license_server' => self::DEFAULT_VENDOR_LICENSE_URL,
            'api_token' => $token,
            'updated_at' => date('c'),
        );
        if (@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            return array('ok' => false, 'error' => 'vendor_token_write_failed');
        }
        @chmod($path, 0640);

        self::update_config_vendor_credentials($token);
        self::$peerVendorCache = null;

        return array('ok' => true);
    }

    private static function update_config_vendor_credentials($token)
    {
        $configFile = self::root_dir() . '/config.php';
        if (!is_file($configFile) || !is_writable($configFile)) {
            return;
        }

        $content = file_get_contents($configFile);
        if ($content === false) {
            return;
        }

        $escapedToken = addslashes($token);
        $escapedUrl = addslashes(self::DEFAULT_VENDOR_LICENSE_URL);
        $updated = preg_replace(
            "/(['\"]license_api_token['\"]\s*=>\s*['\"])([^'\"]*)(['\"])/",
            '$1' . $escapedToken . '$3',
            $content,
            1
        );
        if ($updated !== null) {
            $content = $updated;
        }
        $updated = preg_replace(
            "/(['\"]license_server['\"]\s*=>\s*['\"])([^'\"]*)(['\"])/",
            '$1' . $escapedUrl . '$3',
            $content,
            1
        );
        if ($updated !== null) {
            $content = $updated;
        }

        if (@file_put_contents($configFile, $content) !== false) {
            global $config;
            if (is_array($config)) {
                $config['license_api_token'] = $token;
                $config['license_server'] = self::DEFAULT_VENDOR_LICENSE_URL;
            }
        }
    }

    /** @return array{url:string,token:string,source:string}|null */
    private static function resolve_peer_vendor()
    {
        if (self::$peerVendorCache !== null) {
            return self::$peerVendorCache['token'] !== '' ? self::$peerVendorCache : null;
        }

        $url = '';
        $token = '';
        $source = '';

        global $config;
        $cfgUrl = isset($config['license_server']) ? trim((string) $config['license_server']) : '';
        $cfgToken = isset($config['license_api_token']) ? trim((string) $config['license_api_token']) : '';
        if (!self::is_placeholder_value($cfgToken)) {
            $token = $cfgToken;
            $source = 'config.php';
            if (!self::is_placeholder_value($cfgUrl)) {
                $url = $cfgUrl;
            }
        }

        if ($token === '') {
            foreach (self::peer_vendor_json_paths() as $path) {
                $parsed = self::parse_vendor_json_file($path);
                if ($parsed !== null) {
                    $token = $parsed['token'];
                    $source = $parsed['source'];
                    if ($url === '' && $parsed['url'] !== '') {
                        $url = $parsed['url'];
                    }
                    break;
                }
            }
        }

        if ($token === '') {
            foreach (self::peer_vendor_config_paths() as $path) {
                $parsed = self::parse_vendor_config_php($path);
                if ($parsed !== null) {
                    $token = $parsed['token'];
                    $source = $parsed['source'];
                    if ($url === '' && $parsed['url'] !== '') {
                        $url = $parsed['url'];
                    }
                    break;
                }
            }
        }

        if ($token === '') {
            self::$peerVendorCache = array('url' => '', 'token' => '', 'source' => '');
            return null;
        }

        self::$peerVendorCache = array(
            'url' => self::resolve_vendor_url($url),
            'token' => $token,
            'source' => $source,
        );
        return self::$peerVendorCache;
    }

    /** @return string[] */
    private static function peer_vendor_config_paths()
    {
        return array(
            '/var/www/unlimitsky-license/config.php',
            dirname(self::root_dir()) . '/unlimitsky-license/config.php',
        );
    }

    private static function normalize_license_api_url($domainOrApiUrl)
    {
        $url = rtrim(trim((string) $domainOrApiUrl), '/');
        if ($url === '') {
            return '';
        }
        if (preg_match('#/api/v1\.php$#', $url)) {
            return $url;
        }
        return $url . '/api/v1.php';
    }

    /** @return array{url:string,token:string,source:string}|null */
    private static function parse_vendor_config_php($path)
    {
        if (!is_readable($path)) {
            return null;
        }
        $cfg = @include $path;
        if (!is_array($cfg)) {
            return null;
        }
        $domain = rtrim(trim((string) ($cfg['domain'] ?? '')), '/');
        $token = trim((string) ($cfg['api_secret'] ?? ''));
        if ($domain === '' || $token === '') {
            return null;
        }
        return array(
            'url' => self::normalize_license_api_url($domain),
            'token' => $token,
            'source' => basename(dirname($path)) . '/config.php',
        );
    }

    /** @return string[] */
    private static function peer_vendor_json_paths()
    {
        return array(
            self::root_dir() . '/data/license-vendor.json',
            '/var/www/unlimitsky-license/data/reseller-api.json',
            '/var/lib/unlimitsky/reseller-api.json',
        );
    }

    /** @return array{url:string,token:string,source:string}|null */
    private static function parse_vendor_json_file($path)
    {
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $url = trim((string) ($data['license_server'] ?? ($data['url'] ?? '')));
        $token = trim((string) ($data['api_token'] ?? ($data['token'] ?? '')));
        if ($url === '' || $token === '') {
            return null;
        }
        return array('url' => self::normalize_license_api_url($url), 'token' => $token, 'source' => basename(dirname($path)) . '/' . basename($path));
    }

    private static function presence_state_file()
    {
        return dirname(__DIR__) . '/data/vendor-presence.json';
    }

    /** @return array<string,mixed> */
    public static function last_presence_sync()
    {
        $f = self::presence_state_file();
        if (!is_file($f)) {
            return array();
        }
        $data = json_decode((string) file_get_contents($f), true);
        return is_array($data) ? $data : array();
    }

    private static function save_presence_sync(array $res)
    {
        $dir = dirname(self::presence_state_file());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(self::presence_state_file(), json_encode(array(
            'synced_at' => date('c'),
            'ok' => !empty($res['ok']),
            'error' => (string) ($res['error'] ?? ''),
            'server_ip' => (string) ($res['server_ip'] ?? ''),
            'source' => self::vendor_config_source(),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function presence_sync_due()
    {
        $last = self::last_presence_sync();
        $at = isset($last['synced_at']) ? strtotime($last['synced_at']) : 0;
        return $at < time() - self::PRESENCE_SYNC_INTERVAL;
    }

    /**
     * Tell vendor this client panel exists (Installations list). Throttled unless $force.
     * @return array<string,mixed>|null
     */
    public static function sync_presence_with_vendor($force = false)
    {
        if (!$force && !self::presence_sync_due()) {
            return null;
        }
        if (!self::vendor_configured()) {
            return array('ok' => false, 'error' => 'license_server_not_configured');
        }
        $res = self::api_request('register');
        self::save_presence_sync(is_array($res) ? $res : array('ok' => false, 'error' => 'invalid_response'));
        return $res;
    }

    private static function sign_secret()
    {
        $token = self::api_token();
        if ($token === '') {
            return '';
        }
        return hash('sha256', $token . '|' . self::instance_id() . '|' . self::SIGN_SALT, true);
    }

    private static function cache_payload(array $data)
    {
        return implode('|', array(
            (string) ($data['tier'] ?? 'free'),
            strtoupper((string) ($data['license_key'] ?? '')),
            (string) (int) ($data['max_plans'] ?? self::FREE_MAX_PLANS),
            (string) ($data['expires_at'] ?? ''),
            (string) ($data['last_check'] ?? ''),
            (string) ($data['bound_ip'] ?? ''),
            self::instance_id(),
        ));
    }

    private static function sign_cache(array $data)
    {
        $secret = self::sign_secret();
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', self::cache_payload($data), $secret);
    }

    private static function verify_cache(array $data)
    {
        if (empty($data['sig']) || empty($data['license_key'])) {
            return false;
        }
        $secret = self::sign_secret();
        if ($secret === '') {
            return false;
        }
        if (($data['instance_id'] ?? '') !== self::instance_id()) {
            return false;
        }
        $check = $data;
        unset($check['sig']);
        return hash_equals(self::sign_cache($check), (string) $data['sig']);
    }

    public static function verify_vendor_proof(array $res, $license_key)
    {
        if (empty($res['proof']) || empty($res['ok'])) {
            return false;
        }
        $token = self::api_token();
        if ($token === '') {
            return false;
        }
        $payload = implode('|', array(
            strtoupper(trim((string) $license_key)),
            self::instance_id(),
            (string) (int) ($res['max_plans'] ?? 0),
            (string) ($res['expires_at'] ?? ''),
            (string) ($res['tier'] ?? 'pro'),
            (string) ($res['bound_ip'] ?? ''),
        ));
        return hash_equals(hash_hmac('sha256', $payload, $token), (string) $res['proof']);
    }

    private static function free_defaults()
    {
        return array(
            'tier' => 'free',
            'max_plans' => self::FREE_MAX_PLANS,
            'instance_id' => self::instance_id(),
            'license_key' => '',
        );
    }

    private static function invalidate($reason = 'invalid')
    {
        @unlink(self::file_path());
        return self::free_defaults();
    }

    public static function get()
    {
        $f = self::file_path();
        if (!file_exists($f)) {
            return self::free_defaults();
        }
        $d = json_decode(file_get_contents($f), true);
        if (!is_array($d)) {
            return self::invalidate('corrupt');
        }
        if (!self::verify_cache($d)) {
            if (!empty($d['license_key']) && self::api_token() !== '') {
                $res = self::api_request('validate', $d['license_key']);
                if (!empty($res['ok']) && self::apply_vendor_ok($res, $d['license_key'])) {
                    return self::get();
                }
            }
            return self::invalidate('tampered');
        }
        return $d;
    }

    private static function save(array $data)
    {
        $dir = dirname(self::file_path());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $data['instance_id'] = self::instance_id();
        $data['sig'] = self::sign_cache($data);
        file_put_contents(self::file_path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod(self::file_path(), 0640);
    }

    private static function apply_vendor_ok(array $res, $license_key)
    {
        if (!self::verify_vendor_proof($res, $license_key)) {
            return false;
        }
        $existing = file_exists(self::file_path())
            ? json_decode(file_get_contents(self::file_path()), true)
            : array();
        if (!is_array($existing)) {
            $existing = array();
        }
        self::save(array(
            'tier' => 'pro',
            'license_key' => strtoupper(trim($license_key)),
            'max_plans' => (int) ($res['max_plans'] ?? 999),
            'expires_at' => isset($res['expires_at']) ? $res['expires_at'] : null,
            'bound_ip' => isset($res['bound_ip']) ? $res['bound_ip'] : ($existing['bound_ip'] ?? ''),
            'activated_at' => $existing['activated_at'] ?? date('c'),
            'last_check' => date('c'),
            'suspended_plan_ids' => $existing['suspended_plan_ids'] ?? array(),
            'instance_id' => self::instance_id(),
        ));
        return true;
    }

    public static function is_pro()
    {
        $d = self::get();
        if (($d['tier'] ?? 'free') !== 'pro') {
            return false;
        }
        if (!empty($d['expires_at']) && strtotime($d['expires_at']) < time()) {
            return false;
        }
        if (empty($d['last_check']) || strtotime($d['last_check']) < time() - self::CACHE_TTL - self::GRACE_OFFLINE) {
            return false;
        }
        return true;
    }

    public static function max_plans()
    {
        if (self::is_pro()) {
            $d = self::get();
            return max(self::FREE_MAX_PLANS, (int) ($d['max_plans'] ?? 999));
        }
        return self::FREE_MAX_PLANS;
    }

    public static function current_plan_count()
    {
        global $sql;
        $r = $sql->query('SELECT COUNT(*) c FROM `category`');
        return $r ? (int) $r->fetch_assoc()['c'] : 0;
    }

    public static function can_add_plan()
    {
        return self::current_plan_count() < self::max_plans();
    }

    /** Marzban / Sanaei external panels — Pro only */
    public static function can_use_external_panels()
    {
        return self::is_pro();
    }

    public static function assert_can_use_external_panels($force_online = false)
    {
        if ($force_online) {
            self::refresh_from_vendor(true);
        } else {
            self::validate_cached();
        }
        if (!self::can_use_external_panels()) {
            return array('ok' => false, 'error' => 'panels_pro_required');
        }
        return array('ok' => true);
    }

    public static function assert_can_add_plan($force_online = false)
    {
        if ($force_online || self::current_plan_count() >= self::FREE_MAX_PLANS) {
            self::refresh_from_vendor(true);
        } else {
            self::validate_cached();
        }
        if (!self::can_add_plan()) {
            return array('ok' => false, 'error' => 'plan_limit_reached');
        }
        return array('ok' => true);
    }

    public static function api_request($action, $license_key = '')
    {
        $url = self::license_server_url();
        if ($url === '' || self::api_token() === '') {
            return array('ok' => false, 'error' => 'license_server_not_configured');
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'curl_required');
        }

        global $config;
        $payload = array(
            'action' => $action,
            'instance_id' => self::instance_id(),
            'domain' => isset($config['domain']) ? $config['domain'] : '',
            'panel_url' => isset($config['domain']) ? $config['domain'] : '',
            'version' => isset($config['version']) ? $config['version'] : '',
            'api_token' => self::api_token(),
        );
        if ($license_key !== '') {
            $payload['license_key'] = $license_key;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return array('ok' => false, 'error' => 'network: ' . $err);
        }
        $data = json_decode($resp, true);
        return is_array($data) ? $data : array('ok' => false, 'error' => 'invalid_response');
    }

    public static function register_with_vendor()
    {
        return self::sync_presence_with_vendor(true);
    }

    private static function admin_session_active()
    {
        if (PHP_SAPI === 'cli' && !defined('USK_ADMIN')) {
            return defined('USK_CRON');
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        return !empty($_SESSION['usk_admin_logged']);
    }

    public static function activate($license_key)
    {
        $key = strtoupper(trim($license_key));
        $res = self::api_request('activate', $key);
        if (empty($res['ok'])) {
            return $res;
        }
        if (!self::apply_vendor_ok($res, $key)) {
            return array('ok' => false, 'error' => 'invalid_license_proof');
        }
        self::restore_pro_plans();
        return $res;
    }

    public static function refresh_from_vendor($force = false)
    {
        $before_pro = self::is_pro();
        $d = self::get();
        if (empty($d['license_key'])) {
            return $d;
        }
        if (!$force && !empty($d['last_check']) && strtotime($d['last_check']) > time() - self::CACHE_TTL) {
            return $d;
        }

        $res = self::api_request('validate', $d['license_key']);
        if (!empty($res['ok']) && self::apply_vendor_ok($res, $d['license_key'])) {
            if (!$before_pro) {
                self::restore_pro_plans();
            }
            return self::get();
        }

        if (!empty($res['ok'])) {
            self::downgrade_to_free($d);
            return self::get();
        }

        if (self::is_network_error($res) && self::within_offline_grace($d)) {
            return $d;
        }

        self::downgrade_to_free($d);
        return self::get();
    }

    private static function downgrade_to_free(array $d)
    {
        $suspended = self::enforce_free_tier_plans();
        self::save(array(
            'tier' => 'free',
            'license_key' => $d['license_key'] ?? '',
            'max_plans' => self::FREE_MAX_PLANS,
            'expires_at' => null,
            'bound_ip' => $d['bound_ip'] ?? '',
            'last_check' => date('c'),
            'suspended_plan_ids' => $suspended,
            'instance_id' => self::instance_id(),
        ));
    }

    /**
     * Free tier: keep oldest plan active; suspend extras (not deleted).
     * @return int[] suspended plan row ids
     */
    public static function enforce_free_tier_plans()
    {
        global $sql;
        if (!$sql instanceof mysqli) {
            return array();
        }

        $res = $sql->query("SELECT `row`, `status` FROM `category` ORDER BY `row` ASC");
        if (!$res) {
            return array();
        }

        $rows = array();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        if (count($rows) <= self::FREE_MAX_PLANS) {
            return array();
        }

        $suspended = array();
        foreach ($rows as $i => $r) {
            $id = (int) $r['row'];
            if ($i === 0) {
                $sql->query("UPDATE `category` SET `status`='active' WHERE `row`=$id");
                continue;
            }
            if ($r['status'] === 'active') {
                $sql->query("UPDATE `category` SET `status`='inactive' WHERE `row`=$id");
                $suspended[] = $id;
            }
        }
        return $suspended;
    }

    public static function restore_pro_plans()
    {
        global $sql;
        if (!$sql instanceof mysqli) {
            return;
        }
        $d = self::get();
        $ids = isset($d['suspended_plan_ids']) && is_array($d['suspended_plan_ids']) ? $d['suspended_plan_ids'] : array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $sql->query("UPDATE `category` SET `status`='active' WHERE `row`=$id");
            }
        }
        if (!empty($ids)) {
            $d['suspended_plan_ids'] = array();
            if (($d['tier'] ?? '') === 'pro') {
                self::save($d);
            }
        }
    }

    /** Check plan by code — used by API / WooCommerce */
    public static function plan_is_usable($plan_code)
    {
        return self::get_plan_by_code($plan_code) !== null;
    }

    /** @return array|null */
    public static function get_plan_by_code($plan_code)
    {
        global $sql;
        $plan_code = preg_replace('/[^0-9]/', '', (string) $plan_code);
        if ($plan_code === '' || !$sql instanceof mysqli) {
            return null;
        }
        $code = $sql->real_escape_string($plan_code);
        $r = $sql->query("SELECT * FROM `category` WHERE `code`='$code' AND `status`='active' LIMIT 1");
        return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
    }

    /** @return array<int, array{code:string,name:string,volume_gb:int,duration_days:int,price:string,status:string}> */
    public static function list_active_plans()
    {
        global $sql;
        $out = array();
        if (!$sql instanceof mysqli) {
            return $out;
        }
        $r = $sql->query("SELECT `code`,`name`,`limit`,`date`,`price`,`status`,`connections` FROM `category` WHERE `status`='active' ORDER BY `row` DESC");
        if (!$r) {
            return $out;
        }
        while ($row = $r->fetch_assoc()) {
            $out[] = array(
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'volume_gb' => (int) ($row['limit'] ?? 0),
                'duration_days' => (int) ($row['date'] ?? 0),
                'price' => (string) ($row['price'] ?? ''),
                'status' => (string) ($row['status'] ?? 'active'),
                'max_connections' => max(1, (int) ($row['connections'] ?? 1)),
            );
        }
        return $out;
    }

    public static function first_active_plan()
    {
        global $sql;
        if (!$sql instanceof mysqli) {
            return null;
        }
        $r = $sql->query("SELECT * FROM `category` WHERE `status`='active' ORDER BY `row` ASC LIMIT 1");
        return $r ? $r->fetch_assoc() : null;
    }

    private static function is_network_error(array $res)
    {
        $err = (string) ($res['error'] ?? '');
        return strpos($err, 'network:') === 0 || $err === 'curl_required' || $err === 'invalid_response';
    }

    private static function within_offline_grace(array $d)
    {
        if (($d['tier'] ?? 'free') !== 'pro' || empty($d['last_check'])) {
            return false;
        }
        return strtotime($d['last_check']) > time() - self::GRACE_OFFLINE;
    }

    public static function validate_cached()
    {
        return self::refresh_from_vendor(false);
    }

    public static function boot()
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (self::admin_session_active()) {
            self::sync_presence_with_vendor(false);
        }
        self::validate_cached();
    }

    public static function deactivate()
    {
        $d = self::get();
        self::enforce_free_tier_plans();
        @unlink(self::file_path());
    }
}
