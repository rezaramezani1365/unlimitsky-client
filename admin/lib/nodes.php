<?php

require_once __DIR__ . '/license.php';

class USK_Nodes
{
    private static function data_dir()
    {
        $dir = USK_ROOT . '/data/nodes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private static function store_file()
    {
        return self::data_dir() . '/nodes.json';
    }

    private static function settings_file()
    {
        return USK_ROOT . '/data/settings/nodes-register.json';
    }

    public static function can_use_nodes()
    {
        return USK_License::is_pro();
    }

    public static function assert_can_use_nodes()
    {
        USK_License::validate_cached();
        if (!self::can_use_nodes()) {
            return array('ok' => false, 'error' => 'nodes_pro_required');
        }
        return array('ok' => true);
    }

    /** Shared password — enter during install-node.sh on remote VPS (not a per-node token). */
    public static function register_secret()
    {
        $file = self::settings_file();
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data['register_secret'])) {
                return (string) $data['register_secret'];
            }
        }
        $secret = bin2hex(random_bytes(16));
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode(array(
            'register_secret' => $secret,
            'created_at' => date('c'),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($file, 0600);
        return $secret;
    }

    public static function rotate_register_secret()
    {
        @unlink(self::settings_file());
        return self::register_secret();
    }

    private static function crypto_key()
    {
        $id = USK_License::instance_id();
        return hash('sha256', 'usk-node-v1|' . $id, true);
    }

    public static function encrypt_secret($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            return base64_encode($plain);
        }
        $iv = random_bytes(16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        if ($enc === false) {
            return '';
        }
        return base64_encode($iv . $enc);
    }

    public static function decrypt_secret($stored)
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            $raw = base64_decode($stored, true);
            return $raw !== false ? $raw : '';
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $plain = openssl_decrypt($enc, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    /** @return array<string, array<string, mixed>> */
    public static function all()
    {
        $file = self::store_file();
        if (!is_file($file)) {
            return array();
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : array();
    }

    private static function save_all(array $nodes)
    {
        file_put_contents(
            self::store_file(),
            json_encode($nodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        @chmod(self::store_file(), 0640);
    }

    public static function save_protocol_status($id, $proto, array $status)
    {
        $id = (string) $id;
        $proto = USK_ProtocolManager::sanitize_key($proto);
        if ($id === '' || $proto === '') {
            return;
        }
        $nodes = self::all();
        if (!isset($nodes[$id])) {
            return;
        }
        if (!is_array($nodes[$id]['protocols'] ?? null)) {
            $nodes[$id]['protocols'] = array();
        }
        $nodes[$id]['protocols'][$proto] = $status;
        self::save_all($nodes);
    }

    public static function get($id)
    {
        $id = (string) $id;
        $all = self::all();
        return $all[$id] ?? null;
    }

    public static function register(array $input)
    {
        $check = self::assert_can_use_nodes();
        if (empty($check['ok'])) {
            return $check;
        }

        $name = trim((string) ($input['name'] ?? ''));
        $sshHost = trim((string) ($input['ssh_host'] ?? ''));
        $sshPort = (int) ($input['ssh_port'] ?? 22);
        $sshUser = trim((string) ($input['ssh_user'] ?? ''));
        $sshPass = (string) ($input['ssh_password'] ?? '');
        $connectHost = trim((string) ($input['connect_host'] ?? ''));

        if ($name === '' || $sshHost === '' || $sshUser === '' || $sshPass === '') {
            return array('ok' => false, 'error' => 'missing_fields');
        }
        if ($sshPort < 1 || $sshPort > 65535) {
            $sshPort = 22;
        }
        if ($connectHost === '') {
            $connectHost = $sshHost;
        }
        if (class_exists('USK_ConnectHost')) {
            $connectHost = USK_ConnectHost::sanitize($connectHost) ?: $sshHost;
        }

        require_once __DIR__ . '/node-ssh.php';
        $test = USK_NodeSsh::test_connection($sshHost, $sshPort, $sshUser, $sshPass);
        if (empty($test['ok'])) {
            return array('ok' => false, 'error' => $test['error'] ?? 'ssh_failed', 'detail' => $test['detail'] ?? '');
        }

        $id = 'n' . substr(bin2hex(random_bytes(8)), 0, 12);
        $nodes = self::all();
        $nodes[$id] = array(
            'id' => $id,
            'name' => $name,
            'ssh_host' => $sshHost,
            'ssh_port' => $sshPort,
            'ssh_user' => $sshUser,
            'ssh_password_enc' => self::encrypt_secret($sshPass),
            'connect_host' => $connectHost,
            'status' => 'online',
            'remote_root' => '/opt/unlimitsky-node',
            'protocols' => is_array($input['protocols'] ?? null) ? $input['protocols'] : array(),
            'registered_at' => date('c'),
            'last_seen' => date('c'),
            'last_error' => '',
        );
        self::save_all($nodes);

        return array('ok' => true, 'node_id' => $id, 'name' => $name);
    }

    public static function delete($id)
    {
        $id = (string) $id;
        $nodes = self::all();
        if (!isset($nodes[$id])) {
            return array('ok' => false, 'error' => 'not_found');
        }
        unset($nodes[$id]);
        self::save_all($nodes);
        return array('ok' => true);
    }

    public static function mark_seen($id, $status = 'online', $error = '')
    {
        $nodes = self::all();
        if (!isset($nodes[$id])) {
            return;
        }
        $nodes[$id]['last_seen'] = date('c');
        $nodes[$id]['status'] = $status;
        $nodes[$id]['last_error'] = (string) $error;
        self::save_all($nodes);
    }

    public static function ssh_credentials(array $node)
    {
        return array(
            'host' => (string) ($node['ssh_host'] ?? ''),
            'port' => (int) ($node['ssh_port'] ?? 22),
            'user' => (string) ($node['ssh_user'] ?? ''),
            'password' => self::decrypt_secret($node['ssh_password_enc'] ?? ''),
            'remote_root' => (string) ($node['remote_root'] ?? '/opt/unlimitsky-node'),
        );
    }

    public static function connect_host_for_node(array $node)
    {
        $h = trim((string) ($node['connect_host'] ?? ''));
        if ($h !== '') {
            return $h;
        }
        return trim((string) ($node['ssh_host'] ?? ''));
    }

    public static function list_for_select()
    {
        $out = array();
        foreach (self::all() as $node) {
            if (($node['status'] ?? '') === 'revoked') {
                continue;
            }
            $out[] = array(
                'id' => $node['id'],
                'name' => $node['name'] ?? $node['id'],
                'connect_host' => self::connect_host_for_node($node),
                'status' => $node['status'] ?? 'unknown',
            );
        }
        return $out;
    }

    public static function format_last_run_at($iso)
    {
        return USK_ProtocolLimits::format_last_run_at($iso);
    }
}
