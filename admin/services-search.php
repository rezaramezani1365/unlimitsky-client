<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/services-search.php';

USK_Admin_Auth::boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!USK_Admin_Auth::check()) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'unauthorized'));
    exit;
}

$q = (string) ($_GET['q'] ?? '');
$result = USK_ServicesSearch::search($q);

if (($result['error'] ?? '') === 'query_too_short') {
    unset($result['error']);
    $result['hint'] = __('services_search_min_chars');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
