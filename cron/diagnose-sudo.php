<?php
/**
 * Sudo / VPN script diagnostic — run on server:
 *   php /var/www/unlimitsky/cron/diagnose-sudo.php
 *   sudo -u www-data php /var/www/unlimitsky/cron/diagnose-sudo.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/admin/lib/init.php';
require_once USK_ROOT . '/admin/lib/sudo-runner.php';

$out = array(
    'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
        : get_current_user(),
    'usk_root' => USK_ROOT,
    'wrapper' => USK_SudoRunner::wrapper_path(),
    'wrapper_exists' => is_file(USK_SudoRunner::wrapper_path()),
    'wrapper_executable' => is_executable(USK_SudoRunner::wrapper_path()),
    'sudoers_file' => '/etc/sudoers.d/unlimitsky',
    'sudoers_exists' => is_file('/etc/sudoers.d/unlimitsky'),
    'sudoers_readable' => is_readable('/etc/sudoers.d/unlimitsky'),
    'sudoers_has_wrapper' => false,
    'sudoers_has_add_user' => false,
    'shell_exec_enabled' => !in_array(
        'shell_exec',
        array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))),
        true
    ),
    'probe_ok' => false,
    'probe_output' => '',
    'wg0_up' => false,
    'wg0_show' => '',
    'wg_create_test' => '',
    'note_sudoers_readable' => 'sudoers_readable=false as www-data is normal; run as root to verify /etc/sudoers.d/unlimitsky',
);

if ($out['sudoers_readable']) {
    $body = (string) file_get_contents('/etc/sudoers.d/unlimitsky');
    $out['sudoers_has_wrapper'] = strpos($body, 'usk-run-root.sh') !== false;
    $out['sudoers_has_add_user'] = strpos($body, 'add-user-') !== false;
    $out['sudoers_preview'] = implode("\n", array_slice(explode("\n", $body), 0, 8));
}

if ($out['shell_exec_enabled']) {
    $wgShow = trim((string) shell_exec('wg show wg0 2>&1'));
    $out['wg0_show'] = substr($wgShow, 0, 200);
    $out['wg0_up'] = $wgShow !== '' && stripos($wgShow, 'No such device') === false && stripos($wgShow, 'permission denied') === false;
}

if ($out['shell_exec_enabled'] && $out['wrapper_exists']) {
    $cmd = USK_SudoRunner::cmd_rel('bin/probe-protocol.sh', array('wireguard', USK_ROOT)) . ' 2>&1';
    $probe = shell_exec($cmd);
    $out['probe_output'] = trim((string) $probe);
    $out['probe_ok'] = strpos($out['probe_output'], 'USK_OK') !== false;
}

if ($out['shell_exec_enabled'] && $out['wrapper_exists'] && $out['probe_ok']) {
    $cmd = USK_SudoRunner::cmd_rel('bin/add-user-wireguard.sh', array(
        '_sudo_diag_' . time(),
        '0',
        '0',
        'udp',
        '',
        '',
    )) . ' 2>&1';
    $wg = shell_exec($cmd);
    $out['wg_create_test'] = trim(substr((string) $wg, 0, 500));
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
