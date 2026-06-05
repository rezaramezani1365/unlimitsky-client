<?php

class USK_License
{
    const FREE_MAX_PLANS = 1;
    const CACHE_TTL = 14400;
    const GRACE_OFFLINE = 86400;
    const SIGN_SALT = 'USK-LIC-v2';

    private static $booted = false;

    private static function file_path()
    {
        return dirname(__DIR__) . '/data/license.json';
    }

    public static function instance_id()
    {
        $id_file = dirname(__DIR__) . '/data/instance.id';
        if (file_exists($id_file)) {
            return trim(file_get_contents($id_file));
        }
        $id = hash('sha256', php_uname('n') . '|' . (isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '') . '|' . USK_ROOT);
        @file_put_contents($id_file, $id);
        return $id;
    }

    private static function api_token()
    {
        global $config;
        $token = isset($config['license_api_token']) ? trim((string) $config['license_api_token']) : '';
        if ($token === '' || $token === '[*LICENSE-TOKEN*]') {
            return '';
        }
        return $token;
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
        global $config;
        $url = isset($config['license_server']) ? trim((string) $config['license_server']) : '';
        if ($url === '' || $url === '[*LICENSE-SERVER*]' || self::api_token() === '') {
            return array('ok' => false, 'error' => 'license_server_not_configured');
        }
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'error' => 'curl_required');
        }

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
        return self::api_request('register');
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
        global $sql;
        $plan_code = preg_replace('/[^0-9]/', '', (string) $plan_code);
        if ($plan_code === '' || !$sql instanceof mysqli) {
            return false;
        }
        $code = $sql->real_escape_string($plan_code);
        $r = $sql->query("SELECT * FROM `category` WHERE `code`='$code' AND `status`='active' LIMIT 1");
        return $r && $r->num_rows > 0;
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
        self::validate_cached();
    }

    public static function deactivate()
    {
        $d = self::get();
        self::enforce_free_tier_plans();
        @unlink(self::file_path());
    }
}
