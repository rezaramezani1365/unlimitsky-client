<?php

function usk_config_download_url($code, $token)
{
    global $config;
    $base = rtrim($config['domain'] ?? '', '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . '/download-config.php?code=' . rawurlencode((string) $code) . '&t=' . rawurlencode((string) $token);
}

function usk_serve_openvpn_download($code, $token)
{
    global $sql;

    if ($code === '' || $token === '' || !$sql instanceof mysqli) {
        return false;
    }

    $code_esc = $sql->real_escape_string($code);
    $order = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1")->fetch_assoc();
    if (!$order) {
        return false;
    }

    require_once __DIR__ . '/admin/lib/protocols/limits.php';
    $native = USK_ProtocolLimits::find_client_for_order($order);
    if (!$native || ($native['protocol'] ?? '') !== 'openvpn') {
        return false;
    }

    $client = $native['client'] ?? array();
    $stored = $client['download_token']
        ?? ($client['meta']['download_token'] ?? '');
    if ($stored === '' || !hash_equals((string) $stored, (string) $token)) {
        return false;
    }

    $username = $native['username'] ?? '';
    $filename = ($client['ovpn_filename'] ?? ($client['meta']['ovpn_filename'] ?? ''));
    if ($filename === '') {
        $filename = $username !== '' ? ($username . '.ovpn') : 'client.ovpn';
    }

    $profile = USK_ROOT . '/data/openvpn/profiles/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $username) . '.ovpn';
    if (is_file($profile)) {
        $body = file_get_contents($profile);
    } else {
        $body = $client['config'] ?? ($client['meta']['config'] ?? ($order['link'] ?? ''));
    }

    if ($body === '') {
        return false;
    }

    header('Content-Type: application/x-openvpn-profile');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');
    echo $body;
    return true;
}

function usk_serve_amnezia_download($code, $token)
{
    global $sql;

    if ($code === '' || $token === '' || !$sql instanceof mysqli) {
        return false;
    }

    $code_esc = $sql->real_escape_string($code);
    $order = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1")->fetch_assoc();
    if (!$order) {
        return false;
    }

    require_once __DIR__ . '/admin/lib/protocols/limits.php';
    $native = USK_ProtocolLimits::find_client_for_order($order);
    if (!$native || ($native['protocol'] ?? '') !== 'amnezia') {
        return false;
    }

    $client = $native['client'] ?? array();
    $stored = $client['download_token']
        ?? ($client['meta']['download_token'] ?? '');
    if ($stored === '' || !hash_equals((string) $stored, (string) $token)) {
        return false;
    }

    $username = $native['username'] ?? '';
    $filename = ($client['conf_filename'] ?? ($client['meta']['conf_filename'] ?? ''));
    if ($filename === '') {
        $filename = $username !== '' ? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.conf') : 'amnezia_for_awg.conf';
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    $profile = USK_ROOT . '/data/amnezia/profiles/' . $safe . '.conf';
    if (is_file($profile)) {
        $body = file_get_contents($profile);
    } else {
        $body = $client['wg_conf'] ?? ($client['meta']['wg_conf'] ?? ($client['config'] ?? ''));
    }

    if ($body === '') {
        return false;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');
    echo $body;
    return true;
}

function usk_serve_xray_download($code, $token)
{
    global $sql;

    if ($code === '' || $token === '' || !$sql instanceof mysqli) {
        return false;
    }

    $code_esc = $sql->real_escape_string($code);
    $order = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1")->fetch_assoc();
    if (!$order) {
        return false;
    }

    require_once __DIR__ . '/admin/lib/protocols/limits.php';
    $native = USK_ProtocolLimits::find_client_for_order($order);
    if (!$native || ($native['protocol'] ?? '') !== 'xray') {
        return false;
    }

    $client = $native['client'] ?? array();
    $stored = $client['download_token']
        ?? ($client['meta']['download_token'] ?? '');
    if ($stored === '' || !hash_equals((string) $stored, (string) $token)) {
        return false;
    }

    $username = $native['username'] ?? '';
    $filename = ($client['json_filename'] ?? ($client['meta']['json_filename'] ?? ''));
    if ($filename === '') {
        $filename = $username !== '' ? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.json') : 'xray-client.json';
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    $profile = USK_ROOT . '/data/xray/profiles/' . $safe . '.json';
    if (is_file($profile)) {
        $body = file_get_contents($profile);
    } else {
        $body = $client['client_json'] ?? ($client['meta']['client_json'] ?? '');
    }

    if ($body === '') {
        return false;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');
    echo $body;
    return true;
}

function usk_serve_xray_download($code, $token)
{
    global $sql;

    if ($code === '' || $token === '' || !$sql instanceof mysqli) {
        return false;
    }

    $code_esc = $sql->real_escape_string($code);
    $order = $sql->query("SELECT * FROM `orders` WHERE `code`='$code_esc' LIMIT 1")->fetch_assoc();
    if (!$order) {
        return false;
    }

    require_once __DIR__ . '/admin/lib/protocols/limits.php';
    $native = USK_ProtocolLimits::find_client_for_order($order);
    if (!$native || ($native['protocol'] ?? '') !== 'xray') {
        return false;
    }

    $client = $native['client'] ?? array();
    $stored = $client['download_token']
        ?? ($client['meta']['download_token'] ?? '');
    if ($stored === '' || !hash_equals((string) $stored, (string) $token)) {
        return false;
    }

    $username = $native['username'] ?? '';
    $filename = ($client['json_filename'] ?? ($client['meta']['json_filename'] ?? ''));
    if ($filename === '') {
        $filename = $username !== '' ? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.json') : 'xray-client.json';
    }

    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $username);
    $profile = USK_ROOT . '/data/xray/profiles/' . $safe . '.json';
    if (is_file($profile)) {
        $body = file_get_contents($profile);
    } else {
        $body = $client['client_json'] ?? ($client['meta']['client_json'] ?? '');
    }

    if ($body === '') {
        return false;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store');
    echo $body;
    return true;
}
