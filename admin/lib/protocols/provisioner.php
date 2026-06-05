<?php

class USK_ProtocolProvisioner
{
    private static function interpret_output($out, $fallback = 'provision_error')
    {
        $out = (string) $out;
        if ($out === '') {
            return $fallback;
        }
        if (stripos($out, 'sudo:') !== false) {
            if (stripos($out, 'password is required') !== false || stripos($out, 'a password is required') !== false) {
                return 'sudo_denied';
            }
            if (stripos($out, 'not allowed') !== false) {
                return 'sudo_denied';
            }
        }
        if (strpos($out, 'USK_ERR:') !== false) {
            if (preg_match('/USK_ERR:\s*(.+)/', $out, $m)) {
                return trim($m[1]);
            }
        }
        return $fallback;
    }

    public static function create($protocol, $username, $volume_gb = 0, $duration_days = 0, array $meta = array())
    {
        $allowed = array_keys(USK_ProtocolManager::list());
        if (!in_array($protocol, $allowed, true)) {
            return array('ok' => false, 'error' => 'invalid_protocol');
        }

        $status = USK_ProtocolManager::get_status($protocol);
        if (empty($status['installed'])) {
            return array('ok' => false, 'error' => 'protocol_not_installed');
        }

        $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
        if ($username === '') {
            return array('ok' => false, 'error' => 'invalid_username');
        }

        $script = USK_ROOT . '/bin/add-user-' . $protocol . '.sh';
        if (!file_exists($script)) {
            return array('ok' => false, 'error' => 'provision_script_missing');
        }

        $server_ip = '';
        if (!empty($meta['server_ip'])) {
            $server_ip = preg_replace('/[^0-9a-fA-F.:]/', '', $meta['server_ip']);
        }

        $st = USK_ProtocolManager::get_status($protocol);
        $envParts = array();
        if ($server_ip !== '') {
            $envParts[] = 'USK_SERVER_IP=' . escapeshellarg($server_ip);
        }
        if (!empty($meta['port']) && (int) $meta['port'] > 0) {
            $envParts[] = 'USK_PORT=' . (int) $meta['port'];
        } elseif ($protocol !== 'xray' && !empty($st['port'])) {
            $envParts[] = 'USK_PORT=' . (int) $st['port'];
        }
        $env = implode(' ', $envParts);
        if ($env !== '') {
            $env .= ' ';
        }

        $cmd = $env . 'sudo -n bash ' . escapeshellarg($script) . ' '
            . escapeshellarg($username) . ' '
            . (int) $volume_gb . ' '
            . (int) $duration_days . ' 2>&1';
        $out = shell_exec($cmd);
        if ($out === null || trim($out) === '') {
            return array('ok' => false, 'error' => 'provision_failed', 'log' => '');
        }

        if (strpos($out, 'USK_ERR:') !== false) {
            return array('ok' => false, 'error' => self::interpret_output($out, 'provision_error'), 'log' => $out);
        }

        if (!preg_match('/USK_JSON:(.+)$/s', $out, $m)) {
            return array(
                'ok' => false,
                'error' => self::interpret_output($out, 'invalid_provision_output'),
                'log' => $out,
            );
        }

        $data = json_decode(trim($m[1]), true);
        if (!is_array($data) || empty($data['ok'])) {
            return array('ok' => false, 'error' => 'provision_parse_error', 'log' => $out);
        }

        $config = $data['config'] ?? ($data['links'] ?? '');
        $links = $data['links'] ?? $config;
        $subscription = $data['subscription_url'] ?? $links;

        self::save_client_record($protocol, $username, array(
            'volume_gb' => (int) $volume_gb,
            'duration_days' => (int) $duration_days,
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : ($duration_days > 0 ? date('c', strtotime('+' . (int) $duration_days . ' days')) : null),
            'meta' => $data,
            'public_key' => $data['public_key'] ?? null,
            'uuid' => $data['uuid'] ?? null,
            'status' => 'active',
            'source' => $meta['source'] ?? 'admin',
            'wc_order_id' => $meta['wc_order_id'] ?? null,
        ));

        return array(
            'ok' => true,
            'username' => $username,
            'protocol' => $protocol,
            'config' => $config,
            'links' => $links,
            'subscription' => $subscription,
            'qr_png' => $data['qr_png'] ?? '',
            'expires_at' => $data['expires_at'] ?? null,
            'volume_gb' => (int) $volume_gb,
            'duration_days' => (int) $duration_days,
            'raw' => $data,
        );
    }

    public static function save_client_record($protocol, $username, array $record)
    {
        $dir = USK_ROOT . '/data/clients';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . $protocol . '.json';
        $all = array();
        if (file_exists($file)) {
            $all = json_decode(file_get_contents($file), true) ?: array();
        }
        $record['username'] = $username;
        $record['protocol'] = $protocol;
        $record['created_at'] = date('c');
        if (!isset($record['status'])) {
            $record['status'] = 'active';
        }
        if (!empty($record['meta']) && is_array($record['meta'])) {
            foreach (array('public_key', 'client_ip', 'uuid', 'password', 'psk', 'config', 'qr_png', 'endpoint') as $k) {
                if (isset($record['meta'][$k]) && $record['meta'][$k] !== '') {
                    $record[$k] = $record['meta'][$k];
                }
            }
        }
        $all[$username] = $record;
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function save_order($protocol, $username, $volume_gb, $duration_days, $link, $source = 'api', $wc_order_id = null)
    {
        global $sql;
        $code = (string) rand(111111, 999999);
        $from_id = $wc_order_id ? ('wc-' . (int) $wc_order_id) : ('native-' . $username);
        $location = $sql->real_escape_string(strtoupper($protocol) . ' Native');
        $volume = $sql->real_escape_string((string) $volume_gb);
        $date = $sql->real_escape_string((string) $duration_days);
        $link_esc = $sql->real_escape_string($link);
        $ptype = $sql->real_escape_string('native');
        $proto = $sql->real_escape_string($protocol);
        $from_esc = $sql->real_escape_string($from_id);

        $ok = $sql->query("INSERT INTO `orders` (`from_id`,`location`,`protocol`,`date`,`volume`,`link`,`price`,`code`,`status`,`type`) VALUES ('$from_esc','$location','$proto','$date','$volume','$link_esc','0','$code','active','$ptype')");
        if (!$ok) {
            return array('ok' => false, 'error' => $sql->error ?: 'order_insert_failed', 'code' => $code);
        }

        $clients = self::load_protocol_clients($protocol);
        if (isset($clients[$username])) {
            $clients[$username]['order_code'] = $code;
            self::save_protocol_clients($protocol, $clients);
        }

        return array('ok' => true, 'code' => $code, 'order_id' => $sql->insert_id);
    }

    private static function load_protocol_clients($protocol)
    {
        return USK_ProtocolLimits::load_protocol_clients($protocol);
    }

    private static function save_protocol_clients($protocol, array $clients)
    {
        USK_ProtocolLimits::save_protocol_clients($protocol, $clients);
    }

    public static function error_label($code)
    {
        $code = preg_replace('/\s+port=\d+.*$/', '', trim((string) $code));
        $map = array(
            'sudo_denied' => 'err_sudo_denied',
            'xray_not_installed' => 'err_xray_not_installed',
            'jq_required' => 'err_jq_required',
            'xray_config_invalid' => 'err_xray_config_invalid',
            'xray_config_update_failed' => 'err_xray_config_invalid',
            'xray_vless_port_not_listening' => 'err_xray_not_running',
            'xray_vmess_port_not_listening' => 'err_xray_not_running',
            'xray_config_test_failed' => 'err_xray_config_invalid',
            'xray_restart_failed' => 'err_xray_restart_failed',
            'cisco_not_installed' => 'err_cisco_not_installed',
            'cisco_user_create_failed' => 'err_provision_failed',
            'provision_failed' => 'err_provision_failed',
            'invalid_provision_output' => 'err_sudo_denied',
        );
        $key = isset($map[$code]) ? $map[$code] : 'create_failed';
        return __($key, $code);
    }
}
