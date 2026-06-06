<?php
require_once __DIR__ . '/../lib/panel-update.php';

$GLOBALS['page_title'] = __('nav_updates');
$GLOBALS['active_nav'] = 'updates';

$localRev = USK_Panel_Update::localDeployRev();
$gitRev = USK_Panel_Update::gitHeadShort();
$legacy = USK_Panel_Update::isLegacyPanel();
$features = USK_Panel_Update::featureChecks();
$canUpdate = USK_Panel_Update::canRunFromWeb();
$deployOutdated = USK_Panel_Update::isDeployOutdated();
?>
<div class="usk-page-header">
    <h1><i class="fa-solid fa-arrows-rotate"></i> <?= __('nav_updates') ?></h1>
</div>

<?php if ($legacy) : ?>
<div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation"></i> <?= __('update_legacy_warning') ?>
</div>
<?php endif; ?>

<div class="usk-card mb-3">
    <div class="usk-card-header"><i class="fa-solid fa-code-branch"></i> <?= __('update_status_title') ?></div>
    <div class="p-3">
        <table class="table table-sm mb-0">
            <tr>
                <td><?= __('update_local_rev') ?></td>
                <td><code><?= usk_esc($localRev ?: __('update_unknown')) ?></code></td>
            </tr>
            <tr>
                <td><?= __('update_git_rev') ?></td>
                <td><code><?= usk_esc($gitRev ?: __('update_unknown')) ?></code></td>
            </tr>
            <tr>
                <td><?= __('update_repo') ?></td>
                <td><a href="<?= usk_esc(USK_Panel_Update::repoUrl()) ?>" target="_blank" rel="noopener"><?= usk_esc(USK_Panel_Update::repoUrl()) ?></a></td>
            </tr>
            <tr>
                <td><?= __('update_panel_version') ?></td>
                <td><?= usk_esc(usk_panel_version()) ?></td>
            </tr>
        </table>
        <?php if ($legacy) : ?>
        <p class="text-warning small mt-3 mb-0"><i class="fa-solid fa-circle-exclamation"></i> <?= __('update_features_missing') ?></p>
        <?php elseif ($deployOutdated) : ?>
        <p class="text-warning small mt-3 mb-0"><i class="fa-solid fa-circle-exclamation"></i> <?= __('update_outdated') ?></p>
        <?php else : ?>
        <p class="text-success small mt-3 mb-0"><i class="fa-solid fa-circle-check"></i> <?= __('update_uptodate') ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="usk-card mb-3">
    <div class="usk-card-header"><i class="fa-solid fa-list-check"></i> <?= __('update_features_title') ?></div>
    <div class="p-3">
        <ul class="mb-0">
            <?php foreach ($features as $label => $ok) : ?>
            <li class="<?= $ok ? 'text-success' : 'text-danger' ?>">
                <?= $ok ? '✓' : '✗' ?> <?= usk_esc($label) ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="usk-card">
    <div class="usk-card-header"><i class="fa-solid fa-cloud-arrow-down"></i> <?= __('update_run_title') ?></div>
    <div class="p-3">
        <p class="text-muted small"><?= __('update_run_desc') ?></p>
        <?php if (!$canUpdate) : ?>
        <div class="alert alert-danger mb-0"><?= __('update_script_missing') ?></div>
        <?php else : ?>
        <form method="post" action="<?= usk_esc(usk_admin_base()) ?>/update-action.php">
            <div class="form-group mb-3">
                <label class="form-label"><?= __('update_password_confirm') ?></label>
                <input type="password" name="password" class="form-control" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-usk-primary">
                <i class="fa-solid fa-arrows-rotate"></i> <?= __('update_btn') ?>
            </button>
        </form>
        <p class="text-muted small mt-3 mb-0"><?= __('update_sudo_hint') ?></p>
        <?php endif; ?>
    </div>
</div>
