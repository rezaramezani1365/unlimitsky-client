<?php

define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/panel-access.php';
require_once USK_ROOT . '/admin/lib/license.php';
require_once USK_ROOT . '/admin/lib/nodes.php';
require_once USK_ROOT . '/admin/lib/node-relay.php';

header('Content-Type: application/json; charset=utf-8');

function usk_node_register_response($code, $payload)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    usk_node_register_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
}

$secretHeader = trim((string) ($_SERVER['HTTP_X_USK_REGISTER_SECRET'] ?? ''));
$expected = USK_Nodes::register_secret();
if ($secretHeader === '' || !hash_equals($expected, $secretHeader)) {
    usk_node_register_response(403, array('ok' => false, 'error' => 'invalid_register_secret'));
}

if (!USK_Nodes::can_use_nodes()) {
    usk_node_register_response(403, array('ok' => false, 'error' => 'nodes_pro_required'));
}

$raw = file_get_contents('php://input');
$body = json_decode($raw !== false ? $raw : '', true);
if (!is_array($body)) {
    usk_node_register_response(400, array('ok' => false, 'error' => 'invalid_json'));
}

$result = USK_Nodes::register(array(
    'name' => $body['name'] ?? '',
    'ssh_host' => $body['ssh_host'] ?? ($body['host'] ?? ''),
    'ssh_port' => $body['ssh_port'] ?? 22,
    'ssh_user' => $body['ssh_user'] ?? '',
    'ssh_password' => $body['ssh_password'] ?? '',
    'connect_host' => $body['connect_host'] ?? '',
    'protocols' => $body['protocols'] ?? array(),
));

if (empty($result['ok'])) {
    usk_node_register_response(400, $result);
}

$nodeId = (string) ($result['node_id'] ?? '');
if ($nodeId !== '') {
    $node = USK_Nodes::get($nodeId);
    if ($node) {
        USK_NodeRelay::init_node($node);
    }
}

usk_node_register_response(200, $result);
