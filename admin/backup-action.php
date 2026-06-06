<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/backup.php';

USK_Admin_Auth::boot();
USK_Admin_Auth::require_login();

$action = (string) ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action === '') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$password = (string) ($_POST['password'] ?? '');
if ($password === '' || !USK_Admin_Auth::verify_password($password)) {
    usk_flash(__('backup_password_invalid'), 'error');
    header('Location: ' . usk_admin_url('backup'));
    exit;
}

if ($action === 'export') {
    if (!USK_PanelBackup::zip_available()) {
        usk_flash(__('backup_zip_missing'), 'error');
        header('Location: ' . usk_admin_url('backup'));
        exit;
    }

    $result = USK_PanelBackup::export();
    if (empty($result['ok'])) {
        usk_flash(__('backup_export_failed') . ' (' . usk_esc($result['error'] ?? 'unknown') . ')', 'error');
        header('Location: ' . usk_admin_url('backup'));
        exit;
    }

    $path = $result['path'];
    $filename = $result['filename'];

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');

    readfile($path);
    @unlink($path);
    exit;
}

if ($action === 'import') {
    if (empty($_FILES['backup_file']['tmp_name'])) {
        usk_flash(__('backup_file_missing'), 'error');
        header('Location: ' . usk_admin_url('backup'));
        exit;
    }

    if (!empty($_FILES['backup_file']['error'])) {
        usk_flash(__('backup_upload_error'), 'error');
        header('Location: ' . usk_admin_url('backup'));
        exit;
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $name = (string) ($_FILES['backup_file']['name'] ?? '');
    if (!preg_match('/\.uskbackup$/i', $name)) {
        usk_flash(__('backup_bad_extension'), 'error');
        header('Location: ' . usk_admin_url('backup'));
        exit;
    }

    $result = USK_PanelBackup::import($tmp);
    if (empty($result['ok'])) {
        usk_flash(__('backup_import_failed') . ' (' . usk_esc($result['error'] ?? 'unknown') . ')', 'error');
        header('Location: ' . usk_admin_url('backup'));
        exit;
    }

    $stats = $result['stats'] ?? array();
    $hadPro = !empty($result['had_pro_license']);

    if ($hadPro) {
        usk_flash(sprintf(
            __('backup_import_ok_relicense'),
            (int) ($stats['sql_statements'] ?? 0),
            (int) ($stats['files_copied'] ?? 0)
        ));
        header('Location: ' . usk_admin_url('license'));
        exit;
    }

    usk_flash(sprintf(
        __('backup_import_ok'),
        (int) ($stats['sql_statements'] ?? 0),
        (int) ($stats['files_copied'] ?? 0)
    ));

    header('Location: ' . usk_admin_url('backup'));
    exit;
}

http_response_code(400);
header('Location: ' . usk_admin_url('backup'));
exit;
