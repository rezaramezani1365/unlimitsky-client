<?php
$GLOBALS['page_title'] = __('nav_protocols');
$GLOBALS['active_nav'] = 'protocols';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_protocol'])) {
    $proto = USK_ProtocolManager::sanitize_key($_POST['install_protocol'] ?? '');
    if ($proto === '') {
        usk_flash(__('protocol_failed') . ': invalid_protocol', 'error');
        header('Location: ' . usk_admin_url('protocols'));
        exit;
    }
    $wasInstalled = !empty(USK_ProtocolManager::get_status($proto)['installed']);
    $ports = USK_ProtocolManager::parse_ports($proto, $_POST);
    $res = USK_ProtocolManager::install($proto, $ports);
    if (!empty($res['async']) && !empty($res['ok'])) {
        usk_flash(__('protocol_install_started'), 'info');
    } elseif (!empty($res['ok'])) {
        if (!empty($res['warn'])) {
            $logFile = 'data/protocols/' . $proto . '-last.log';
            $tail = isset($res['log']) ? trim(substr((string) $res['log'], -350)) : '';
            $msg = sprintf(__('protocol_reinstalled_warn'), $logFile);
            if ($tail !== '') {
                $msg .= ' — ' . $tail;
            }
            usk_flash($msg, 'warning');
        } else {
            usk_flash($wasInstalled ? __('protocol_reinstalled') : __('protocol_installed'));
        }
    } elseif (!empty($res['async']) && ($res['msg'] ?? '') === 'install_already_running') {
        usk_flash(__('protocol_install_running'), 'warning');
    } else {
        usk_flash(__('protocol_failed') . (isset($res['log']) ? ': ' . substr($res['log'], -400) : ''), 'error');
    }
    header('Location: ' . usk_admin_url('protocols'));
    exit;
}

USK_ProtocolManager::poll_all_install_jobs();
USK_ProtocolManager::refresh_all_status();

$protocols = USK_ProtocolManager::list();
$anyInstallRunning = false;
foreach (array_keys($protocols) as $_pk) {
    $s = USK_ProtocolManager::get_status($_pk);
    if (($s['install_state'] ?? '') === 'running' || USK_ProtocolManager::is_install_job_running($_pk)) {
        $anyInstallRunning = true;
        break;
    }
}
unset($_pk, $s);
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-server"></i> <?= __('protocols_intro') ?>
</div>

<div class="row g-3">
<?php foreach ($protocols as $key => $meta) :
    $st = USK_ProtocolManager::get_status($key);
    $installed = !empty($st['installed']);
    $installing = !$installed && (
        ($st['install_state'] ?? '') === 'running'
        || USK_ProtocolManager::is_install_job_running($key)
    );
    $portFields = $meta['port_fields'] ?? array();
?>
    <div class="col-md-6 col-lg-4"<?= $installing ? ' data-install-running="1"' : '' ?>>
        <div class="usk-card h-100">
            <div class="usk-card-header">
                <i class="fa-solid <?= $meta['icon'] ?>"></i> <?= usk_esc($meta['name']) ?>
            </div>
            <div class="p-3">
                <p class="text-muted small mb-2"><?= __('protocol_port') ?>: <code class="usk-code"><?= (int) $meta['port'] ?></code></p>
                <?php if ($installed) : ?>
                    <?php if ($key === 'xray' && !empty($st['vless_port'])) : ?>
                    <p class="text-muted small mb-2">
                        VLESS Reality: <code class="usk-code">TCP <?= (int) $st['vless_port'] ?></code>
                        <?php if (!empty($st['reality_sni'])) : ?>
                        · SNI: <code class="usk-code"><?= usk_esc($st['reality_sni']) ?></code>
                        <?php endif; ?>
                    </p>
                    <?php elseif ($key === 'openvpn' && (!empty($st['udp_port']) || !empty($st['tcp_port']))) : ?>
                    <p class="text-muted small mb-2">
                        UDP: <code class="usk-code"><?= (int) ($st['udp_port'] ?? 1194) ?></code>
                        · TCP: <code class="usk-code"><?= (int) ($st['tcp_port'] ?? 443) ?></code>
                    </p>
                    <?php elseif ($key === 'wireguard' && (!empty($st['port']) || !empty($st['tcp_port']))) : ?>
                    <p class="text-muted small mb-2">
                        UDP: <code class="usk-code"><?= (int) ($st['port'] ?? 51820) ?></code>
                        <?php if (!empty($st['tcp_port']) && (int) $st['tcp_port'] > 0) : ?>
                        · TCP bridge: <code class="usk-code"><?= (int) $st['tcp_port'] ?></code>
                        <?php if (isset($st['tcp_bridge_active']) && !$st['tcp_bridge_active']) : ?>
                        <span class="text-danger">(<?= __('protocol_tcp_bridge_inactive') ?>)</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php elseif (!empty($st['port'])) : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_active_port') ?>: <code class="usk-code"><?= (int) $st['port'] ?></code></p>
                    <?php endif; ?>
                    <?php if (!empty($meta['fixed_ports'])) : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_fixed_ports') ?>: <?= usk_esc($meta['fixed_ports']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($meta['note_key'])) : ?>
                    <p class="small text-danger mb-2"><i class="fa-solid fa-circle-info"></i> <?= __($meta['note_key']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($st['last_install_warning'])) : ?>
                    <p class="small text-warning mb-2" style="white-space:pre-wrap;direction:ltr;text-align:left;"><i class="fa-solid fa-triangle-exclamation"></i> <?= usk_esc($st['last_install_warning']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($st['firewall_note'])) : ?>
                    <p class="small text-warning mb-2"><i class="fa-solid fa-triangle-exclamation"></i> <?= usk_esc($st['firewall_note']) ?></p>
                    <?php endif; ?>
                <?php elseif (!empty($meta['fixed_ports'])) : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_fixed_ports') ?>: <?= usk_esc($meta['fixed_ports']) ?></p>
                <?php endif; ?>
                <?php if (!$installed && !empty($meta['note_key'])) : ?>
                    <p class="small text-danger mb-2"><i class="fa-solid fa-circle-info"></i> <?= __($meta['note_key']) ?></p>
                <?php endif; ?>
                <p class="mb-3">
                    <?php if ($installed) : ?>
                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> <?= __('protocol_active') ?></span>
                    <?php elseif ($installing) : ?>
                        <span class="badge badge-warning"><i class="fa-solid fa-spinner fa-spin"></i> <?= __('protocol_installing') ?></span>
                        <p class="text-muted small mt-2 mb-0"><?= __('protocol_installing_hint') ?></p>
                    <?php else : ?>
                        <span class="badge badge-danger"><?= __('protocol_not_installed') ?></span>
                    <?php endif; ?>
                </p>
                <form method="post">
                    <input type="hidden" name="install_protocol" value="<?= usk_esc($key) ?>">
                    <?php foreach ($portFields as $field) :
                        $fkey = $field['key'];
                        $fval = USK_ProtocolManager::effective_port($st[$fkey] ?? null, $field['default']);
                    ?>
                    <div class="form-group mb-2">
                        <label class="small mb-1"><?= usk_esc($field['label']) ?></label>
                        <input type="number" class="form-control form-control-sm" name="port_<?= usk_esc($fkey) ?>"
                               min="1" max="65535" value="<?= $fval ?>" required<?= $installing ? ' disabled' : '' ?>>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($key === 'wireguard') : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_wg_tcp_port_hint') ?></p>
                    <?php endif; ?>
                    <?php if ($installing) : ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" disabled>
                        <i class="fa-solid fa-spinner fa-spin"></i> <?= __('protocol_installing') ?>
                    </button>
                    <?php elseif (!$installed) : ?>
                    <button type="submit" class="btn btn-usk-primary btn-sm w-100 mt-2" onclick="return confirm('<?= __('protocol_install_confirm') ?>')">
                        <i class="fa-solid fa-download"></i> <?= __('protocol_install') ?>
                    </button>
                    <?php else : ?>
                    <?php if (!empty($st['updated_at'])) : ?>
                    <p class="text-muted small mb-2 mt-2"><?= __('protocol_last_install') ?>: <?= usk_esc(date('Y-m-d H:i', strtotime($st['updated_at']))) ?></p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100 mt-2" onclick="return confirm('<?= __('protocol_reinstall_confirm') ?>')">
                        <i class="fa-solid fa-rotate"></i> <?= __('protocol_reinstall') ?>
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="usk-card mt-4">
    <div class="usk-card-header"><?= __('limits_cron_title') ?></div>
    <div class="p-3 text-muted small">
        <p><?= __('limits_cron_intro') ?></p>
        <p class="alert alert-usk-info small py-2 px-3 mb-2"><?= __('limits_cron_auto_note') ?></p>
        <p class="alert alert-usk-info small py-2 px-3 mb-2"><?= __('connections_cron_auto_note') ?></p>
        <ul>
            <li><?= __('limits_cron_expiry') ?></li>
            <li><?= __('limits_cron_volume') ?></li>
            <li><?= __('limits_cron_connections') ?></li>
        </ul>
        <p class="text-muted small"><?= __('limits_cron_manual_note') ?></p>
        <pre class="usk-code p-2" style="white-space:pre-wrap;direction:ltr;text-align:left">*/5 * * * * flock -n … php <?= usk_esc(USK_ROOT) ?>/cron/native-limits.php (هر ۵ دقیقه)</pre>
        <pre class="usk-code p-2 mt-2" style="white-space:pre-wrap;direction:ltr;text-align:left">* * * * * php <?= usk_esc(USK_ROOT) ?>/cron/enforce-connections.php (هر ۳۰ ثانیه + Fail2ban IP limit)</pre>
        <pre class="usk-code p-2 mt-2" style="white-space:pre-wrap;direction:ltr;text-align:left">www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/disable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/enable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/remove-user-*.sh *</pre>
        <?php
        $last = USK_ProtocolLimits::get_last_run();
        if ($last) :
        ?>
        <p class="mt-3 mb-0"><strong><?= __('limits_last_run') ?>:</strong> <?= usk_esc($last['ran_at'] ?? '—') ?>
        — <?= __('limits_checked') ?>: <?= (int) ($last['checked'] ?? 0) ?>,
        <?= __('limits_marked') ?>: <?= (int) ($last['disabled'] ?? ($last['marked'] ?? 0)) ?></p>
        <?php endif; ?>
        <?php
        require_once USK_ROOT . '/admin/lib/protocols/connections.php';
        $connLast = USK_ProtocolConnections::get_last_run();
        if ($connLast) :
        ?>
        <p class="mt-2 mb-0"><strong><?= __('connections_last_run') ?>:</strong> <?= usk_esc($connLast['ran_at'] ?? '—') ?>
        — <?= __('connections_trimmed') ?>: <?= (int) ($connLast['trimmed'] ?? 0) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="usk-card mt-4">
    <div class="usk-card-header"><?= __('protocol_sudo_title') ?></div>
    <div class="p-3 text-muted small">
        <p><?= __('protocol_sudo_note') ?></p>
        <pre class="usk-code p-2" style="white-space:pre-wrap;direction:ltr;text-align:left">www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/install-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/run-protocol-install.sh *
www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/probe-protocol.sh *
www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/add-user-*.sh *</pre>
    </div>
</div>
<?php if ($anyInstallRunning) : ?>
<script>
(function () {
    setTimeout(function () { window.location.reload(); }, 15000);
})();
</script>
<?php endif; ?>
