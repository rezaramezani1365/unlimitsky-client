<?php

define('USK_API', true);
define('USK_ROOT', dirname(__DIR__));

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/admin/lib/panel-access.php';
USK_PanelAccess::enforce_request_host();
require_once dirname(__DIR__) . '/admin/lib/protocols/manager.php';
require_once dirname(__DIR__) . '/admin/lib/protocols/provisioner.php';
require_once dirname(__DIR__) . '/admin/lib/client-dns.php';
require_once dirname(__DIR__) . '/admin/lib/connect-host.php';
require_once dirname(__DIR__) . '/admin/lib/panel-access.php';
require_once dirname(__DIR__) . '/admin/lib/api-keys.php';
require_once dirname(__DIR__) . '/admin/lib/license.php';
require_once dirname(__DIR__) . '/admin/lib/nodes.php';
require_once dirname(__DIR__) . '/admin/lib/node-ssh.php';

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
        'service' => 'unlimitsky API',
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

if ($action === 'panels') {
    if (!USK_License::can_use_external_panels()) {
        usk_api_response(403, array('ok' => false, 'error' => 'panels_pro_required'));
    }
    global $sql;
    $panels = array();
    $res = $sql->query("SELECT `code`,`name`,`type`,`protocols`,`status`,`count_create` FROM `panels` WHERE `status`='active' AND `type` IN ('marzban','sanayi') ORDER BY `name` ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $panels[] = array(
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'protocols' => $row['protocols'],
                'protocol_label' => 'VLESS/VMess (Xray)',
                'count_create' => (int) $row['count_create'],
            );
        }
    }
    usk_api_response(200, array('ok' => true, 'panels' => $panels));
}

if ($action === 'plans') {
    $plans = USK_License::list_active_plans();
    usk_api_response(200, array(
        'ok' => true,
        'plans' => $plans,
        'max_plans' => USK_License::max_plans(),
        'plan_count' => USK_License::current_plan_count(),
    ));
}

if ($action === 'create-service') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        usk_api_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        usk_api_response(400, array('ok' => false, 'error' => 'invalid_json'));
    }

    $protocol = USK_ProtocolManager::sanitize_key($body['protocol'] ?? '');
    $panel_code = preg_replace('/[^0-9]/', '', (string) ($body['panel_code'] ?? ''));
    $volume_gb = (int) ($body['volume_gb'] ?? 0);
    $duration_days = (int) ($body['duration_days'] ?? 0);
    $wc_order_id = isset($body['wc_order_id']) ? (int) $body['wc_order_id'] : null;
    $server_ip = USK_ConnectHost::sanitize(trim((string) ($body['server_ip'] ?? '')));
    $plan_code = preg_replace('/[^0-9]/', '', (string) ($body['plan_code'] ?? ''));
    $max_connections = max(1, (int) ($body['max_connections'] ?? 1));
    $customer_email = USK_ProtocolProvisioner::sanitize_customer_email($body['customer_email'] ?? '');
    $usage_id_raw = trim((string) ($body['usage_id'] ?? ''));
    $usage_id = $usage_id_raw !== '' ? USK_ProtocolProvisioner::sanitize_usage_id($usage_id_raw, (string) ($body['username'] ?? '')) : '';

    if ($plan_code !== '') {
        $planRow = USK_License::get_plan_by_code($plan_code);
        if ($planRow === null) {
            usk_api_response(403, array('ok' => false, 'error' => 'plan_inactive_or_missing'));
        }
        $volume_gb = max(1, (int) ($planRow['limit'] ?? 0));
        $duration_days = max(1, (int) ($planRow['date'] ?? 0));
        $max_connections = max(1, (int) ($planRow['connections'] ?? 1));
    }

    if ($panel_code !== '') {
        if (!USK_License::can_use_external_panels()) {
            usk_api_response(403, array('ok' => false, 'error' => 'panels_pro_required'));
        }
        if ($volume_gb < 1 || $duration_days < 1) {
            usk_api_response(400, array('ok' => false, 'error' => 'volume_duration_required'));
        }

        global $sql;
        $code_esc = $sql->real_escape_string($panel_code);
        $panel = $sql->query("SELECT * FROM `panels` WHERE `code`='$code_esc' AND `status`='active' LIMIT 1")->fetch_assoc();
        if (!$panel || !in_array($panel['type'], array('marzban', 'sanayi'), true)) {
            usk_api_response(400, array('ok' => false, 'error' => 'invalid_panel'));
        }

        require_once dirname(__DIR__) . '/admin/lib/service.php';

        $order_code = (string) rand(111111, 999999);
        $suffix = $wc_order_id ? ('wc' . $wc_order_id) : 'api';
        $username = !empty($body['username'])
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $body['username'])
            : base64_encode($order_code) . '_' . $suffix . '_' . time();
        if ($username === '') {
            usk_api_response(400, array('ok' => false, 'error' => 'invalid_username'));
        }

        $created = USK_Service::create_on_panel($panel, $volume_gb, $duration_days, $username);
        if (empty($created['ok'])) {
            usk_api_response(500, array(
                'ok' => false,
                'error' => $created['error'] ?? 'panel_create_failed',
            ));
        }

        $link = $created['links'] ?: ($created['subscription'] ?? '');
        $order = USK_ProtocolProvisioner::save_external_panel_order(
            $panel,
            $username,
            $volume_gb,
            $duration_days,
            $link,
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
            'protocol' => 'xray',
            'panel_type' => $panel['type'],
            'panel_name' => $panel['name'],
            'panel_code' => $panel['code'],
            'service_code' => $order['code'],
            'subscription_url' => $created['subscription'] ?? $link,
            'config' => $created['config'] ?? '',
            'config_links' => $created['links'] ?? $link,
            'volume_gb' => $volume_gb,
            'duration_days' => $duration_days,
        ));
    }

    if ($protocol === '') {
        usk_api_response(400, array('ok' => false, 'error' => 'protocol_required'));
    }

    if ($volume_gb < 1 || $duration_days < 1) {
        usk_api_response(400, array('ok' => false, 'error' => 'volume_duration_required'));
    }

    $code = (string) rand(111111, 999999);
    $suffix = $wc_order_id ? ('wc' . $wc_order_id) : 'api';
    $username = !empty($body['username'])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '', $body['username'])
        : base64_encode($code) . '_' . $suffix . '_' . time();

    if ($username === '') {
        usk_api_response(400, array('ok' => false, 'error' => 'invalid_username'));
    }

    $ovpnProto = null;
    if ($protocol === 'openvpn') {
        if (isset($body['openvpn_proto'])) {
            $ovpnProto = strtolower((string) $body['openvpn_proto']);
            if (!in_array($ovpnProto, array('udp', 'tcp'), true)) {
                $ovpnProto = 'tcp';
            }
        } else {
            $ovpnProto = 'tcp';
        }
    }

    $wgTransport = null;
    if ($protocol === 'wireguard') {
        if (isset($body['wireguard_transport'])) {
            $wgTransport = strtolower((string) $body['wireguard_transport']);
            if (!in_array($wgTransport, array('udp', 'tcp'), true)) {
                $wgTransport = 'tcp';
            }
        } else {
            $wgTransport = 'tcp';
        }
    }

    $created = USK_ProtocolProvisioner::create($protocol, $username, $volume_gb, $duration_days, array(
        'source' => 'woocommerce',
        'wc_order_id' => $wc_order_id,
        'server_ip' => $server_ip,
        'port' => isset($body['port']) ? (int) $body['port'] : null,
        'vless_port' => isset($body['vless_port']) ? (int) $body['vless_port'] : null,
        'vmess_port' => isset($body['vmess_port']) ? (int) $body['vmess_port'] : null,
        'openvpn_proto' => $ovpnProto,
        'wireguard_transport' => $wgTransport,
        'client_dns' => preg_replace('/[^0-9a-zA-Z.,;:\- _]/', '', trim((string) ($body['client_dns'] ?? ''))),
        'customer_email' => $customer_email,
        'usage_id' => $usage_id,
        'max_connections' => $max_connections,
        'node_id' => preg_replace('/[^a-z0-9]/', '', (string) ($body['node_id'] ?? '')),
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
        $wc_order_id,
        array(
            'max_connections' => $max_connections,
            'customer_email' => $created['customer_email'] ?? $customer_email,
            'usage_id' => $created['usage_id'] ?? $usage_id,
            'xray_email' => $created['xray_email'] ?? '',
            'email' => $created['xray_email'] ?? ($created['customer_email'] ?? $customer_email),
        )
    );

    if (empty($order['ok'])) {
        usk_api_response(500, array(
            'ok' => false,
            'error' => $order['error'] ?? 'order_save_failed',
            'provisioned' => true,
        ));
    }

    $raw = $created['raw'] ?? array();
    $portalUrl = USK_ProtocolProvisioner::finalize_order_link(
        $protocol,
        $raw,
        $order['code'],
        ''
    );
    $fileDownloadUrl = USK_ProtocolProvisioner::config_file_download_url($order['code'], $raw);
    if ($portalUrl !== '') {
        global $sql;
        $dl_esc = $sql->real_escape_string($portalUrl);
        $code_esc = $sql->real_escape_string($order['code']);
        $sql->query("UPDATE `orders` SET `link`='$dl_esc' WHERE `code`='$code_esc'");
    }

    $subUrl = $created['subscription'];
    if ($portalUrl !== '') {
        $subUrl = $portalUrl;
    } elseif ($protocol === 'amnezia') {
        $vpnUri = trim((string) ($created['vpn_uri'] ?? ''));
        if ($vpnUri !== '') {
            $subUrl = $vpnUri;
        }
    } elseif ($protocol === 'xray') {
        $vless = trim((string) ($raw['vless'] ?? ($created['subscription'] ?? '')));
        if ($vless !== '') {
            $subUrl = $vless;
        }
    } elseif ($fileDownloadUrl !== '') {
        $subUrl = $fileDownloadUrl;
    }

    $dlFilename = $raw['ovpn_filename'] ?? ($username . '.ovpn');
    if ($protocol === 'amnezia') {
        $dlFilename = $raw['conf_filename'] ?? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.conf');
    } elseif ($protocol === 'xray') {
        $dlFilename = $raw['json_filename'] ?? (preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '.json');
    }

    usk_api_response(200, array(
        'ok' => true,
        'username' => $username,
        'protocol' => $protocol,
        'service_code' => $order['code'],
        'subscription_url' => $subUrl,
        'portal_url' => $portalUrl,
        'config' => $created['config'],
        'config_links' => $created['links'],
        'download_url' => $fileDownloadUrl,
        'max_connections' => $max_connections,
        'ovpn_filename' => $dlFilename,
        'json_filename' => $raw['json_filename'] ?? '',
        'conf_filename' => $raw['conf_filename'] ?? '',
        'client_dns' => $raw['client_dns'] ?? '',
        'customer_email' => $created['customer_email'] ?? $customer_email,
        'usage_id' => $created['usage_id'] ?? $usage_id,
        'xray_email' => $created['xray_email'] ?? '',
        'connect_host' => USK_ConnectHost::resolve($server_ip !== '' ? $server_ip : null) ?: USK_ConnectHost::detect_ip(),
        'vless' => $raw['vless'] ?? '',
        'transport' => $raw['transport'] ?? '',
        'openvpn_proto' => $raw['proto'] ?? $ovpnProto,
        'wireguard_transport' => $raw['wireguard_transport'] ?? $wgTransport,
        'tcp_client_cmd' => $raw['tcp_client_cmd'] ?? '',
        'volume_gb' => $volume_gb,
        'duration_days' => $duration_days,
        'plan_code' => $plan_code !== '' ? $plan_code : null,
        'qr_png' => $created['qr_png'] ?? '',
        'vpn_uri' => $created['vpn_uri'] ?? '',
        'wg_conf' => $created['wg_conf'] ?? '',
        'expires_at' => $created['expires_at'] ?? null,
    ));
}

if ($action === 'verify-renew') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        usk_api_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
    }

    require_once dirname(__DIR__) . '/admin/lib/customer-renewal.php';

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        usk_api_response(400, array('ok' => false, 'error' => 'invalid_json'));
    }

    $serviceCode = preg_replace('/[^0-9]/', '', (string) ($body['service_code'] ?? ''));
    $planCode = preg_replace('/[^0-9]/', '', (string) ($body['plan_code'] ?? ''));
    $protocol = USK_ProtocolManager::sanitize_key($body['protocol'] ?? '');
    $signature = (string) ($body['renew_sig'] ?? '');

    $target = USK_CustomerRenewal::resolve_renew_target($serviceCode);
    if (empty($target['ok'])) {
        usk_api_response(404, array('ok' => false, 'error' => $target['error'] ?? 'not_found'));
    }

    $client = $target['client'] ?? array();
    $token = (string) ($client['download_token'] ?? ($client['meta']['download_token'] ?? ''));
    $serviceProtocol = USK_ProtocolManager::sanitize_key((string) ($target['protocol'] ?? ''));

    if ($protocol === '' || $serviceProtocol === '' || $protocol !== $serviceProtocol) {
        usk_api_response(403, array('ok' => false, 'error' => 'protocol_mismatch'));
    }

    if (!USK_CustomerRenewal::verify_signature($serviceCode, $planCode, $protocol, $token, $signature)) {
        usk_api_response(403, array('ok' => false, 'error' => 'invalid_signature'));
    }

    if (USK_License::get_plan_by_code($planCode) === null) {
        usk_api_response(403, array('ok' => false, 'error' => 'plan_inactive_or_missing'));
    }

    usk_api_response(200, array(
        'ok' => true,
        'service_code' => $serviceCode,
        'protocol' => $protocol,
        'plan_code' => $planCode,
    ));
}

if ($action === 'extend-service') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        usk_api_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
    }

    require_once dirname(__DIR__) . '/admin/lib/customer-renewal.php';

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        usk_api_response(400, array('ok' => false, 'error' => 'invalid_json'));
    }

    $serviceCode = preg_replace('/[^0-9]/', '', (string) ($body['service_code'] ?? ''));
    $planCode = preg_replace('/[^0-9]/', '', (string) ($body['plan_code'] ?? ''));
    $protocol = USK_ProtocolManager::sanitize_key($body['protocol'] ?? '');
    $signature = (string) ($body['renew_sig'] ?? '');
    $wcOrderId = isset($body['wc_order_id']) ? (int) $body['wc_order_id'] : null;

    $result = USK_CustomerRenewal::extend_service($serviceCode, $planCode, $protocol, $signature, $wcOrderId);
    if (empty($result['ok'])) {
        usk_api_response(400, array(
            'ok' => false,
            'error' => $result['error'] ?? 'extend_failed',
        ));
    }

    usk_api_response(200, $result);
}

usk_api_response(404, array('ok' => false, 'error' => 'unknown_action'));
