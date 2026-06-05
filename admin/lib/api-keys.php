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

    public static function list_keys()
    {
        $data = self::load();
        $out = array();
        foreach ($data['keys'] as $key) {
            $out[] = array(
                'id' => $key['id'],
                'name' => $key['name'],
                'prefix' => $key['prefix'],
                'created_at' => $key['created_at'],
                'status' => $key['status'],
            );
        }
        return $out;
    }

    public static function create($name)
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'WooCommerce';
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
            'created_at' => date('c'),
            'status' => 'active',
        );
        self::save($data);

        return array('id' => $id, 'key' => $raw, 'prefix' => $prefix, 'name' => $name);
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
