<?php

define('USK_ROOT', __DIR__);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin/lib/panel-access.php';
USK_PanelAccess::enforce_request_host();
require_once __DIR__ . '/admin/lib/protocols/limits.php';
require_once __DIR__ . '/admin/lib/service-stats.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'), JSON_UNESCAPED_UNICODE);
    exit;
}

$code = (string) ($_GET['code'] ?? '');
$token = (string) ($_GET['t'] ?? '');

$result = USK_ServiceStats::for_portal($code, $token);
if (empty($result['ok'])) {
    http_response_code($result['error'] === 'invalid_request' ? 400 : 403);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
