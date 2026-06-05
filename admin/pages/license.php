<?php
$GLOBALS['page_title'] = __('nav_license');
$GLOBALS['active_nav'] = 'license';

USK_License::validate_cached();
$info = USK_License::get();
$is_pro = USK_License::is_pro();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['action']) ? $_POST['action'] : '';
    if ($act === 'activate') {
        $key = isset($_POST['license_key']) ? $_POST['license_key'] : '';
        $res = USK_License::activate($key);
        if (!empty($res['ok'])) {
            usk_flash(__('license_activated'));
        } else {
            $err = isset($res['error']) ? $res['error'] : 'error';
            usk_flash(__('license_failed') . ': ' . $err, 'error');
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
                <input class="form-control" name="license_key" required placeholder="USK-XXXXX-XXXXX-XXXXX-XXXXX" dir="ltr">
            </div>
            <button type="submit" class="btn btn-usk-primary"><?= __('license_activate_btn') ?></button>
        </form>
        <p class="text-muted small mt-3 mb-0"><?= __('license_free_note') ?></p>
    </div>
</div>
<?php endif; ?>

<div class="usk-card">
    <div class="usk-card-header"><?= __('license_instance') ?></div>
    <div class="p-3"><code class="usk-code" style="word-break:break-all"><?= usk_esc(USK_License::instance_id()) ?></code></div>
</div>
