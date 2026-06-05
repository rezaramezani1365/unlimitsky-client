<?php
$GLOBALS['page_title'] = __('nav_protocols');
$GLOBALS['active_nav'] = 'protocols';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_protocol'])) {
    $proto = preg_replace('/[^a-z]/', '', $_POST['install_protocol']);
    $res = USK_ProtocolManager::install($proto);
    if (!empty($res['ok'])) {
        usk_flash(__('protocol_installed'));
    } else {
        usk_flash(__('protocol_failed') . (isset($res['log']) ? ': ' . substr($res['log'], -200) : ''), 'error');
    }
    header('Location: ' . usk_admin_url('protocols'));
    exit;
}

$protocols = USK_ProtocolManager::list();
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-server"></i> <?= __('protocols_intro') ?>
</div>

<div class="row g-3">
<?php foreach ($protocols as $key => $meta) :
    $st = USK_ProtocolManager::get_status($key);
    $installed = !empty($st['installed']);
?>
    <div class="col-md-6 col-lg-4">
        <div class="usk-card h-100">
            <div class="usk-card-header">
                <i class="fa-solid <?= $meta['icon'] ?>"></i> <?= usk_esc($meta['name']) ?>
            </div>
            <div class="p-3">
                <p class="text-muted small mb-2"><?= __('protocol_port') ?>: <code class="usk-code"><?= (int) $meta['port'] ?></code></p>
                <p class="mb-3">
                    <?php if ($installed) : ?>
                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> <?= __('protocol_active') ?></span>
                    <?php else : ?>
                        <span class="badge badge-danger"><?= __('protocol_not_installed') ?></span>
                    <?php endif; ?>
                </p>
                <?php if (!$installed) : ?>
                <form method="post">
                    <input type="hidden" name="install_protocol" value="<?= usk_esc($key) ?>">
                    <button type="submit" class="btn btn-usk-primary btn-sm w-100" onclick="return confirm('<?= __('protocol_install_confirm') ?>')">
                        <i class="fa-solid fa-download"></i> <?= __('protocol_install') ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="usk-card mt-4">
    <div class="usk-card-header"><?= __('limits_cron_title') ?></div>
    <div class="p-3 text-muted small">
        <p><?= __('limits_cron_intro') ?></p>
        <ul>
            <li><?= __('limits_cron_expiry') ?></li>
            <li><?= __('limits_cron_volume') ?></li>
        </ul>
        <p class="text-muted small"><?= __('limits_cron_manual_note') ?></p>
        <pre class="usk-code p-2" style="white-space:pre-wrap;direction:ltr;text-align:left">*/15 * * * * php <?= usk_esc(USK_ROOT) ?>/cron/native-limits.php >> /var/log/unlimitsky-limits.log 2>&1</pre>
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
    </div>
</div>

<div class="usk-card mt-4">
    <div class="usk-card-header"><?= __('protocol_sudo_title') ?></div>
    <div class="p-3 text-muted small">
        <p><?= __('protocol_sudo_note') ?></p>
        <pre class="usk-code p-2" style="white-space:pre-wrap;direction:ltr;text-align:left">www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/install-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash <?= usk_esc(USK_ROOT) ?>/bin/add-user-*.sh *</pre>
    </div>
</div>
