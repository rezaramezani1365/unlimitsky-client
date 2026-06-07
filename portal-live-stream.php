<?php

define('USK_ROOT', __DIR__);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin/lib/panel-access.php';
USK_PanelAccess::enforce_request_host();
require_once __DIR__ . '/admin/lib/live-stats.php';
require_once __DIR__ . '/admin/lib/service-stats.php';

@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

$code = (string) ($_GET['code'] ?? '');
$token = (string) ($_GET['t'] ?? '');

require_once __DIR__ . '/admin/lib/customer-portal.php';
$codeClean = preg_replace('/[^0-9]/', '', $code);
$tokenClean = preg_replace('/[^a-f0-9]/', '', strtolower($token));
if ($codeClean === '' || $tokenClean === '') {
    http_response_code(400);
    exit;
}
$view = USK_CustomerPortal::load($codeClean, $tokenClean);
if (empty($view['ok'])) {
    http_response_code(403);
    exit;
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$lastHash = '';
$iterations = 0;
while ($iterations < 900 && !connection_aborted()) {
    $payload = USK_ServiceStats::for_portal($codeClean, $tokenClean);
    $payload['live'] = array(
        'cache_age_sec' => USK_LiveStats::cache_age_sec(),
        'cache_fresh' => USK_LiveStats::is_fresh(),
    );
    $hash = md5(json_encode($payload));
    if ($hash !== $lastHash) {
        echo 'event: stats' . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        $lastHash = $hash;
        @ob_flush();
        flush();
    } else {
        echo ": ping\n\n";
        @ob_flush();
        flush();
    }
    $iterations++;
    sleep(2);
}
