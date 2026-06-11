<?php

require_once dirname(__DIR__) . '/client-dns.php';
require_once dirname(__DIR__) . '/connect-host.php';

class USK_ProtocolProvisioner
{
    private static function sudo_script_cmd($script, array $args)
    {
        $argStr = implode(' ', array_map('escapeshellarg', $args));
        return 'sudo -n /bin/bash ' . escapeshellarg($script) . ' ' . $argStr . ' 2>&1';
    }

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

    public static function sanitize_customer_email($email)
    {
        $email = trim((string) $email);
        if ($email === '') {
            return '';
        }
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    public static function sanitize_usage_id($value, $fallback = '')
    {
        $value = trim((string) $value);
        $value = preg_replace('/[^a-zA-Z0-9@._+\-]/', '_', $value);
        $value = trim((string) $value, '._-');
        if ($value === '') {
            $value = preg_replace('/[^a-zA-Z0-9@._+\-]/', '_', (string) $fallback);
            $value = trim((string) $value, '._-');
        }
        return substr($value !== '' ? $value : 'user', 0, 96);
    }

    private static function usage_id_for($protocol, $username, $customerEmail, array $meta)
    {
        $explicit = trim((string) ($meta['usage_id'] ?? ($meta['xray_email'] ?? '')));
        if ($explicit !== '') {
            return self::sanitize_usage_id($explicit, $username);
        }
        if ($protocol === 'xray' && $customerEmail !== '') {
            return self::sanitize_usage_id($customerEmail, $username);
        }
        return self::sanitize_usage_id($username, $username);
    }

    public static function create($protocol, $username, $volume_gb = 0, $duration_days = 0, array $meta = array())
    {
        $allowed = array_keys(USK_ProtocolManager::list());
        if (!in_array($protocol, $allowed, true)) {
            return array('ok' => false, 'error' => 'invalid_protocol');
        }

        $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
        if ($username === '') {
            return array('ok' => false, 'error' => 'invalid_username');
        }

        $nodeId = preg_replace('/[^a-z0-9]/', '', (string) ($meta['node_id'] ?? ''));
        if ($nodeId !== '') {
            return self::create_on_node($protocol, $username, $volume_gb, $duration_days, $meta, $nodeId);
        }

        if (!USK_ProtocolManager::is_installed($protocol)) {
            return array('ok' => false, 'error' => $protocol . '_not_installed');
        }

        $clientDns = USK_ClientDns::resolve((string) ($meta['client_dns'] ?? ''), $protocol);
        $meta['client_dns'] = $clientDns;
        $customerEmail = self::sanitize_customer_email($meta['customer_email'] ?? '');
        $usageId = self::usage_id_for($protocol, $username, $customerEmail, $meta);

        $connectHost = USK_ConnectHost::resolve(
            isset($meta['server_ip']) && (string) $meta['server_ip'] !== ''
                ? (string) $meta['server_ip']
                : null
        );

        $script = USK_ROOT . '/bin/add-user-' . $protocol . '.sh';
        if (!file_exists($script)) {
            return array('ok' => false, 'error' => 'provision_script_missing');
        }

        $st = USK_ProtocolManager::get_status($protocol);
        $scriptArgs = array($username, (string) (int) $volume_gb, (string) (int) $duration_days);
        if ($protocol === 'openvpn') {
            $ovpnProto = strtolower((string) ($meta['openvpn_proto'] ?? 'tcp'));
            if (!in_array($ovpnProto, array('udp', 'tcp'), true)) {
                $ovpnProto = 'tcp';
            }
            $scriptArgs[] = $ovpnProto;
            $scriptArgs[] = (string) (int) ($st['udp_port'] ?? 1194);
            $scriptArgs[] = (string) (int) ($st['tcp_port'] ?? 443);
            $scriptArgs[] = $connectHost;
            $scriptArgs[] = $clientDns;
        } elseif ($protocol === 'wireguard') {
            $wgTransport = strtolower((string) ($meta['wireguard_transport'] ?? 'udp'));
            if (!in_array($wgTransport, array('udp', 'tcp'), true)) {
                $wgTransport = 'udp';
            }
            $scriptArgs[] = $wgTransport;
            $scriptArgs[] = $clientDns;
            $scriptArgs[] = $connectHost;
        } elseif ($protocol === 'xray') {
            $scriptArgs[] = $clientDns;
            $scriptArgs[] = $connectHost;
            $scriptArgs[] = $usageId;
        } elseif ($protocol !== 'openvpn') {
            $scriptArgs[] = $connectHost;
        }

        $cmd = self::sudo_script_cmd($script, $scriptArgs);
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
        $vpnUri = trim((string) ($data['vpn_uri'] ?? ''));
        $subscription = $vpnUri !== '' ? $vpnUri : ($data['subscription_url'] ?? $links);
        if ($protocol === 'xray') {
            $vlessLink = trim((string) ($data['vless'] ?? ''));
            if ($vlessLink !== '') {
                $subscription = $vlessLink;
            }
        }

        $clientRecord = array(
            'volume_gb' => (int) $volume_gb,
            'duration_days' => (int) $duration_days,
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : ($duration_days > 0 ? date('c', strtotime('+' . (int) $duration_days . ' days')) : null),
            'meta' => $data,
            'public_key' => $data['public_key'] ?? null,
            'uuid' => $data['uuid'] ?? null,
            'status' => 'active',
            'source' => $meta['source'] ?? 'admin',
            'wc_order_id' => $meta['wc_order_id'] ?? null,
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'usage_id' => $usageId,
            'xray_email' => $protocol === 'xray' ? $usageId : null,
            'email' => $protocol === 'xray' ? $usageId : ($customerEmail !== '' ? $customerEmail : null),
            'max_connections' => max(1, (int) ($meta['max_connections'] ?? 1)),
            'vpn_uri' => $vpnUri !== '' ? $vpnUri : null,
            'qr_conf_png' => $data['qr_conf_png'] ?? '',
        );
        self::save_client_record($protocol, $username, $clientRecord);

        $result = array(
            'ok' => true,
            'username' => $username,
            'protocol' => $protocol,
            'config' => $config,
            'links' => $links,
            'subscription' => $subscription,
            'vpn_uri' => $vpnUri,
            'wg_conf' => $data['wg_conf'] ?? '',
            'qr_png' => $data['qr_png'] ?? '',
            'download_token' => $data['download_token'] ?? '',
            'conf_filename' => $data['conf_filename'] ?? '',
            'json_filename' => $data['json_filename'] ?? '',
            'client_dns' => $data['client_dns'] ?? '',
            'client_json' => $data['client_json'] ?? '',
            'vless' => $data['vless'] ?? '',
            'expires_at' => $data['expires_at'] ?? null,
            'volume_gb' => (int) $volume_gb,
            'duration_days' => (int) $duration_days,
            'customer_email' => $customerEmail,
            'usage_id' => $usageId,
            'xray_email' => $protocol === 'xray' ? $usageId : '',
            'raw' => $data,
        );
        return $result;
    }

    private static function create_on_node($protocol, $username, $volume_gb, $duration_days, array $meta, $nodeId)
    {
        $check = USK_Nodes::assert_can_use_nodes();
        if (empty($check['ok'])) {
            return $check;
        }

        $node = USK_Nodes::get($nodeId);
        if (!$node) {
            return array('ok' => false, 'error' => 'node_not_found');
        }

        require_once dirname(__DIR__) . '/node-protocols.php';
        require_once dirname(__DIR__) . '/node-ssh.php';

        if (!in_array($protocol, USK_NodeProtocols::supported(), true)) {
            return array('ok' => false, 'error' => 'nodes_protocol_unsupported');
        }

        $ensure = USK_NodeProtocols::ensure_installed($node, $protocol);
        if (empty($ensure['ok'])) {
            USK_Nodes::mark_seen($nodeId, 'offline', $ensure['error'] ?? 'install_failed');
            return array(
                'ok' => false,
                'error' => self::interpret_output((string) ($ensure['log'] ?? ''), $ensure['error'] ?? ($protocol . '_not_installed')),
                'log' => $ensure['log'] ?? '',
            );
        }

        $clientDns = USK_ClientDns::resolve((string) ($meta['client_dns'] ?? ''), $protocol);
        $connectHost = USK_Nodes::connect_host_for_node($node);
        $customerEmail = self::sanitize_customer_email($meta['customer_email'] ?? '');
        $usageId = self::usage_id_for($protocol, $username, $customerEmail, $meta);

        $scriptName = 'add-user-' . $protocol . '.sh';
        $scriptArgs = array(
            $username,
            (string) (int) $volume_gb,
            (string) (int) $duration_days,
        );
        if ($protocol === 'openvpn') {
            $ovpnProto = strtolower((string) ($meta['openvpn_proto'] ?? 'tcp'));
            if (!in_array($ovpnProto, array('udp', 'tcp'), true)) {
                $ovpnProto = 'tcp';
            }
            $st = USK_NodeProtocols::get_status($node, 'openvpn');
            $scriptArgs[] = $ovpnProto;
            $scriptArgs[] = (string) (int) ($st['udp_port'] ?? 1194);
            $scriptArgs[] = (string) (int) ($st['tcp_port'] ?? 443);
            $scriptArgs[] = $connectHost;
            $scriptArgs[] = $clientDns;
        } elseif ($protocol === 'xray') {
            $scriptArgs[] = $clientDns;
            $scriptArgs[] = $connectHost;
            $scriptArgs[] = $usageId;
        } elseif ($protocol === 'l2tp') {
            $scriptArgs[] = $connectHost;
        }

        $remote = USK_NodeSsh::run_script($node, $scriptName, $scriptArgs, 240);
        $out = (string) ($remote['log'] ?? '');

        if (empty($remote['ok'])) {
            USK_Nodes::mark_seen($nodeId, 'offline', $remote['error'] ?? 'remote_failed');
            return array(
                'ok' => false,
                'error' => self::interpret_output($out, $remote['error'] ?? 'provision_failed'),
                'log' => $out,
            );
        }

        if (!preg_match('/USK_JSON:(.+)$/s', $out, $m)) {
            USK_Nodes::mark_seen($nodeId, 'offline', 'invalid_provision_output');
            return array(
                'ok' => false,
                'error' => self::interpret_output($out, 'invalid_provision_output'),
                'log' => $out,
            );
        }

        $data = json_decode(trim($m[1]), true);
        if (!is_array($data) || empty($data['ok'])) {
            USK_Nodes::mark_seen($nodeId, 'offline', 'provision_parse_error');
            return array('ok' => false, 'error' => 'provision_parse_error', 'log' => $out);
        }

        USK_Nodes::mark_seen($nodeId, 'online');

        $config = $data['config'] ?? ($data['links'] ?? '');
        $links = $data['links'] ?? $config;
        $vpnUri = trim((string) ($data['vpn_uri'] ?? ''));
        $subscription = $vpnUri !== '' ? $vpnUri : ($data['subscription_url'] ?? $links);
        if ($protocol === 'xray') {
            $vlessLink = trim((string) ($data['vless'] ?? ''));
            if ($vlessLink !== '') {
                $subscription = $vlessLink;
            }
        }

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
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'usage_id' => $usageId,
            'xray_email' => $protocol === 'xray' ? $usageId : null,
            'email' => $protocol === 'xray' ? $usageId : ($customerEmail !== '' ? $customerEmail : null),
            'max_connections' => max(1, (int) ($meta['max_connections'] ?? 1)),
            'vpn_uri' => $vpnUri !== '' ? $vpnUri : null,
            'qr_conf_png' => $data['qr_conf_png'] ?? '',
            'node_id' => $nodeId,
            'server_ip' => $connectHost,
        ));

        return array(
            'ok' => true,
            'username' => $username,
            'protocol' => $protocol,
            'config' => $config,
            'links' => $links,
            'subscription' => $subscription,
            'vpn_uri' => $vpnUri,
            'wg_conf' => $data['wg_conf'] ?? '',
            'qr_png' => $data['qr_png'] ?? '',
            'download_token' => $data['download_token'] ?? '',
            'conf_filename' => $data['conf_filename'] ?? '',
            'json_filename' => $data['json_filename'] ?? '',
            'client_dns' => $data['client_dns'] ?? '',
            'client_json' => $data['client_json'] ?? '',
            'vless' => $data['vless'] ?? '',
            'expires_at' => $data['expires_at'] ?? null,
            'volume_gb' => (int) $volume_gb,
            'duration_days' => (int) $duration_days,
            'customer_email' => $customerEmail,
            'usage_id' => $usageId,
            'xray_email' => $protocol === 'xray' ? $usageId : '',
            'raw' => $data,
            'node_id' => $nodeId,
            'node_name' => $node['name'] ?? $nodeId,
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
            foreach (array('public_key', 'client_ip', 'uuid', 'password', 'psk', 'config', 'qr_png', 'vpn_uri', 'wg_conf', 'endpoint', 'download_token', 'ovpn_filename', 'conf_filename', 'json_filename', 'client_dns', 'client_json', 'vless', 'proto', 'port', 'server_ip', 'max_connections', 'customer_email', 'usage_id', 'xray_email', 'email', 'wireguard_transport', 'tcp_client_cmd', 'tcp_port') as $k) {
                if (isset($record['meta'][$k]) && $record['meta'][$k] !== '') {
                    $record[$k] = $record['meta'][$k];
                }
            }
        }
        $all[$username] = $record;
        file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function save_order($protocol, $username, $volume_gb, $duration_days, $link, $source = 'api', $wc_order_id = null, array $extra = array())
    {
        global $sql;
        $code = (string) rand(111111, 999999);
        $from_id = $wc_order_id ? ('wc-' . (int) $wc_order_id) : ('native-' . $username);
        $locationLabel = strtoupper($protocol) . ' Native';
        if (!empty($extra['node_name'])) {
            $locationLabel = (string) $extra['node_name'] . ' / ' . strtoupper($protocol);
        }
        $location = $sql->real_escape_string($locationLabel);
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
            if (!empty($extra['max_connections'])) {
                $clients[$username]['max_connections'] = max(1, (int) $extra['max_connections']);
            }
            if (!empty($extra['node_id'])) {
                $clients[$username]['node_id'] = (string) $extra['node_id'];
            }
            foreach (array('customer_email', 'usage_id', 'xray_email', 'email') as $k) {
                if (isset($extra[$k]) && (string) $extra[$k] !== '') {
                    $clients[$username][$k] = (string) $extra[$k];
                }
            }
            self::save_protocol_clients($protocol, $clients);
        }

        return array('ok' => true, 'code' => $code, 'order_id' => $sql->insert_id);
    }

    public static function save_external_panel_order(array $panel, $username, $volume_gb, $duration_days, $link, $wc_order_id = null)
    {
        global $sql;
        $ptype = $panel['type'] ?? 'marzban';
        if (!in_array($ptype, array('marzban', 'sanayi'), true)) {
            return array('ok' => false, 'error' => 'invalid_panel_type');
        }
        $code = (string) rand(111111, 999999);
        $from_id = $wc_order_id ? ('wc-' . (int) $wc_order_id) : ('native-' . $username);
        $location = $sql->real_escape_string($panel['name'] ?? strtoupper($ptype));
        $volume = $sql->real_escape_string((string) $volume_gb);
        $date = $sql->real_escape_string((string) $duration_days);
        $link_esc = $sql->real_escape_string($link);
        $proto = $sql->real_escape_string('null');
        $from_esc = $sql->real_escape_string($from_id);
        $type_esc = $sql->real_escape_string($ptype);

        $ok = $sql->query("INSERT INTO `orders` (`from_id`,`location`,`protocol`,`date`,`volume`,`link`,`price`,`code`,`status`,`type`) VALUES ('$from_esc','$location','$proto','$date','$volume','$link_esc','0','$code','active','$type_esc')");
        if (!$ok) {
            return array('ok' => false, 'error' => $sql->error ?: 'order_insert_failed', 'code' => $code);
        }

        $panel_code = $sql->real_escape_string($panel['code'] ?? '');
        if ($panel_code !== '') {
            $sql->query("UPDATE `panels` SET `count_create` = count_create + 1 WHERE `code`='$panel_code'");
        }

        return array('ok' => true, 'code' => $code, 'order_id' => $sql->insert_id);
    }

    public static function openvpn_download_url($order_code, array $raw)
    {
        if (empty($raw['download_token'])) {
            return '';
        }
        require_once dirname(__DIR__) . '/config-download.php';
        return usk_config_download_url($order_code, $raw['download_token']);
    }

    public static function amnezia_download_url($order_code, array $raw)
    {
        return self::openvpn_download_url($order_code, $raw);
    }

    public static function xray_download_url($order_code, array $raw)
    {
        return self::openvpn_download_url($order_code, $raw);
    }

    public static function finalize_order_link($protocol, array $raw, $order_code, $fallback_link)
    {
        if (!empty($raw['download_token'])) {
            require_once dirname(__DIR__) . '/customer-portal.php';
            return usk_customer_portal_url($order_code, $raw['download_token']);
        }
        return $fallback_link;
    }

    public static function config_file_download_url($order_code, array $raw)
    {
        if (empty($raw['download_token'])) {
            return '';
        }
        require_once dirname(__DIR__) . '/config-download.php';
        return usk_config_download_url($order_code, $raw['download_token']);
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
            'xray_reality_keygen_failed' => 'err_xray_reality_keygen_failed',
            'xray_config_test_failed' => 'err_xray_config_invalid',
            'xray_restart_failed' => 'err_xray_restart_failed',
            'cisco_not_installed' => 'err_cisco_not_installed',
            'cisco_user_create_failed' => 'err_provision_failed',
            'openvpn_not_installed' => 'err_openvpn_not_installed',
            'openvpn_tcp_not_installed' => 'err_openvpn_tcp_not_installed',
            'wireguard_not_installed' => 'err_wireguard_not_installed',
            'wireguard_interface_down' => 'err_wireguard_interface_down',
            'wireguard_conf_invalid' => 'err_wireguard_conf_invalid',
            'wireguard_peer_sync_failed' => 'err_wireguard_peer_sync_failed',
            'wireguard_server_key_missing' => 'err_wireguard_server_key_missing',
            'wireguard_tcp_not_installed' => 'err_wireguard_tcp_not_installed',
            'wireguard_tcp_port_in_use' => 'err_wireguard_tcp_port_in_use',
            'wireguard_tcp_bridge_start_failed' => 'err_wireguard_tcp_bridge_failed',
            'wireguard_udp2raw_download_failed' => 'err_wireguard_udp2raw_download_failed',
            'wireguard_udp2raw_binary_bad' => 'err_wireguard_udp2raw_binary_bad',
            'amnezia_userspace_install_failed' => 'err_amnezia_userspace_install_failed',
            'amnezia_go_download_failed' => 'err_amnezia_go_download_failed',
            'amnezia_tools_install_failed' => 'err_amnezia_tools_install_failed',
            'amnezia_config_failed' => 'err_amnezia_config_failed',
            'amnezia_not_installed' => 'err_amnezia_not_installed',
            'amnezia_install_failed' => 'err_amnezia_install_failed',
            'amnezia_user_create_failed' => 'err_amnezia_user_create_failed',
            'l2tp_packages_failed' => 'err_l2tp_install_failed',
            'l2tp_config_failed' => 'err_l2tp_install_failed',
            'l2tp_service_failed' => 'err_l2tp_service_failed',
            'l2tp_not_installed' => 'err_l2tp_not_installed',
            'provision_failed' => 'err_provision_failed',
            'invalid_provision_output' => 'err_sudo_denied',
            'node_not_found' => 'nodes_not_found',
            'nodes_xray_only' => 'nodes_xray_only',
            'nodes_protocol_unsupported' => 'nodes_protocol_unsupported',
            'node_scripts_sync_failed' => 'node_protocols_sync_failed',
            'relay_init_failed' => 'node_relay_failed',
            'relay_add_failed' => 'node_relay_failed',
            'relay_failed' => 'node_relay_failed',
            'hub_tunnel_prepare_failed' => 'node_tunnel_failed',
            'hub_tunnel_ensure_failed' => 'node_tunnel_failed',
            'node_tunnel_ensure_failed' => 'node_tunnel_failed',
            'node_tunnel_pubkey_failed' => 'node_tunnel_failed',
            'node_tunnel_not_ready' => 'node_tunnel_failed',
            'tunnel_not_ready' => 'node_tunnel_failed',
            'nodes_pro_required' => 'nodes_pro_required',
            'sshpass_missing' => 'nodes_sshpass_missing',
            'ssh_connect_failed' => 'nodes_test_failed',
        );
        $key = isset($map[$code]) ? $map[$code] : 'create_failed';
        return __($key, $code);
    }
}
