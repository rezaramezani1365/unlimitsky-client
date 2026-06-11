<?php

require_once __DIR__ . '/node-relay.php';

/**
 * Backward-compatible facade — nodes now use Hub-side VPN + port relay (no protocol install on node).
 */
class USK_NodeProtocols
{
    public static function supported()
    {
        return USK_NodeRelay::supported();
    }

    public static function list_meta()
    {
        return USK_NodeRelay::list_meta();
    }

    public static function node_root(array $node)
    {
        return USK_NodeRelay::node_root($node);
    }

    public static function hub_base()
    {
        return USK_NodeRelay::hub_base();
    }

    public static function script_manifest()
    {
        return USK_NodeRelay::script_manifest();
    }

    public static function sync_scripts(array $node)
    {
        return USK_NodeRelay::sync_scripts($node);
    }

    public static function read_cached_status(array $node, $proto)
    {
        return USK_NodeRelay::read_cached_status($node, $proto);
    }

    public static function save_status($nodeId, $proto, array $status)
    {
        USK_NodeRelay::save_status($nodeId, $proto, $status);
    }

    public static function refresh_all(array $node)
    {
        return USK_NodeRelay::refresh_all($node);
    }

    /** @deprecated Nodes no longer install protocols — checks Hub install + relay status. */
    public static function is_installed(array $node, $proto)
    {
        if (!USK_ProtocolManager::is_installed($proto)) {
            return false;
        }
        $st = self::read_cached_status($node, $proto);
        if (!empty($st['relay_active'])) {
            return true;
        }
        $probe = USK_NodeRelay::probe_status($node, $proto);
        return !empty($probe['relay_active']);
    }

    public static function get_status(array $node, $proto, $refresh = false)
    {
        if ($refresh) {
            $all = self::refresh_all($node);
            return $all[$proto] ?? USK_NodeRelay::read_cached_status($node, $proto);
        }
        return USK_NodeRelay::read_cached_status($node, $proto);
    }

    public static function probe(array $node, $proto)
    {
        return USK_NodeRelay::probe_status($node, $proto);
    }

    /** Setup port relay on node for a Hub-installed protocol. */
    public static function install(array $node, $proto, array $ports = array())
    {
        $proto = USK_ProtocolManager::sanitize_key($proto);
        if ($proto === '' || !in_array($proto, self::supported(), true)) {
            return array('ok' => false, 'error' => 'invalid_protocol');
        }
        if (!USK_ProtocolManager::is_installed($proto)) {
            return array('ok' => false, 'error' => $proto . '_not_installed');
        }

        $meta = array();
        if ($proto === 'openvpn') {
            if (!empty($ports['tcp_port'])) {
                $meta['openvpn_proto'] = 'tcp';
            } elseif (!empty($ports['udp_port'])) {
                $meta['openvpn_proto'] = 'udp';
            }
        }

        $res = USK_NodeRelay::ensure_for_protocol($node, $proto, $meta);
        $ok = !empty($res['ok']);
        $parsed = array(
            'installed' => $ok,
            'relay_active' => $ok,
            'status' => $ok ? 'active' : 'failed',
            'updated_at' => date('c'),
            'verified_at' => date('c'),
            'hub_installed' => true,
            'log' => substr((string) ($res['log'] ?? ''), -2000),
        );
        $parsed = array_merge($parsed, USK_ProtocolManager::default_status_fields($proto));
        $hubSt = USK_ProtocolManager::get_status($proto);
        if ($proto === 'xray' && !empty($hubSt['vless_port'])) {
            $parsed['vless_port'] = (int) $hubSt['vless_port'];
            $parsed['port'] = (int) $hubSt['vless_port'];
            $parsed['firewall_note'] = 'Open TCP ' . $parsed['vless_port'] . ' on the Node firewall (relay to Hub).';
        } elseif ($proto === 'openvpn') {
            $parsed['udp_port'] = (int) ($hubSt['udp_port'] ?? 1194);
            $parsed['tcp_port'] = (int) ($hubSt['tcp_port'] ?? 443);
            $parsed['firewall_note'] = 'Open UDP/TCP VPN ports on the Node firewall (relay to Hub).';
        } elseif ($proto === 'l2tp') {
            $parsed['port'] = 1701;
            $parsed['firewall_note'] = 'Open UDP 500, 4500, 1701 on the Node firewall (relay to Hub).';
        }

        if (!empty($node['id'])) {
            self::save_status($node['id'], $proto, $parsed);
        }

        return array(
            'ok' => $ok,
            'error' => $ok ? '' : ($res['error'] ?? 'relay_failed'),
            'log' => (string) ($res['log'] ?? ''),
            'status' => $parsed,
        );
    }

    public static function port_defaults_for_create(array $node, $proto)
    {
        $hubSt = USK_ProtocolManager::get_status($proto);
        return USK_ProtocolManager::port_defaults_for_create($proto) + $hubSt;
    }
}
