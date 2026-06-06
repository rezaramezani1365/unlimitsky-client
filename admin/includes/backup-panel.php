<?php

require_once dirname(__DIR__) . '/lib/backup.php';
require_once dirname(__DIR__) . '/lib/php-zip.php';

$backupFormSuffix = isset($backupFormSuffix) ? (string) $backupFormSuffix : '';
$backupReturnPage = isset($backupReturnPage) ? preg_replace('/[^a-z-]/', '', (string) $backupReturnPage) : 'backup';
if ($backupReturnPage === '') {
    $backupReturnPage = 'backup';
}
$confirmId = 'backup-confirm' . $backupFormSuffix;
$zipOk = USK_PhpZip::available_cli();
$zipStatus = USK_PhpZip::get_status();
$zipInstalling = ($zipStatus['state'] ?? '') === 'running'
    && USK_PhpZip::running_age_sec() < USK_PhpZip::RUNNING_TIMEOUT_SEC;
$zipStale = !$zipOk && (
    (($zipStatus['state'] ?? '') === 'failed' && ($zipStatus['message'] ?? '') === 'stale_timeout')
    || USK_PhpZip::is_install_stale()
);
$zipCanInstall = !$zipOk && !$zipInstalling && !$zipStale && USK_PhpZip::can_install_from_web();
$zipFailed = ($zipStatus['state'] ?? '') === 'failed' && !$zipOk;
$zipDiag = !$zipOk ? USK_PhpZip::diagnostics() : null;
$zipSuggested = !$zipOk ? USK_PhpZip::suggested_zip_package() : '';
$tables = USK_PanelBackup::tables();
$dataPaths = USK_PanelBackup::data_paths();
?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-circle-info"></i> <?= __('backup_v1_intro') ?>
</div>

<?php if (!$zipOk) : ?>
    <div class="alert alert-warning mb-4">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= __('backup_zip_missing') ?>
        <?php if ($zipInstalling) : ?>
        <p class="small mb-0 mt-2"><i class="fa-solid fa-spinner fa-spin"></i> <?= __('backup_zip_install_running') ?></p>
        <form method="post" action="<?= usk_esc(usk_admin_base()) ?>/backup-action.php" class="mt-2 mb-0">
            <input type="hidden" name="action" value="cancel_install_zip">
            <input type="hidden" name="return_page" value="<?= usk_esc($backupReturnPage) ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm"><?= __('backup_zip_install_cancel') ?></button>
        </form>
        <?php elseif ($zipStale || ($zipFailed && ($zipStatus['message'] ?? '') === 'stale_timeout')) : ?>
        <p class="small text-danger mb-0 mt-2"><?= __('backup_zip_install_stale') ?></p>
        <pre class="small mt-2 mb-0 p-2 bg-dark text-light" style="white-space:pre-wrap;direction:ltr"><?= usk_esc(sprintf(
            "sudo apt update\nsudo apt install -y %s\nsudo systemctl restart php*-fpm\nphp -r \"var_dump(class_exists('ZipArchive'));\"\nsudo rm -f /var/www/unlimitsky/data/settings/php-zip-install.json",
            $zipSuggested
        )) ?></pre>
        <?php if ($zipDiag) : ?>
        <p class="small text-muted mb-0 mt-2" dir="ltr">PHP: <?= usk_esc($zipDiag['php_version']) ?>
        <?php if (!empty($zipDiag['fpm_packages'])) : ?> | FPM: <?= usk_esc(implode(', ', $zipDiag['fpm_packages'])) ?><?php endif; ?>
        <?php if (!empty($zipDiag['zip_packages'])) : ?> | apt zip: <?= usk_esc(implode(', ', $zipDiag['zip_packages'])) ?><?php endif; ?>
        </p>
        <?php endif; ?>
        <?php elseif ($zipCanInstall) : ?>
        <form method="post" action="<?= usk_esc(usk_admin_base()) ?>/backup-action.php" class="mt-3 mb-0">
            <input type="hidden" name="action" value="install_zip">
            <input type="hidden" name="return_page" value="<?= usk_esc($backupReturnPage) ?>">
            <button type="submit" class="btn btn-usk-primary btn-sm">
                <i class="fa-solid fa-download"></i> <?= __('backup_zip_install_btn') ?>
            </button>
            <span class="text-muted small ms-2"><?= __('backup_zip_install_hint') ?></span>
        </form>
        <?php else : ?>
        <p class="small mb-0 mt-2"><?= __('backup_zip_install_manual') ?></p>
        <?php endif; ?>
        <?php if ($zipFailed && !empty($zipStatus['message']) && ($zipStatus['message'] ?? '') !== 'stale_timeout') : ?>
        <pre class="small mt-2 mb-0 p-2 bg-dark text-light" style="white-space:pre-wrap;direction:ltr"><?= usk_esc($zipStatus['message']) ?></pre>
        <?php endif; ?>
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
                    <input type="hidden" name="return_page" value="<?= usk_esc($backupReturnPage) ?>">
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
                    <input type="hidden" name="return_page" value="<?= usk_esc($backupReturnPage) ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= __('backup_file_label') ?></label>
                        <input type="file" name="backup_file" class="form-control" accept=".uskbackup,application/zip" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= __('backup_password_confirm') ?></label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="<?= usk_esc($confirmId) ?>" required>
                        <label class="form-check-label small" for="<?= usk_esc($confirmId) ?>"><?= __('backup_import_confirm') ?></label>
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
            <li><code>admin/data/api-keys.json</code>, <code>admin/data/license.json</code> (export only)</li>
        </ul>
    </div>
</div>
<?php if ($zipInstalling) : ?>
<script>
(function () {
    var n = parseInt(sessionStorage.getItem('uskZipRefresh') || '0', 10);
    if (n >= 8) { return; }
    sessionStorage.setItem('uskZipRefresh', String(n + 1));
    setTimeout(function () { window.location.reload(); }, 15000);
})();
</script>
<?php else : ?>
<script>try { sessionStorage.removeItem('uskZipRefresh'); } catch (e) {}</script>
<?php endif; ?>
