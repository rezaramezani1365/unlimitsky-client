<?php

function usk_client_meta($client, $key, $default = '')
{
    if (!$client || !is_array($client)) {
        return $default;
    }
    if (isset($client[$key]) && $client[$key] !== '' && $client[$key] !== null) {
        return $client[$key];
    }
    if (!empty($client['meta'][$key])) {
        return $client['meta'][$key];
    }
    return $default;
}

function usk_client_meta_string($client, $key)
{
    return trim((string) usk_client_meta($client, $key, ''));
}

function usk_service_download_url($order, $client)
{
    if (!$client || !is_array($order)) {
        return '';
    }
    $link = trim((string) ($order['link'] ?? ''));
    if ($link !== '' && strpos($link, 'download-config.php') !== false) {
        return $link;
    }
    $token = usk_client_meta_string($client, 'download_token');
    if ($token === '') {
        return '';
    }
    require_once __DIR__ . '/config-download.php';
    return usk_config_download_url($order['code'] ?? '', $token);
}

function usk_service_download_filename($order, $client, $username = '')
{
    $protocol = (string) ($order['protocol'] ?? '');
    if ($protocol === 'openvpn') {
        $name = usk_client_meta_string($client, 'ovpn_filename');
        return $name !== '' ? $name : ($username !== '' ? $username . '.ovpn' : 'client.ovpn');
    }
    if ($protocol === 'amnezia') {
        $name = usk_client_meta_string($client, 'conf_filename');
        return $name !== '' ? $name : ($username !== '' ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.conf' : 'amnezia.conf');
    }
    if ($protocol === 'xray') {
        $name = usk_client_meta_string($client, 'json_filename');
        return $name !== '' ? $name : ($username !== '' ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.json' : 'xray-client.json');
    }
    return 'config';
}

function usk_service_download_label($protocol)
{
    if ($protocol === 'amnezia') {
        return __('download_amnezia_conf');
    }
    if ($protocol === 'xray') {
        return __('download_xray_json');
    }
    return __('download_ovpn');
}

/** Primary copy-paste config (VLESS URI, subscription link, etc.). */
function usk_service_primary_config($order, $client)
{
    if (!$order) {
        return '';
    }
    $protocol = (string) ($order['protocol'] ?? '');

    if ($protocol === 'xray') {
        $vless = usk_client_meta_string($client, 'vless');
        if ($vless !== '') {
            return $vless;
        }
    }
    if ($protocol === 'amnezia') {
        $uri = usk_client_meta_string($client, 'vpn_uri');
        if ($uri !== '') {
            return $uri;
        }
    }

    $link = trim((string) ($order['link'] ?? ''));
    if ($link !== '' && strpos($link, 'download-config.php') === false) {
        return $link;
    }

    if ($client) {
        $cfg = usk_client_meta_string($client, 'config');
        if ($cfg !== '' && strpos($cfg, 'download-config.php') === false) {
            return $cfg;
        }
        $links = usk_client_meta_string($client, 'links');
        if ($links !== '') {
            return $links;
        }
        $sub = usk_client_meta_string($client, 'subscription_url');
        if ($sub !== '') {
            return $sub;
        }
    }

    return '';
}

function usk_service_secondary_config($order, $client)
{
    $protocol = (string) ($order['protocol'] ?? '');
    if ($protocol !== 'amnezia') {
        return '';
    }
    return usk_client_meta_string($client, 'wg_conf');
}

function usk_service_portal_url($order, $client)
{
    if (!$client || !is_array($order)) {
        return '';
    }
    $link = trim((string) ($order['link'] ?? ''));
    if ($link !== '' && strpos($link, 'service.php') !== false) {
        return $link;
    }
    $token = usk_client_meta_string($client, 'download_token');
    $code = (string) ($order['code'] ?? '');
    if ($token === '' || $code === '') {
        return '';
    }
    require_once __DIR__ . '/customer-portal.php';
    return usk_customer_portal_url($code, $token);
}

function usk_service_usage_stats($order, $client)
{
    if (!$client || !is_array($order)) {
        return null;
    }
    require_once __DIR__ . '/protocols/usage.php';
    $protocol = (string) ($order['protocol'] ?? '');
    $volumeGb = (int) ($order['volume'] ?? ($client['volume_gb'] ?? 0));
    return USK_ProtocolUsage::usage_stats($protocol, $client, $volumeGb);
}
