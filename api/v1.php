<?php

define('USK_API', true);
define('USK_ROOT', dirname(__DIR__));

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/lib/protocols/manager.php';
require_once dirname(__DIR__) . '/admin/lib/protocols/provisioner.php';
require_once dirname(__DIR__) . '/admin/lib/api-keys.php';
require_once dirname(__DIR__) . '/admin/lib/license.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function usk_api_response($code, $payload)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = preg_replace('/[^a-z-]/', '', $_GET['action'] ?? 'health');

if ($action === 'health') {
    usk_api_response(200, array(
        'ok' => true,
        'service' => 'UnlimitSky API',
        'version' => '1.0',
        'protocols' => array_keys(USK_ProtocolManager::installed_protocols()),
    ));
}

USK_ApiKeys::require_auth();

if ($action === 'protocols') {
    $installed = USK_ProtocolManager::installed_protocols();
    $list = array();
    foreach ($installed as $key => $meta) {
        $st = USK_ProtocolManager::get_status($key);
        $ports = USK_ProtocolManager::port_defaults_for_create($key);
        $list[] = array(
            'id' => $key,
            'name' => $meta['name'],
            'port' => $meta['port'],
            'ports' => $ports,
            'installed_port' => $st['port'] ?? null,
        );
    }
    usk_api_response(200, array('ok' => true, 'protocols' => $list));
}

if ($action === 'create-service') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        usk_api_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        usk_api_response(400, array('ok' => false, 'error' => 'invalid_json'));
    }

    $protocol = preg_replace('/[^a-z]/', '', $body['protocol'] ?? '');
    $volume_gb = (int) ($body['volume_gb'] ?? 0);
    $duration_days = (int) ($body['duration_days'] ?? 0);
    $wc_order_id = isset($body['wc_order_id']) ? (int) $body['wc_order_id'] : null;
    $server_ip = trim($body['server_ip'] ?? '');
    $plan_code = preg_replace('/[^0-9]/', '', (string) ($body['plan_code'] ?? ''));

    if ($plan_code !== '' && !USK_License::plan_is_usable($plan_code)) {
        usk_api_response(403, array('ok' => false, 'error' => 'plan_inactive_or_missing'));
    }

    if ($protocol === '') {
        usk_api_response(400, array('ok' => false, 'error' => 'protocol_required'));
    }

    $code = (string) rand(111111, 999999);
    $suffix = $wc_order_id ? ('wc' . $wc_order_id) : 'api';
    $username = !empty($body['username'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '', $body['username'])
        : base64_encode($code) . '_' . $suffix . '_' . time();

    if ($username === '') {
        usk_api_response(400, array('ok' => false, 'error' => 'invalid_username'));
    }

    $created = USK_ProtocolProvisioner::create($protocol, $username, $volume_gb, $duration_days, array(
        'source' => 'woocommerce',
        'wc_order_id' => $wc_order_id,
        'server_ip' => $server_ip,
        'port' => isset($body['port']) ? (int) $body['port'] : null,
        'vless_port' => isset($body['vless_port']) ? (int) $body['vless_port'] : null,
        'vmess_port' => isset($body['vmess_port']) ? (int) $body['vmess_port'] : null,
    ));

    if (empty($created['ok'])) {
        usk_api_response(500, array(
            'ok' => false,
            'error' => $created['error'] ?? 'provision_failed',
        ));
    }

    $order = USK_ProtocolProvisioner::save_order(
        $protocol,
        $username,
        $volume_gb,
        $duration_days,
        $created['links'] ?: $created['config'],
        'api',
        $wc_order_id
    );

    if (empty($order['ok'])) {
        usk_api_response(500, array(
            'ok' => false,
            'error' => $order['error'] ?? 'order_save_failed',
            'provisioned' => true,
        ));
    }

    usk_api_response(200, array(
        'ok' => true,
        'username' => $username,
        'protocol' => $protocol,
        'service_code' => $order['code'],
        'subscription_url' => $created['subscription'],
        'config' => $created['config'],
        'config_links' => $created['links'],
        'volume_gb' => $volume_gb,
        'duration_days' => $duration_days,
        'qr_png' => $created['qr_png'] ?? '',
        'expires_at' => $created['expires_at'] ?? null,
    ));
}

usk_api_response(404, array('ok' => false, 'error' => 'unknown_action'));
