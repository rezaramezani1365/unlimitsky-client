<?php

/**
 * Deprecated — kept so old systemd units exit cleanly after update.
 * Do NOT run collect in a loop; use cron/native-limits.php instead.
 */
if (php_sapi_name() === 'cli') {
    fwrite(STDERR, "live-stats-worker is disabled; use cron/native-limits.php\n");
    exit(0);
}
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'error' => 'disabled'));
