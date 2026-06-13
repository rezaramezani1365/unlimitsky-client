<?php

class USK_ApiKeys
{
    private static function file_path()
    {
        return dirname(__DIR__) . '/data/api-keys.json';
    }

    private static function load()
    {
        $file = self::file_path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!file_exists($file)) {
            return array('keys' => array());
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : array('keys' => array());
    }

    private static function save(array $data)
    {
        file_put_contents(self::file_path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function normalize_site_domain($raw)
    {
        $raw = trim(strtolower((string) $raw));
        if ($raw === '') {
            return '';
        }
        $raw = preg_replace('#^https?://#i', '', $raw);
        $raw = preg_replace('#/.*$#', '', $raw);
        $raw = preg_replace('#:\d+$#', '', $raw);
        return trim($raw, '. ');
    }

    private static function strip_www($domain)
    {
        $domain = self::normalize_site_domain($domain);
        if (strpos($domain, 'www.') === 0) {
            return substr($domain, 4);
        }
        return $domain;
    }

    public static function request_site_domain()
    {
        // Try GET/POST fallback first (helps if headers are stripped by proxy/WAF)
        $site = trim((string) ($_GET['usk_site_url'] ?? $_POST['usk_site_url'] ?? ''));

        if ($site === '') {
            $site = trim((string) ($_SERVER['HTTP_X_USK_SITE_URL'] ?? ''));
        }
        if ($site === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $site = $headers['X-USK-Site-URL'] ?? $headers['x-usk-site-url'] ?? '';
        }
        if ($site !== '') {
            return self::normalize_site_domain($site);
        }
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            return self::normalize_site_domain($origin);
        }
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            return self::normalize_site_domain($referer);
        }
        return '';
    }

    public static function validate_domain(array $key)
    {
        $allowed = self::normalize_site_domain($key['allowed_domain'] ?? '');
        if ($allowed === '') {
            return true;
        }
        $request = self::request_site_domain();
        if ($request === '') {
            return false;
        }
        return self::strip_www($request) === self::strip_www($allowed);
    }

    public static function list_keys()
    {
        $data = self::load();
        $out = array();
        foreach ($data['keys'] as $key) {
            $out[] = array(
                'id' => $key['id'],
                'name' => $key['name'],
                'prefix' => $key['prefix'],
                'allowed_domain' => $key['allowed_domain'] ?? '',
                'created_at' => $key['created_at'],
                'status' => $key['status'],
            );
        }
        return $out;
    }

    public static function create($name, $allowed_domain = '')
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'WooCommerce';
        }

        $allowed_domain = self::normalize_site_domain($allowed_domain);
        if ($allowed_domain === '') {
            return array('error' => 'domain_required');
        }

        $raw = 'USK-API-' . bin2hex(random_bytes(24));
        $id = bin2hex(random_bytes(8));
        $prefix = substr($raw, 0, 16) . '...';

        $data = self::load();
        $data['keys'][] = array(
            'id' => $id,
            'name' => $name,
            'hash' => hash('sha256', $raw),
            'prefix' => $prefix,
            'allowed_domain' => $allowed_domain,
            'created_at' => date('c'),
            'status' => 'active',
        );
        self::save($data);

        return array(
            'id' => $id,
            'key' => $raw,
            'prefix' => $prefix,
            'name' => $name,
            'allowed_domain' => $allowed_domain,
        );
    }

    public static function revoke($id)
    {
        $data = self::load();
        foreach ($data['keys'] as &$key) {
            if ($key['id'] === $id) {
                $key['status'] = 'revoked';
            }
        }
        unset($key);
        self::save($data);
        return true;
    }

    public static function validate($token)
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $hash = hash('sha256', $token);
        $data = self::load();
        foreach ($data['keys'] as $key) {
            if ($key['status'] === 'active' && hash_equals($key['hash'], $hash)) {
                return $key;
            }
        }
        return false;
    }

    public static function get_bearer_token()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return trim($m[1]);
        }
        return trim($_GET['api_key'] ?? $_POST['api_key'] ?? '');
    }

    public static function require_auth()
    {
        $token = self::get_bearer_token();
        $key = self::validate($token);
        if (!$key) {
            http_response_code(401);
            echo json_encode(array('ok' => false, 'error' => 'unauthorized'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!self::validate_domain($key)) {
            http_response_code(403);
            echo json_encode(array(
                'ok' => false,
                'error' => 'domain_mismatch',
                'allowed_domain' => self::normalize_site_domain($key['allowed_domain'] ?? ''),
                'request_domain' => self::request_site_domain(),
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
        return $key;
    }

    public static function api_base_url()
    {
        $install_file = USK_ROOT . '/install/unlimitsky.install';
        if (file_exists($install_file)) {
            $inst = json_decode(file_get_contents($install_file), true);
            if (!empty($inst['install_location'])) {
                return rtrim($inst['install_location'], '/') . '/api/v1.php';
            }
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/api/v1.php';
    }
}
