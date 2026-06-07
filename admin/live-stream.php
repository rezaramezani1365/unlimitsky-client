<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';

USK_Admin_Auth::boot();
if (!USK_Admin_Auth::check()) {
    http_response_code(401);
    exit;
}

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'error' => 'sse_disabled_use_polling'));
