<?php

class USK_ProtocolProvisioner
{
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

        $env = '';
        if ($server_ip !== '') {
            $env = 'USK_SERVER_IP=' . escapeshellarg($server_ip) . ' ';
        }

        $cmd = $env . 'sudo bash ' . escapeshellarg($script) . ' '
            . escapeshellarg($username) . ' '
            . (int) $volume_gb . ' '
            . (int) $duration_days . ' 2>&1';
        $out = shell_exec($cmd);
        if ($out === null || $out === '') {
            return array('ok' => false, 'error' => 'provision_failed', 'log' => '');
        }

        if (strpos($out, 'USK_ERR:') !== false) {
            preg_match('/USK_ERR:\s*(.+)/', $out, $m);
            return array('ok' => false, 'error' => trim($m[1] ?? 'provision_error'), 'log' => $out);
        }

        if (!preg_match('/USK_JSON:(.+)$/s', $out, $m)) {
            return array('ok' => false, 'error' => 'invalid_provision_output', 'log' => $out);
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

        $sql->query("INSERT INTO `orders` (`from_id`,`location`,`protocol`,`date`,`volume`,`link`,`price`,`code`,`status`,`type`) VALUES ('$from_esc','$location','$proto','$date','$volume','$link_esc','0','$code','active','$ptype')");

        $clients = self::load_protocol_clients($protocol);
        if (isset($clients[$username])) {
            $clients[$username]['order_code'] = $code;
            self::save_protocol_clients($protocol, $clients);
        }

        return array('code' => $code, 'order_id' => $sql->insert_id);
    }

    private static function load_protocol_clients($protocol)
    {
        return USK_ProtocolLimits::load_protocol_clients($protocol);
    }

    private static function save_protocol_clients($protocol, array $clients)
    {
        USK_ProtocolLimits::save_protocol_clients($protocol, $clients);
    }
}
