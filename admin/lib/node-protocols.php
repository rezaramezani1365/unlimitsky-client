<?php

require_once __DIR__ . '/node-ssh.php';

class USK_NodeProtocols
{
    /** Protocols that can be installed and provisioned on remote nodes. */
    public static function supported()
    {
        return array('xray', 'openvpn', 'l2tp');
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

    public static function node_root(array $node)
    {
        return rtrim((string) ($node['remote_root'] ?? '/opt/unlimitsky-node'), '/');
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

    /** Bin scripts synced from Hub to each node. */
    public static function script_manifest()
    {
        return array(
            'usk-common.sh',
            'provision-common.sh',
            'xray-common.sh',
            'xray-stats-state.sh',
            'enforce-xray-iplimit.sh',
            'install-fail2ban-iplimit.sh',
            'openvpn-common.sh',
            'l2tp-common.sh',
            'setup-l2tp-usage.sh',
            'l2tp-ip-up.sh',
            'l2tp-ip-down.sh',
            'collect-usage-stats.sh',
            'enforce-connection-limits.sh',
            'probe-protocol.sh',
            'install-xray.sh',
            'install-openvpn.sh',
            'install-l2tp.sh',
            'repair-xray.sh',
            'repair-openvpn.sh',
            'repair-l2tp.sh',
            'xray-fix-connectivity.sh',
            'refresh-xray-client-links.sh',
            'add-user-xray.sh',
            'add-user-openvpn.sh',
            'add-user-l2tp.sh',
            'remove-user-xray.sh',
            'remove-user-openvpn.sh',
            'remove-user-l2tp.sh',
            'disable-user-xray.sh',
            'enable-user-xray.sh',
            'disable-user-openvpn.sh',
            'enable-user-openvpn.sh',
            'disable-user-l2tp.sh',
            'enable-user-l2tp.sh',
            'node-receive-script.sh',
        );
    }

    public static function sync_scripts(array $node)
    {
        require_once __DIR__ . '/nodes.php';
        $root = self::node_root($node);
        $hub = self::hub_base();
        $cred = USK_Nodes::ssh_credentials($node);
        $sshUser = (string) ($cred['user'] ?? 'ubuntu');
        $binDir = $root . '/bin';

        $prep = sprintf(
            'mkdir -p %s %s && (test -w %s || sudo -n chown -R %s:%s %s)',
            escapeshellarg($binDir),
            escapeshellarg($root . '/data/protocol-installed'),
            escapeshellarg($binDir),
            escapeshellarg($sshUser),
            escapeshellarg($sshUser),
            escapeshellarg($binDir)
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
            $url = $hub . '/bin/' . $file;
            $res = self::push_script_to_node($node, $file, $hubLocal, $url, $dest, $receiver, $root);

            if (empty($res['ok'])) {
                $failed[] = $file;
                $failLog[] = $file . ': ' . ($res['log'] ?? 'sync_failed');
                continue;
            }

            $verify = USK_NodeSsh::run($node, 'test -x ' . escapeshellarg($dest), 20);
            if (empty($verify['ok'])) {
                $failed[] = $file;
                $diag = USK_NodeSsh::run(
                    $node,
                    'ls -la ' . escapeshellarg($binDir) . ' 2>&1 | tail -5',
                    20
                );
                $tail = trim((string) ($diag['log'] ?? ''));
                $failLog[] = $file . ': missing or not executable after sync'
                    . ($tail !== '' ? ' — ' . $tail : '');
            }
        }

        $log = $failLog === array() ? '' : implode("\n", $failLog);
        if ($failed !== array() && strpos($log, 'not writable') === false) {
            $log .= "\nHint: on node run: sudo chown -R " . $sshUser . ':' . $sshUser . ' ' . $binDir
                . ' — or re-run install-node.sh from Hub Nodes page.';
        }

        return array(
            'ok' => $failed === array(),
            'failed' => $failed,
            'synced' => count(self::script_manifest()) - count($failed),
            'hub' => $hub,
            'log' => $log,
        );
    }

    /** Push one bin script: Hub filesystem over SSH first, curl from node as fallback. */
    private static function push_script_to_node(
        array $node,
        $file,
        $hubLocal,
        $url,
        $dest,
        $receiver,
        $root
    ) {
        if (!is_readable($hubLocal)) {
            return array(
                'ok' => false,
                'log' => 'missing on hub disk: ' . $hubLocal . ' (run panel update on Hub)',
            );
        }

        $staging = '/tmp/.usk-sync-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $file);
        $put = USK_NodeSsh::put_file($node, $hubLocal, $staging, 90);
        if (!empty($put['ok'])) {
            $move = sprintf(
                'mv -f %s %s && chmod 755 %s && test -s %s',
                escapeshellarg($staging),
                escapeshellarg($dest),
                escapeshellarg($dest),
                escapeshellarg($dest)
            );
            $res = USK_NodeSsh::run($node, $move, 30);
            if (!empty($res['ok'])) {
                return $res;
            }
            $install = sprintf(
                'test -x %s && sudo -n /bin/bash %s %s %s',
                escapeshellarg($receiver),
                escapeshellarg($receiver),
                escapeshellarg($staging),
                escapeshellarg($dest)
            );
            $res = USK_NodeSsh::run($node, $install, 60);
            USK_NodeSsh::run($node, 'rm -f ' . escapeshellarg($staging), 10);
            if (!empty($res['ok'])) {
                return $res;
            }
        }

        $curlCmd = self::build_curl_download_cmd($url, $dest, $staging);
        $res = USK_NodeSsh::run($node, $curlCmd, 90);
        USK_NodeSsh::run($node, 'rm -f ' . escapeshellarg($staging), 10);
        if (!empty($res['ok'])) {
            return $res;
        }

        $tail = trim(substr((string) ($res['log'] ?? $put['log'] ?? ''), -400));
        return array('ok' => false, 'log' => $tail !== '' ? $tail : 'push and curl both failed');
    }

    private static function build_curl_download_cmd($url, $dest, $staging)
    {
        $errFile = $staging . '.err';
        return sprintf(
            'err=%s; code=$(curl -fsSL -w %%{http_code} -o %s %s 2>"$err" || echo fail); '
            . 'if [ "$code" = "fail" ] || [ -z "$code" ] || [ "$code" = "000" ]; then '
            . 'echo USK_ERR:curl_failed http=${code:-unknown}; cat "$err" 2>/dev/null; '
            . 'ls -la %s 2>/dev/null; exit 1; fi; '
            . 'chmod 755 %s && test -s %s',
            escapeshellarg($errFile),
            escapeshellarg($dest),
            escapeshellarg($url),
            escapeshellarg(dirname($dest)),
            escapeshellarg($dest),
            escapeshellarg($dest)
        );
    }

    public static function probe(array $node, $proto)
    {
        $proto = USK_ProtocolManager::sanitize_key($proto);
        if ($proto === '' || !in_array($proto, self::supported(), true)) {
            return array('ok' => false, 'installed' => false, 'error' => 'invalid_protocol');
        }

        $root = self::node_root($node);
        $remote = sprintf(
            'sudo -n /bin/bash %s/bin/probe-protocol.sh %s %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($proto),
            escapeshellarg($root)
        );
        $res = USK_NodeSsh::run($node, $remote, 60);
        $out = (string) ($res['log'] ?? '');
        $installed = strpos($out, 'USK_OK') !== false;

        return array(
            'ok' => !empty($res['ok']),
            'installed' => $installed,
            'log' => $out,
            'error' => $installed ? '' : self::probe_error_from_output($out, $proto),
        );
    }

    private static function probe_error_from_output($out, $proto)
    {
        if (preg_match('/USK_ERR:\s*(\S+)/', (string) $out, $m)) {
            return trim($m[1]);
        }
        return $proto . '_not_installed';
    }

    public static function is_installed(array $node, $proto)
    {
        $cached = self::read_cached_status($node, $proto);
        if (!empty($cached['installed']) && !empty($cached['verified_at'])) {
            $verified = strtotime((string) $cached['verified_at']);
            if ($verified && (time() - $verified) < 120) {
                return true;
            }
        }
        $probe = self::probe($node, $proto);
        if (!empty($probe['installed'])) {
            self::save_status($node['id'] ?? '', $proto, array_merge($cached, array(
                'installed' => true,
                'status' => 'active',
                'verified_at' => date('c'),
            )));
            return true;
        }
        if (!empty($cached['installed'])) {
            self::save_status($node['id'] ?? '', $proto, array_merge($cached, array(
                'installed' => false,
                'status' => 'not_installed',
                'verified_at' => date('c'),
            )));
        }
        return false;
    }

    public static function read_cached_status(array $node, $proto)
    {
        $proto = USK_ProtocolManager::sanitize_key($proto);
        $protocols = is_array($node['protocols'] ?? null) ? $node['protocols'] : array();
        $st = $protocols[$proto] ?? array();
        return is_array($st) ? $st : array();
    }

    public static function get_status(array $node, $proto, $refresh = false)
    {
        $proto = USK_ProtocolManager::sanitize_key($proto);
        $cached = self::read_cached_status($node, $proto);
        if (!$refresh && !empty($cached['installed']) && !empty($cached['verified_at'])) {
            $verified = strtotime((string) $cached['verified_at']);
            if ($verified && (time() - $verified) < 300) {
                return $cached;
            }
        }

        $probe = self::probe($node, $proto);
        $st = array_merge(USK_ProtocolManager::default_status_fields($proto), $cached, array(
            'installed' => !empty($probe['installed']),
            'status' => !empty($probe['installed']) ? 'active' : 'not_installed',
            'verified_at' => date('c'),
        ));
        if (!empty($node['id'])) {
            self::save_status($node['id'], $proto, $st);
            $fresh = USK_Nodes::get($node['id']);
            if ($fresh) {
                $st = self::read_cached_status($fresh, $proto);
            }
        }
        return $st;
    }

    public static function refresh_all(array $node)
    {
        $out = array();
        foreach (self::supported() as $proto) {
            $out[$proto] = self::get_status($node, $proto, true);
        }
        return $out;
    }

    public static function save_status($nodeId, $proto, array $status)
    {
        USK_Nodes::save_protocol_status($nodeId, $proto, $status);
    }

    public static function parse_install_meta($proto, $out)
    {
        $status = array(
            'installed' => strpos((string) $out, 'USK_OK') !== false,
            'status' => strpos((string) $out, 'USK_OK') !== false ? 'active' : 'failed',
            'updated_at' => date('c'),
            'verified_at' => date('c'),
            'log' => substr((string) $out, -2000),
        );
        $status = array_merge($status, USK_ProtocolManager::default_status_fields($proto));

        if ($proto === 'xray' && preg_match('/USK_META:vless_port=(\d+)/', $out, $m)) {
            $status['vless_port'] = (int) $m[1];
            $status['port'] = (int) $m[1];
            $sni = 'www.microsoft.com';
            if (preg_match('/sni=([^;\s]+)/', $out, $sm)) {
                $sni = $sm[1];
            }
            $status['reality_sni'] = $sni;
            $status['firewall_note'] = 'Open TCP ' . $m[1] . ' on the Node firewall.';
        } elseif ($proto === 'openvpn' && preg_match('/USK_META:udp_port=(\d+);tcp_port=(\d+)/', $out, $m)) {
            $status['udp_port'] = (int) $m[1];
            $status['tcp_port'] = (int) $m[2];
            $status['port'] = (int) $m[1];
            $status['firewall_note'] = 'Open UDP ' . $m[1] . ' and TCP ' . $m[2] . ' on the Node firewall.';
        } elseif ($proto === 'l2tp' && strpos($out, 'USK_OK') !== false) {
            $status['port'] = 1701;
            $status['firewall_note'] = 'Open UDP 500, 4500, and 1701 on the Node firewall.';
        }

        return $status;
    }

    public static function install(array $node, $proto, array $ports = array())
    {
        $proto = USK_ProtocolManager::sanitize_key($proto);
        if ($proto === '' || !in_array($proto, self::supported(), true)) {
            return array('ok' => false, 'error' => 'invalid_protocol');
        }

        $sync = self::sync_scripts($node);
        if (empty($sync['ok'])) {
            $hub = (string) ($sync['hub'] ?? self::hub_base());
            $failed = implode(', ', $sync['failed'] ?? array());
            $detail = trim((string) ($sync['log'] ?? ''));
            $log = sprintf('Hub %s/bin/ — failed: %s', $hub, $failed);
            if ($detail !== '') {
                $log .= "\n" . $detail;
            }
            return array(
                'ok' => false,
                'error' => 'node_scripts_sync_failed',
                'log' => $log,
            );
        }

        $root = self::node_root($node);
        $script = $root . '/bin/install-' . $proto . '.sh';
        $verify = USK_NodeSsh::run($node, 'test -x ' . escapeshellarg($script), 20);
        if (empty($verify['ok'])) {
            $hub = (string) ($sync['hub'] ?? self::hub_base());
            $hubLocal = rtrim((string) USK_ROOT, '/') . '/bin/install-' . $proto . '.sh';
            $diag = USK_NodeSsh::run(
                $node,
                'ls -la ' . escapeshellarg($root . '/bin') . ' 2>&1 | tail -8',
                20
            );
            $log = sprintf(
                'Missing on node after sync: %s (Hub %s/bin/install-%s.sh)',
                $script,
                $hub,
                $proto
            );
            if (!is_readable($hubLocal)) {
                $log .= "\nHub disk missing: " . $hubLocal . ' — run panel self-update on Hub.';
            }
            $tail = trim((string) ($diag['log'] ?? ''));
            if ($tail !== '') {
                $log .= "\nNode bin/: " . $tail;
            }
            if (!empty($sync['log'])) {
                $log .= "\n" . trim((string) $sync['log']);
            }
            return array(
                'ok' => false,
                'error' => 'node_scripts_sync_failed',
                'log' => $log,
            );
        }

        if (empty($ports)) {
            $ports = USK_ProtocolManager::parse_ports($proto, array());
        }

        $argv = USK_ProtocolManager::build_install_argv($proto, $ports);
        $env = 'PANEL_ROOT=' . escapeshellarg($root) . ' USK_DATA_ROOT=/var/lib/unlimitsky';
        $remote = sprintf(
            'sudo -n env %s /bin/bash %s',
            $env,
            escapeshellarg($script)
        );
        if ($argv !== '') {
            $remote .= ' ' . $argv;
        }
        $remote .= ' 2>&1';

        $res = USK_NodeSsh::run($node, $remote, 600);
        $out = (string) ($res['log'] ?? '');
        $parsed = self::parse_install_meta($proto, $out);
        $ok = !empty($parsed['installed']);

        if (!empty($node['id'])) {
            self::save_status($node['id'], $proto, $parsed);
            USK_Nodes::mark_seen($node['id'], !empty($res['ok']) ? 'online' : 'offline', $ok ? '' : ($parsed['log'] ?? 'install_failed'));
        }

        return array(
            'ok' => $ok && !empty($res['ok']),
            'error' => $ok ? '' : self::probe_error_from_output($out, $proto),
            'log' => $out,
            'status' => $parsed,
        );
    }

    public static function port_defaults_for_create(array $node, $proto)
    {
        $st = self::get_status($node, $proto);
        return USK_ProtocolManager::port_defaults_for_create($proto) + $st;
    }
}
