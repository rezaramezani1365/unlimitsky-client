<?php

define('USK_ROOT', __DIR__);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin/lib/config-download.php';

$code = preg_replace('/[^0-9]/', '', (string) ($_GET['code'] ?? ''));
$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));

if ($code === '' || $token === '') {
    http_response_code(400);
    echo 'Invalid request';
    exit;
}

if (usk_serve_openvpn_download($code, $token)) {
    exit;
}

if (usk_serve_amnezia_download($code, $token)) {
    exit;
}

if (usk_serve_xray_download($code, $token)) {
    exit;
}

http_response_code(404);
echo 'Config not found';
exit;
