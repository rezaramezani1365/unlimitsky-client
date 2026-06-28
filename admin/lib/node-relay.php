<?php

require_once __DIR__ . '/node-ssh.php';
require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/connect-host.php';

class USK_NodeRelay
{
    /** Protocols that can egress via a Node relay (must be installed on Hub). */
    public static function supported()
    {
        return array('xray', 'openvpn', 'l2tp');
    }

    public static function node_root(array $node)
    {
        return rtrim((string) ($node['remote_root'] ?? '/opt/unlimitsky-node'), '/');
    }

    public static function hub_backend_ip()
    {
        $ip = USK_ConnectHost::resolve('');
        if ($ip !== '') {
            return $ip;
        }
        return USK_ConnectHost::detect_ip();
    }

    public static function script_manifest()
    {
        return array(
            'node-receive-script.sh',
            'setup-node-relay.sh',
            'remove-node-relay.sh',
        );
    }

    public static function hub_base()
    {
        $hubCfg = USK_PanelAccess::get();
        $hubHost = !empty($hubCfg['domain_enabled']) && ($hubCfg['panel_domain'] ?? '') !== ''
            ? $hubCfg['panel_domain']
            : USK_ConnectHost::detect_ip();
        $hubPort = (int) ($hubCfg['panel_port'] ?? 8082);
        $scheme = !empty($hubCfg['https_enabled']) ? 'https' : 'http';
        return sprintf('%s://%s:%d', $scheme, $hubHost, $hubPort);
    }

    public static function sync_scripts(array $node)
    {
        $root = self::node_root($node);
        $hub = self::hub_base();
        $cred = USK_Nodes::ssh_credentials($node);
        $sshUser = (string) ($cred['user'] ?? 'ubuntu');
        $binDir = $root . '/bin';

        $prep = sprintf(
            'sudo -n mkdir -p %s %s && sudo -n chown -R %s:%s %s',
            escapeshellarg($binDir),
            escapeshellarg($root . '/data/relay'),
            escapeshellarg($sshUser),
            escapeshellarg($sshUser),
            escapeshellarg($root)
        );
        $prepRes = USK_NodeSsh::run($node, $prep, 30);
        if (empty($prepRes['ok'])) {
            return array(
                'ok' => false,
                'error' => 'sync_prepare_failed',
                'hub' => $hub,
                'log' => trim((string) ($prepRes['log'] ?? '')),
            );
        }

        $failed = array();
        $failLog = array();
        $receiver = $binDir . '/node-receive-script.sh';
        foreach (self::script_manifest() as $file) {
            $dest = $binDir . '/' . $file;
            $hubLocal = rtrim((string) USK_ROOT, '/') . '/bin/' . $file;
            if (!is_readable($hubLocal)) {
                $failed[] = $file;
                $failLog[] = $file . ': missing on hub';
                continue;
            }
            $put = USK_NodeSsh::put_file($node, $hubLocal, '/tmp/.usk-relay-' . $file, 90);
            if (empty($put['ok'])) {
                $failed[] = $file;
                $failLog[] = $file . ': push failed';
                continue;
            }
            $staging = '/tmp/.usk-relay-' . $file;
            $move = sprintf(
                'mv -f %s %s && chmod 755 %s && test -s %s',
                escapeshellarg($staging),
                escapeshellarg($dest),
                escapeshellarg($dest),
                escapeshellarg($dest)
            );
            $res = USK_NodeSsh::run($node, $move, 30);
            if (empty($res['ok'])) {
                $install = sprintf(
                    'test -x %s && sudo -n /bin/bash %s %s %s',
                    escapeshellarg($receiver),
                    escapeshellarg($receiver),
                    escapeshellarg($staging),
                    escapeshellarg($dest)
                );
                $res = USK_NodeSsh::run($node, $install, 60);
            }
            if (empty($res['ok'])) {
                $failed[] = $file;
                $failLog[] = $file . ': install failed';
            }
        }

        return array(
            'ok' => $failed === array(),
            'failed' => $failed,
            'hub' => $hub,
            'log' => $failLog === array() ? '' : implode("\n", $failLog),
        );
    }

    public static function init_node(array $node)
    {
        $sync = self::sync_scripts($node);
        if (empty($sync['ok'])) {
            return $sync;
        }
        $root = self::node_root($node);
        $remote = sprintf(
            'sudo -n /bin/bash %s/bin/setup-node-relay.sh init 2>&1',
            escapeshellarg($root)
        );
        $res = USK_NodeSsh::run($node, $remote, 60);
        $out = (string) ($res['log'] ?? '');
        $ok = !empty($res['ok']) && strpos($out, 'USK_OK') !== false;
        return array(
            'ok' => $ok,
            'error' => $ok ? '' : 'relay_init_failed',
            'log' => $out,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function relay_rules_for_protocol($protocol, array $meta = array())
    {
        $protocol = USK_ProtocolManager::sanitize_key($protocol);
        $hubIp = self::hub_backend_ip();
        $st = USK_ProtocolManager::get_status($protocol);
        $rules = array();

        if ($protocol === 'xray') {
            $port = (int) ($st['vless_port'] ?? 443);
            $rules[] = array(
                'id' => 'xray-tcp-' . $port,
                'proto' => 'tcp',
                'listen' => $port,
                'hub_ip' => $hubIp,
                'hub_port' => $port,
            );
        } elseif ($protocol === 'openvpn') {
            $ovpnProto = strtolower((string) ($meta['openvpn_proto'] ?? 'tcp'));
            if ($ovpnProto === 'udp' || !empty($st['udp_port'])) {
                $udp = (int) ($st['udp_port'] ?? 1194);
                $rules[] = array(
                    'id' => 'openvpn-udp-' . $udp,
                    'proto' => 'udp',
                    'listen' => $udp,
                    'hub_ip' => $hubIp,
                    'hub_port' => $udp,
                );
            }
            if ($ovpnProto === 'tcp' || !empty($st['tcp_port'])) {
                $tcp = (int) ($st['tcp_port'] ?? 443);
                $rules[] = array(
                    'id' => 'openvpn-tcp-' . $tcp,
                    'proto' => 'tcp',
                    'listen' => $tcp,
                    'hub_ip' => $hubIp,
                    'hub_port' => $tcp,
                );
            }
            if (count($rules) === 1) {
                return $rules;
            }
            if ($ovpnProto === 'tcp') {
                return array_values(array_filter($rules, function ($r) {
                    return ($r['proto'] ?? '') === 'tcp';
                }));
            }
            if ($ovpnProto === 'udp') {
                return array_values(array_filter($rules, function ($r) {
                    return ($r['proto'] ?? '') === 'udp';
                }));
            }
        } elseif ($protocol === 'l2tp') {
            foreach (array(500, 4500, 1701) as $port) {
                $rules[] = array(
                    'id' => 'l2tp-udp-' . $port,
                    'proto' => 'udp',
                    'listen' => $port,
                    'hub_ip' => $hubIp,
                    'hub_port' => $port,
                );
            }
        }

        return $rules;
    }

    public static function add_rule(array $node, array $rule)
    {
        $root = self::node_root($node);
        $remote = sprintf(
            'sudo -n /bin/bash %s/bin/setup-node-relay.sh add %s %d %s %d %s 2>&1',
            escapeshellarg($root),
            escapeshellarg((string) ($rule['proto'] ?? 'tcp')),
            (int) ($rule['listen'] ?? 0),
            escapeshellarg((string) ($rule['hub_ip'] ?? '')),
            (int) ($rule['hub_port'] ?? 0),
            escapeshellarg((string) ($rule['id'] ?? ''))
        );
        $res = USK_NodeSsh::run($node, $remote, 60);
        $out = (string) ($res['log'] ?? '');
        $ok = !empty($res['ok']) && strpos($out, 'USK_OK') !== false;
        return array('ok' => $ok, 'error' => $ok ? '' : 'relay_add_failed', 'log' => $out);
    }

    public static function ensure_for_protocol(array $node, $protocol, array $meta = array())
    {
        $protocol = USK_ProtocolManager::sanitize_key($protocol);
        if (!in_array($protocol, self::supported(), true)) {
            return array('ok' => false, 'error' => 'nodes_protocol_unsupported');
        }
        if (!USK_ProtocolManager::is_installed($protocol)) {
            return array('ok' => false, 'error' => $protocol . '_not_installed');
        }

        $init = self::init_node($node);
        if (empty($init['ok'])) {
            return $init;
        }

        $rules = self::relay_rules_for_protocol($protocol, $meta);
        if ($rules === array()) {
            return array('ok' => false, 'error' => 'relay_rules_empty');
        }

        $logs = array();
        foreach ($rules as $rule) {
            $add = self::add_rule($node, $rule);
            $logs[] = (string) ($add['log'] ?? '');
            if (empty($add['ok'])) {
                return array(
                    'ok' => false,
                    'error' => $add['error'] ?? 'relay_add_failed',
                    'log' => implode("\n", $logs),
                );
            }
        }

        $status = self::read_cached_status($node, $protocol);
        $status['relay_active'] = true;
        $status['relay_rules'] = $rules;
        $status['updated_at'] = date('c');
        $status['verified_at'] = date('c');
        $status['hub_ip'] = self::hub_backend_ip();
        if (!empty($node['id'])) {
            self::save_status($node['id'], $protocol, $status);
            USK_Nodes::mark_seen($node['id'], 'online');
        }

        return array('ok' => true, 'rules' => $rules, 'log' => implode("\n", $logs));
    }

    public static function setup_all_hub_protocols(array $node)
    {
        $init = self::init_node($node);
        if (empty($init['ok'])) {
            return $init;
        }

        $errors = array();
        foreach (self::supported() as $proto) {
            if (!USK_ProtocolManager::is_installed($proto)) {
                continue;
            }
            $res = self::ensure_for_protocol($node, $proto, array());
            if (empty($res['ok'])) {
                $errors[] = $proto . ': ' . ($res['error'] ?? 'failed');
            }
        }

        return array(
            'ok' => $errors === array(),
            'error' => $errors === array() ? '' : implode('; ', $errors),
        );
    }

    public static function probe_status(array $node, $protocol)
    {
        $protocol = USK_ProtocolManager::sanitize_key($protocol);
        $root = self::node_root($node);
        $remote = sprintf(
            'sudo -n /bin/bash %s/bin/setup-node-relay.sh status 2>&1',
            escapeshellarg($root)
        );
        $res = USK_NodeSsh::run($node, $remote, 45);
        $out = (string) ($res['log'] ?? '');
        $active = !empty($res['ok']) && strpos($out, 'USK_OK') !== false;

        $expected = self::relay_rules_for_protocol($protocol, array());
        $ruleCount = 0;
        if (preg_match('/count=(\d+)/', $out, $m)) {
            $ruleCount = (int) $m[1];
        }

        $hubInstalled = USK_ProtocolManager::is_installed($protocol);
        $relayReady = $hubInstalled && $active && $ruleCount >= count($expected);

        return array(
            'ok' => !empty($res['ok']),
            'relay_active' => $relayReady,
            'hub_installed' => $hubInstalled,
            'rule_count' => $ruleCount,
            'expected_rules' => count($expected),
            'log' => $out,
        );
    }

    public static function read_cached_status(array $node, $protocol)
    {
        $protocols = is_array($node['protocols'] ?? null) ? $node['protocols'] : array();
        $st = $protocols[$protocol] ?? array();
        return is_array($st) ? $st : array();
    }

    public static function save_status($nodeId, $protocol, array $status)
    {
        USK_Nodes::save_protocol_status($nodeId, $protocol, $status);
    }

    public static function refresh_all(array $node)
    {
        $out = array();
        foreach (self::supported() as $proto) {
            $cached = self::read_cached_status($node, $proto);
            $probe = self::probe_status($node, $proto);
            $hubSt = USK_ProtocolManager::get_status($proto);
            $st = array_merge(USK_ProtocolManager::default_status_fields($proto), $cached, array(
                'hub_installed' => USK_ProtocolManager::is_installed($proto),
                'relay_active' => !empty($probe['relay_active']),
                'rule_count' => (int) ($probe['rule_count'] ?? 0),
                'verified_at' => date('c'),
            ));
            if ($proto === 'xray' && !empty($hubSt['vless_port'])) {
                $st['vless_port'] = (int) $hubSt['vless_port'];
                $st['port'] = (int) $hubSt['vless_port'];
            }
            if ($proto === 'openvpn') {
                $st['udp_port'] = (int) ($hubSt['udp_port'] ?? 1194);
                $st['tcp_port'] = (int) ($hubSt['tcp_port'] ?? 443);
            }
            if (!empty($node['id'])) {
                self::save_status($node['id'], $proto, $st);
            }
            $out[$proto] = $st;
        }
        return $out;
    }

    public static function list_meta()
    {
        $all = USK_ProtocolManager::list();
        $out = array();
        foreach (self::supported() as $proto) {
            if (isset($all[$proto])) {
                $out[$proto] = $all[$proto];
            }
        }
        return $out;
    }
}
