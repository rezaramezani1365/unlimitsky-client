<?php

require_once __DIR__ . '/config-download.php';
require_once __DIR__ . '/service-config-view.php';
require_once __DIR__ . '/protocols/usage.php';

function usk_customer_portal_url($code, $token)
{
    $base = usk_public_base_url();
    return $base . '/service.php?code=' . rawurlencode((string) $code) . '&t=' . rawurlencode((string) $token);
}

class USK_CustomerPortal
{
    public static function load($code, $token)
    {
        global $sql;

        $code = preg_replace('/[^0-9]/', '', (string) $code);
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $token));
        if ($code === '' || $token === '' || !$sql instanceof mysqli) {
            return array('ok' => false, 'error' => 'invalid_request');
        }

        $code_esc = $sql->real_escape_string($code);
        $orderRes = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1");
        $order = $orderRes ? $orderRes->fetch_assoc() : null;
        if (!$order) {
            return array('ok' => false, 'error' => 'not_found');
        }

        if (($order['type'] ?? '') !== 'native') {
            return array('ok' => false, 'error' => 'unsupported');
        }

        $native = USK_ProtocolLimits::find_client_for_order($order);
        if (!$native) {
            return array('ok' => false, 'error' => 'removed');
        }

        $client = $native['client'] ?? array();
        $stored = (string) ($client['download_token'] ?? ($client['meta']['download_token'] ?? ''));
        if ($stored === '' || !hash_equals($stored, $token)) {
            return array('ok' => false, 'error' => 'invalid_token');
        }

        $status = (string) ($client['status'] ?? 'active');
        if ($status === 'revoked') {
            return array('ok' => false, 'error' => 'removed');
        }

        $protocol = (string) ($native['protocol'] ?? ($order['protocol'] ?? ''));
        $meta = USK_ProtocolLimits::client_meta($client);
        $volumeGb = (int) ($order['volume'] ?? ($meta['volume_gb'] ?? 0));
        $durationDays = (int) ($order['date'] ?? ($meta['duration_days'] ?? 0));
        $expiresAt = (string) ($meta['expires_at'] ?? '');
        $maxConnections = max(1, (int) ($meta['max_connections'] ?? 1));

        $usage = USK_ProtocolUsage::usage_stats($protocol, $meta, $volumeGb);
        $remaining = self::remaining_time($expiresAt, $durationDays, $meta['created'] ?? ($meta['created_at'] ?? ''));

        $primaryLink = usk_service_primary_config($order, $meta);
        if ($primaryLink !== '' && strpos($primaryLink, 'service.php') !== false) {
            $primaryLink = '';
        }
        if ($protocol === 'xray') {
            $primaryLink = usk_client_meta_string($meta, 'vless') ?: $primaryLink;
        } elseif ($protocol === 'amnezia') {
            $primaryLink = usk_client_meta_string($meta, 'vpn_uri') ?: $primaryLink;
        } elseif ($protocol === 'wireguard') {
            $primaryLink = usk_client_meta_string($meta, 'config') ?: $primaryLink;
        }

        $qrB64 = (string) ($meta['qr_png'] ?? '');
        if ($primaryLink !== '' && ($qrB64 === '' || $protocol === 'xray')) {
            $generated = USK_ProtocolUsage::qr_png_b64($primaryLink);
            if ($generated !== '') {
                $qrB64 = $generated;
            }
        }

        $downloadUrl = usk_config_download_url($code, $token);
        $downloadFilename = self::download_filename($protocol, $native['username'] ?? '', $meta);

        return array(
            'ok' => true,
            'order' => $order,
            'protocol' => $protocol,
            'username' => (string) ($native['username'] ?? ''),
            'status' => $status,
            'service_status' => self::service_status_label($status, $remaining, $usage),
            'volume_gb' => $volumeGb,
            'duration_days' => $durationDays,
            'expires_at' => $expiresAt,
            'remaining' => $remaining,
            'usage' => $usage,
            'max_connections' => $maxConnections,
            'primary_link' => $primaryLink,
            'qr_b64' => $qrB64,
            'download_url' => $downloadUrl,
            'download_filename' => $downloadFilename,
            'credentials' => self::credentials($protocol, $meta),
            'wg_conf' => usk_client_meta_string($meta, 'wg_conf'),
            'apps' => self::app_links($protocol),
            'show_qr' => in_array($protocol, array('xray', 'wireguard', 'amnezia'), true)
                && ($primaryLink !== '' || $qrB64 !== ''),
            'show_copy_link' => in_array($protocol, array('xray', 'amnezia', 'wireguard'), true) && $primaryLink !== '',
            'show_download' => in_array($protocol, array('openvpn', 'amnezia', 'xray', 'wireguard'), true),
        );
    }

    private static function service_status_label($status, array $remaining, array $usage)
    {
        if ($status === 'expired') {
            return 'expired';
        }
        if ($status === 'volume_exceeded') {
            return 'volume_exceeded';
        }
        if (!empty($remaining['expired'])) {
            return 'expired';
        }
        if (!empty($usage['exceeded'])) {
            return 'volume_exceeded';
        }
        return 'active';
    }

    private static function remaining_time($expiresAt, $durationDays, $createdAt)
    {
        $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : 0;
        if (!$expiresTs && $durationDays > 0 && $createdAt !== '') {
            $createdTs = strtotime($createdAt);
            if ($createdTs) {
                $expiresTs = $createdTs + ($durationDays * 86400);
            }
        }
        if (!$expiresTs && $durationDays > 0) {
            $expiresTs = time() + ($durationDays * 86400);
        }

        if (!$expiresTs) {
            return array(
                'expired' => false,
                'days' => null,
                'hours' => null,
                'label' => '',
                'expires_ts' => null,
            );
        }

        $diff = $expiresTs - time();
        if ($diff <= 0) {
            return array(
                'expired' => true,
                'days' => 0,
                'hours' => 0,
                'label' => '0',
                'expires_ts' => $expiresTs,
            );
        }

        $days = (int) floor($diff / 86400);
        $hours = (int) floor(($diff % 86400) / 3600);

        return array(
            'expired' => false,
            'days' => $days,
            'hours' => $hours,
            'label' => $days . 'd ' . $hours . 'h',
            'expires_ts' => $expiresTs,
        );
    }

    private static function usage_stats($protocol, array $meta, $volumeGb)
    {
        return USK_ProtocolUsage::usage_stats($protocol, $meta, $volumeGb);
    }

    private static function credentials($protocol, array $meta)
    {
        $out = array();
        if ($protocol === 'l2tp') {
            $out[] = array('key' => 'server', 'label' => __('portal_cred_server'), 'value' => usk_client_meta_string($meta, 'server_ip'));
            $out[] = array('key' => 'username', 'label' => __('username'), 'value' => (string) ($meta['username'] ?? ''));
            $out[] = array('key' => 'password', 'label' => __('password'), 'value' => usk_client_meta_string($meta, 'password'));
            $out[] = array('key' => 'psk', 'label' => __('portal_cred_psk'), 'value' => usk_client_meta_string($meta, 'psk'));
        } elseif ($protocol === 'cisco') {
            $out[] = array('key' => 'server', 'label' => __('portal_cred_server'), 'value' => usk_client_meta_string($meta, 'server_ip'));
            $port = usk_client_meta_string($meta, 'port');
            if ($port !== '') {
                $out[] = array('key' => 'port', 'label' => __('portal_cred_port'), 'value' => $port);
            }
            $out[] = array('key' => 'username', 'label' => __('username'), 'value' => (string) ($meta['username'] ?? ''));
            $out[] = array('key' => 'password', 'label' => __('password'), 'value' => usk_client_meta_string($meta, 'password'));
        } elseif ($protocol === 'wireguard') {
            $endpoint = usk_client_meta_string($meta, 'endpoint');
            if ($endpoint !== '') {
                $out[] = array('key' => 'endpoint', 'label' => __('portal_cred_endpoint'), 'value' => $endpoint);
            }
        }
        return array_values(array_filter($out, function ($row) {
            return trim((string) ($row['value'] ?? '')) !== '';
        }));
    }

    private static function download_filename($protocol, $username, array $meta)
    {
        if ($protocol === 'openvpn') {
            return usk_client_meta_string($meta, 'ovpn_filename') ?: ($username . '.ovpn');
        }
        if ($protocol === 'amnezia') {
            return usk_client_meta_string($meta, 'conf_filename') ?: (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.conf');
        }
        if ($protocol === 'xray') {
            return usk_client_meta_string($meta, 'json_filename') ?: (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.json');
        }
        if ($protocol === 'wireguard') {
            return preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.conf';
        }
        return 'config';
    }

    /** @return array<int, array{platform:string,label:string,url:string,icon:string,color:string}> */
    public static function app_links($protocol)
    {
        $windows = array('platform' => 'windows', 'label' => 'Windows', 'icon' => 'fa-brands fa-windows', 'color' => '#111111');
        $mac = array('platform' => 'mac', 'label' => 'macOS', 'icon' => 'fa-brands fa-apple', 'color' => '#6c757d');
        $ios = array('platform' => 'ios', 'label' => 'iOS', 'icon' => 'fa-brands fa-app-store-ios', 'color' => '#0d6efd');
        $android = array('platform' => 'android', 'label' => 'Android', 'icon' => 'fa-brands fa-google-play', 'color' => '#198754');

        $map = array(
            'xray' => array(
                array_merge($windows, array('url' => 'https://github.com/hiddify/hiddify-app/releases')),
                array_merge($mac, array('url' => 'https://github.com/hiddify/hiddify-app/releases')),
                array_merge($ios, array('url' => 'https://apps.apple.com/app/hiddify-proxy-vpn/id6596777532')),
                array_merge($android, array('url' => 'https://play.google.com/store/apps/details?id=app.hiddify.com')),
            ),
            'wireguard' => array(
                array_merge($windows, array('url' => 'https://www.wireguard.com/install/')),
                array_merge($mac, array('url' => 'https://www.wireguard.com/install/')),
                array_merge($ios, array('url' => 'https://apps.apple.com/app/wireguard/id1441195209')),
                array_merge($android, array('url' => 'https://play.google.com/store/apps/details?id=com.wireguard.android')),
            ),
            'openvpn' => array(
                array_merge($windows, array('url' => 'https://openvpn.net/client/')),
                array_merge($mac, array('url' => 'https://openvpn.net/client/')),
                array_merge($ios, array('url' => 'https://apps.apple.com/app/openvpn-connect/id590379981')),
                array_merge($android, array('url' => 'https://play.google.com/store/apps/details?id=net.openvpn.openvpn')),
            ),
            'amnezia' => array(
                array_merge($windows, array('url' => 'https://amnezia.org/downloads')),
                array_merge($mac, array('url' => 'https://amnezia.org/downloads')),
                array_merge($ios, array('url' => 'https://apps.apple.com/app/amneziavpn/id1600529900')),
                array_merge($android, array('url' => 'https://play.google.com/store/apps/details?id=org.amnezia.vpn')),
            ),
            'l2tp' => array(
                array_merge($windows, array('url' => 'https://support.microsoft.com/windows')),
                array_merge($mac, array('url' => 'https://support.apple.com/guide/mac-help/set-up-a-vpn-connection-on-mac-mchlp2963/mac')),
                array_merge($ios, array('url' => 'https://support.apple.com/guide/iphone/set-up-a-vpn-iph1a377714/ios')),
                array_merge($android, array('url' => 'https://support.google.com/android/answer/9089766')),
            ),
            'cisco' => array(
                array_merge($windows, array('url' => 'https://www.cisco.com/c/en/us/support/security/anyconnect-secure-mobility-client-v4-x/products-installation-guides-list.html')),
                array_merge($mac, array('url' => 'https://apps.apple.com/app/cisco-secure-client/id1135064690')),
                array_merge($ios, array('url' => 'https://apps.apple.com/app/cisco-secure-client/id1135064690')),
                array_merge($android, array('url' => 'https://play.google.com/store/apps/details?id=com.cisco.anyconnect.vpn.android.avf')),
            ),
        );

        return $map[$protocol] ?? array();
    }

    public static function protocol_label($protocol)
    {
        $labels = array(
            'xray' => 'Xray / VLESS',
            'wireguard' => 'WireGuard',
            'openvpn' => 'OpenVPN',
            'amnezia' => 'AmneziaWG',
            'l2tp' => 'L2TP/IPsec',
            'cisco' => 'Cisco AnyConnect',
        );
        return $labels[$protocol] ?? strtoupper($protocol);
    }
}
