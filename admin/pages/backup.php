<?php

require_once __DIR__ . '/../lib/backup.php';

$GLOBALS['page_title'] = __('nav_backup');
$GLOBALS['active_nav'] = 'backup';

$zipOk = USK_PanelBackup::zip_available();
$tables = USK_PanelBackup::tables();
$dataPaths = USK_PanelBackup::data_paths();
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-circle-info"></i> <?= __('backup_v1_intro') ?>
</div>

<?php if (!$zipOk) : ?>
    <div class="alert alert-danger mb-4">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= __('backup_zip_missing') ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="usk-card h-100">
            <div class="usk-card-header"><i class="fa-solid fa-download"></i> <?= __('backup_export_title') ?></div>
            <div class="p-3">
                <p class="text-muted small"><?= __('backup_export_desc') ?></p>
                <ul class="small text-muted mb-3">
                    <li><?= __('backup_includes_db') ?></li>
                    <li><?= __('backup_includes_data') ?></li>
                    <li><?= __('backup_includes_admin') ?></li>
                </ul>
                <details class="mb-3">
                    <summary class="small text-muted" style="cursor:pointer"><?= __('backup_tables_detail') ?></summary>
                    <code class="d-block small mt-2" dir="ltr"><?= usk_esc(implode(', ', $tables)) ?></code>
                </details>
                <form method="post" action="<?= usk_esc(usk_admin_base()) ?>/backup-action.php">
                    <input type="hidden" name="action" value="export">
                    <div class="mb-3">
                        <label class="form-label"><?= __('backup_password_confirm') ?></label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-usk-primary" <?= $zipOk ? '' : 'disabled' ?>>
                        <i class="fa-solid fa-file-zipper"></i> <?= __('backup_export_btn') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="usk-card h-100">
            <div class="usk-card-header"><i class="fa-solid fa-upload"></i> <?= __('backup_import_title') ?></div>
            <div class="p-3">
                <p class="text-muted small"><?= __('backup_import_desc') ?></p>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= __('backup_import_warning') ?>
                </div>
                <ul class="small text-muted mb-3">
                    <li><?= __('backup_import_note_vpn') ?></li>
                    <li><?= __('backup_import_note_license_v2') ?></li>
                    <li><?= __('backup_import_note_api') ?></li>
                </ul>
                <form method="post" action="<?= usk_esc(usk_admin_base()) ?>/backup-action.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import">
                    <div class="mb-3">
                        <label class="form-label"><?= __('backup_file_label') ?></label>
                        <input type="file" name="backup_file" class="form-control" accept=".uskbackup,application/zip" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= __('backup_password_confirm') ?></label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="backup-confirm" required>
                        <label class="form-check-label small" for="backup-confirm"><?= __('backup_import_confirm') ?></label>
                    </div>
                    <button type="submit" class="btn btn-outline-usk" <?= $zipOk ? '' : 'disabled' ?>>
                        <i class="fa-solid fa-cloud-arrow-up"></i> <?= __('backup_import_btn') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="usk-card mt-3">
    <div class="usk-card-header"><i class="fa-solid fa-folder-tree"></i> <?= __('backup_paths_title') ?></div>
    <div class="p-3">
        <ul class="small mb-0" dir="ltr">
            <?php foreach ($dataPaths as $path) : ?>
                <li><code><?= usk_esc($path) ?></code></li>
            <?php endforeach; ?>
            <li><code>admin/data/api-keys.json</code>, <code>admin/data/license.json</code></li>
        </ul>
    </div>
</div>
