<?php

define('USK_CRON', true);
define('USK_ROOT', dirname(__DIR__));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/admin/lib/protocols/manager.php';
require_once USK_ROOT . '/admin/lib/protocols/limits.php';
require_once USK_ROOT . '/admin/lib/protocols/usage.php';

$out = array('ok' => true, 'usk_root' => USK_ROOT);

$ref = new ReflectionClass('USK_ProtocolUsage');
$batchMethod = $ref->getMethod('batch_usage_maps');
$batchMethod->setAccessible(true);
$bytesMethod = $ref->getMethod('bytes_from_maps');
$bytesMethod->setAccessible(true);
$candidatesMethod = $ref->getMethod('usage_name_candidates');
$candidatesMethod->setAccessible(true);

$maps = $batchMethod->invoke(null);

foreach (array('xray', 'wireguard', 'openvpn') as $protocol) {
    $file = USK_ROOT . '/data/clients/' . $protocol . '.json';
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded) && $raw !== '' && defined('JSON_INVALID_UTF8_IGNORE')) {
        $decoded = json_decode($raw, true, 512, JSON_INVALID_UTF8_IGNORE);
    }
    $clients = USK_ProtocolLimits::load_protocol_clients($protocol);
    $map = $maps[$protocol] ?? array();

    $out['files'][$protocol] = array(
        'path' => $file,
        'exists' => is_file($file),
        'bytes' => strlen($raw),
        'json_ok' => is_array($decoded),
        'raw_keys_sample' => is_array($decoded) ? array_slice(array_keys($decoded), 0, 5) : array(),
        'loaded_count' => count($clients),
        'loaded_keys_sample' => array_slice(array_keys($clients), 0, 5),
        'map_count' => count($map),
        'map_keys_sample' => array_slice(array_keys($map), 0, 5),
        'positive_map_count' => count(array_filter($map, function ($v) {
            return (int) $v > 0;
        })),
        'positive_map_keys_sample' => array_slice(array_keys(array_filter($map, function ($v) {
            return (int) $v > 0;
        })), 0, 10),
    );

    $samples = array();
    $i = 0;
    foreach ($clients as $username => $rec) {
        if (!is_array($rec) || $i >= 5) {
            break;
        }
        $bytes = $bytesMethod->invoke(null, $protocol, $username, $rec, $maps);
        $samples[] = array(
            'foreach_key' => (string) $username,
            'rec_username' => (string) ($rec['username'] ?? ''),
            'uuid' => (string) ($rec['uuid'] ?? ''),
            'order_code' => (string) ($rec['order_code'] ?? ''),
            'candidates' => $candidatesMethod->invoke(null, $username, $rec),
            'bytes_matched' => $bytes,
        );
        $i++;
    }
    $out['match_samples'][$protocol] = $samples;
}

$out['collect_meta'] = USK_ProtocolUsage::last_collect_meta();

if (is_readable('/var/lib/unlimitsky/xray/access.log')) {
    $cmd = "awk '/email:/ && /accepted/ {for(i=1;i<=NF;i++) if(\$i==\"email:\" && (i+1)<=NF) print \$(i+1)}' /var/lib/unlimitsky/xray/access.log 2>/dev/null | sort | uniq -c | sort -nr | head -10";
    $rawAccess = trim((string) @shell_exec($cmd));
    $access = array();
    foreach (explode("\n", $rawAccess) as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
            continue;
        }
        $access[] = array('hits' => (int) $m[1], 'email' => $m[2]);
    }
    $out['xray_access_email_top'] = $access;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
