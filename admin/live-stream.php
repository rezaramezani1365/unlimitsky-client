<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/live-stats.php';
require_once __DIR__ . '/lib/service-stats.php';

USK_Admin_Auth::boot();
if (!USK_Admin_Auth::check()) {
    http_response_code(401);
    exit;
}

@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$codesRaw = trim((string) ($_GET['codes'] ?? ''));
$codes = array();
if ($codesRaw !== '') {
    $codes = preg_split('/[\s,]+/', $codesRaw, -1, PREG_SPLIT_NO_EMPTY);
    $codes = is_array($codes) ? $codes : array();
}

$lastHash = '';
$iterations = 0;
$maxIterations = 900;

while ($iterations < $maxIterations && !connection_aborted()) {
    $payload = USK_ServiceStats::for_codes($codes);
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
