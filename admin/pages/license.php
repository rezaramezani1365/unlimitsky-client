<?php
$GLOBALS['page_title'] = __('nav_license');
$GLOBALS['active_nav'] = 'license';

USK_License::validate_cached();
$info = USK_License::get();
$is_pro = USK_License::is_pro();
$vendorConfigured = USK_License::vendor_configured();
$vendorSource = USK_License::vendor_config_source();
$presence = USK_License::last_presence_sync();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['action']) ? $_POST['action'] : '';
    if ($act === 'connect_vendor' || $act === 'save_vendor_token') {
        $token = isset($_POST['license_api_token']) ? trim((string) $_POST['license_api_token']) : '';
        if ($token === '' && $act === 'connect_vendor' && USK_License::vendor_configured()) {
            $res = USK_License::sync_presence_with_vendor(true);
            if (!empty($res['ok'])) {
                usk_flash(__('license_vendor_sync_ok'));
            } else {
                $err = isset($res['error']) ? $res['error'] : 'error';
                usk_flash(__('license_vendor_sync_failed') . ': ' . usk_license_error_message($err), 'error');
            }
            header('Location: ' . usk_admin_url('license'));
            exit;
        }
        if ($token === '') {
            usk_flash(__('license_vendor_token_required'), 'error');
            header('Location: ' . usk_admin_url('license'));
            exit;
        }
        $saved = USK_License::save_vendor_token($token);
        if (empty($saved['ok'])) {
            $err = isset($saved['error']) ? $saved['error'] : 'error';
            usk_flash(__('license_vendor_token_save_failed') . ': ' . usk_license_error_message($err), 'error');
            header('Location: ' . usk_admin_url('license'));
            exit;
        }
        if ($act === 'save_vendor_token') {
            usk_flash(__('license_vendor_token_saved'));
            header('Location: ' . usk_admin_url('license'));
            exit;
        }
        $res = USK_License::sync_presence_with_vendor(true);
        if (!empty($res['ok'])) {
            usk_flash(__('license_vendor_connect_ok'));
        } else {
            $err = isset($res['error']) ? $res['error'] : 'error';
            usk_flash(__('license_vendor_connect_saved_sync_failed') . ': ' . usk_license_error_message($err), 'error');
        }
        header('Location: ' . usk_admin_url('license'));
        exit;
    }
    if ($act === 'sync_vendor') {
        $res = USK_License::sync_presence_with_vendor(true);
        if (!empty($res['ok'])) {
            usk_flash(__('license_vendor_sync_ok'));
        } else {
            $err = isset($res['error']) ? $res['error'] : 'error';
            usk_flash(__('license_vendor_sync_failed') . ': ' . usk_license_error_message($err), 'error');
        }
        header('Location: ' . usk_admin_url('license'));
        exit;
    }
    if ($act === 'activate') {
        $key = isset($_POST['license_key']) ? $_POST['license_key'] : '';
        $res = USK_License::activate($key);
        if (!empty($res['ok'])) {
            USK_Migration::clear();
            usk_flash(__('license_activated'));
        } else {
            $err = isset($res['error']) ? $res['error'] : 'error';
            usk_flash(__('license_failed') . ': ' . usk_license_error_message($err), 'error');
        }
        header('Location: ' . usk_admin_url('license'));
        exit;
    }
    if ($act === 'deactivate') {
        USK_License::deactivate();
        usk_flash(__('license_removed'));
        header('Location: ' . usk_admin_url('license'));
        exit;
    }
}
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-crown"></i>
    <?= __('license_intro') ?>
</div>

<?php
$migration = USK_Migration::get_pending();
$licenseHint = USK_Migration::license_key_hint();
if (USK_Migration::needs_license_reactivation()) :
    $fromHost = is_array($migration) ? ($migration['from_hostname'] ?? '') : '';
?>
<div class="alert alert-warning mb-4">
    <h6 class="alert-heading mb-2"><i class="fa-solid fa-server"></i> <?= __('license_migration_title') ?></h6>
    <p class="small mb-2"><?= __('license_migration_intro') ?><?php if ($fromHost !== '') : ?> <?= __('license_migration_from') ?> <code dir="ltr"><?= usk_esc($fromHost) ?></code><?php endif; ?></p>
    <ol class="small mb-2 ps-3">
        <li><?= __('license_migration_step_vendor') ?></li>
        <li><?= __('license_migration_step_activate') ?></li>
    </ol>
    <p class="small mb-0 text-muted"><?= __('license_migration_note') ?></p>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="usk-stat">
            <div class="usk-stat-label"><?= __('license_tier') ?></div>
            <div class="usk-stat-value" style="font-size:1.25rem">
                <?= $is_pro ? '<span class="badge badge-usk">PRO</span>' : '<span class="badge badge-danger">FREE</span>' ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="usk-stat">
            <div class="usk-stat-label"><?= __('license_plans_limit') ?></div>
            <div class="usk-stat-value"><?= USK_License::current_plan_count() ?> / <?= USK_License::max_plans() ?></div>
        </div>
    </div>
</div>

<?php if ($is_pro) : ?>
<div class="usk-card mb-4">
    <div class="usk-card-header"><?= __('license_active') ?></div>
    <div class="p-3">
        <p><strong><?= __('license_key') ?>:</strong> <code class="usk-code"><?= usk_esc($info['license_key'] ?? '') ?></code></p>
        <p><strong><?= __('license_expires') ?>:</strong> <?= usk_esc($info['expires_at'] ?? '—') ?></p>
        <form method="post" onsubmit="return confirm('<?= __('license_deactivate_confirm') ?>')">
            <input type="hidden" name="action" value="deactivate">
            <button type="submit" class="btn btn-outline-usk btn-sm"><?= __('license_deactivate') ?></button>
        </form>
    </div>
</div>
<?php else : ?>
<div class="usk-card mb-4">
    <div class="usk-card-header"><?= __('license_activate') ?></div>
    <div class="p-3">
        <form method="post">
            <input type="hidden" name="action" value="activate">
            <div class="form-group">
                <label><?= __('license_key') ?></label>
                <input class="form-control" name="license_key" required placeholder="USK-XXXXX-XXXXX-XXXXX-XXXXX" dir="ltr" value="<?= usk_esc($licenseHint) ?>">
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('license_activate_btn') ?></button>
        </form>
        <p class="text-muted small mt-3 mb-0"><?= __('license_free_note') ?></p>
    </div>
</div>
<?php endif; ?>

<div class="usk-card mb-4">
    <div class="usk-card-header"><?= __('license_vendor_title') ?></div>
    <div class="p-3">
        <p class="mb-3 small text-muted">
            <?= __('license_vendor_host_label') ?>:
            <code dir="ltr"><?= usk_esc(USK_License::default_vendor_host()) ?></code>
            <span class="text-muted">(<code dir="ltr"><?= usk_esc(USK_License::default_vendor_license_url()) ?></code>)</span>
        </p>
        <?php if ($vendorConfigured) : ?>
        <p class="mb-2 small text-muted"><?= __('license_vendor_configured') ?><?php if ($vendorSource !== '') : ?> <code dir="ltr"><?= usk_esc($vendorSource) ?></code><?php endif; ?></p>
        <?php if (!empty($presence['synced_at'])) : ?>
        <p class="mb-2 small">
            <?= __('license_vendor_last_sync') ?>:
            <span dir="ltr"><?= usk_esc($presence['synced_at']) ?></span>
            <?php if (!empty($presence['ok'])) : ?>
            <span class="badge badge-usk ms-1"><?= __('license_vendor_sync_ok_short') ?></span>
            <?php elseif (!empty($presence['error'])) : ?>
            <span class="badge badge-danger ms-1"><?= usk_esc(usk_license_error_message($presence['error'])) ?></span>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <p class="small text-muted mb-3"><?= __('license_vendor_auto_note') ?></p>
        <form method="post" class="d-inline me-2">
            <input type="hidden" name="action" value="sync_vendor">
            <button type="submit" class="btn btn-outline-usk btn-sm"><?= __('license_vendor_sync_btn') ?></button>
        </form>
        <?php else : ?>
        <p class="small mb-3"><?= __('license_vendor_not_configured') ?></p>
        <?php endif; ?>
        <form method="post" class="mt-2">
            <div class="form-group mb-3">
                <label><?= __('license_vendor_token_label') ?></label>
                <input class="form-control" type="password" name="license_api_token" dir="ltr" autocomplete="off" placeholder="<?= __('license_vendor_token_placeholder') ?>" <?= $vendorConfigured ? '' : 'required' ?>>
                <p class="small text-muted mt-1 mb-0"><?= __('license_vendor_token_hint') ?></p>
            </div>
            <button type="submit" name="action" value="connect_vendor" class="btn btn-usk-primary btn-sm"><?= __('license_vendor_connect_btn') ?></button>
            <?php if ($vendorConfigured) : ?>
            <button type="submit" name="action" value="save_vendor_token" class="btn btn-outline-usk btn-sm ms-1"><?= __('license_vendor_save_token_btn') ?></button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="usk-card">
    <div class="usk-card-header"><?= __('license_instance') ?></div>
    <div class="p-3"><code class="usk-code" style="word-break:break-all"><?= usk_esc(USK_License::instance_id()) ?></code></div>
</div>
