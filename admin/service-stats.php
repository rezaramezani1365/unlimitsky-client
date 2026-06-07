<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/service-stats.php';

USK_Admin_Auth::boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!USK_Admin_Auth::check()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'), JSON_UNESCAPED_UNICODE);
    exit;
}

$codesRaw = trim((string) ($_GET['codes'] ?? ''));
if ($codesRaw !== '') {
    $codes = preg_split('/[\s,]+/', $codesRaw, -1, PREG_SPLIT_NO_EMPTY);
    echo json_encode(USK_ServiceStats::for_codes(is_array($codes) ? $codes : array()), JSON_UNESCAPED_UNICODE);
    exit;
}

$code = preg_replace('/[^0-9]/', '', (string) ($_GET['code'] ?? ''));
if ($code !== '') {
    echo json_encode(USK_ServiceStats::for_codes(array($code)), JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(array('ok' => false, 'error' => 'codes_required'), JSON_UNESCAPED_UNICODE);
