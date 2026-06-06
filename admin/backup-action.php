<?php

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/php-zip.php';

USK_Admin_Auth::boot();
USK_Admin_Auth::require_login();

$action = (string) ($_POST['action'] ?? '');

function usk_backup_redirect_url()
{
    $return = preg_replace('/[^a-z-]/', '', (string) ($_POST['return_page'] ?? 'backup'));
    if ($return === '') {
        $return = 'backup';
    }
    return usk_admin_url($return);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action === '') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

if ($action === 'install_zip') {
    if (USK_PhpZip::available_cli()) {
        usk_flash(__('backup_zip_already'), 'info');
    } elseif (USK_PhpZip::is_install_running()) {
        usk_flash(__('backup_zip_install_running'), 'info');
    } else {
        $result = USK_PhpZip::start_install_async();
        if (!empty($result['ok'])) {
            if (($result['msg'] ?? '') === 'already_installed') {
                usk_flash(__('backup_zip_already'), 'info');
            } else {
                usk_flash(__('backup_zip_install_started'), 'info');
            }
        } else {
            usk_flash(__('backup_zip_install_failed') . ': ' . ($result['output'] ?? ''), 'error');
        }
    }
    header('Location: ' . usk_backup_redirect_url());
    exit;
}

if ($action === 'cancel_install_zip') {
    USK_PhpZip::cancel_install();
    usk_flash(__('backup_zip_install_cancelled'), 'info');
    header('Location: ' . usk_backup_redirect_url());
    exit;
}

$password = (string) ($_POST['password'] ?? '');
if ($password === '' || !USK_Admin_Auth::verify_password($password)) {
    usk_flash(__('backup_password_invalid'), 'error');
    header('Location: ' . usk_backup_redirect_url());
    exit;
}

if ($action === 'export') {
    if (!USK_PanelBackup::zip_available()) {
        usk_flash(__('backup_zip_missing'), 'error');
        header('Location: ' . usk_backup_redirect_url());
        exit;
    }

    $result = USK_PanelBackup::export();
    if (empty($result['ok'])) {
        usk_flash(__('backup_export_failed') . ' (' . usk_esc($result['error'] ?? 'unknown') . ')', 'error');
        header('Location: ' . usk_backup_redirect_url());
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
        header('Location: ' . usk_backup_redirect_url());
        exit;
    }

    if (!empty($_FILES['backup_file']['error'])) {
        usk_flash(__('backup_upload_error'), 'error');
        header('Location: ' . usk_backup_redirect_url());
        exit;
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $name = (string) ($_FILES['backup_file']['name'] ?? '');
    if (!preg_match('/\.uskbackup$/i', $name)) {
        usk_flash(__('backup_bad_extension'), 'error');
        header('Location: ' . usk_backup_redirect_url());
        exit;
    }

    $result = USK_PanelBackup::import($tmp);
    if (empty($result['ok'])) {
        usk_flash(__('backup_import_failed') . ' (' . usk_esc($result['error'] ?? 'unknown') . ')', 'error');
        header('Location: ' . usk_backup_redirect_url());
        exit;
    }

    $stats = $result['stats'] ?? array();
    $hadPro = !empty($result['had_pro_license']);
    $needsReapply = !empty($result['needs_panel_access_reapply']);
    $restoredUrl = (string) ($result['restored_public_url'] ?? '');

    if ($hadPro) {
        $msg = sprintf(
            __('backup_import_ok_relicense'),
            (int) ($stats['sql_statements'] ?? 0),
            (int) ($stats['files_copied'] ?? 0)
        );
        if ($needsReapply && $restoredUrl !== '') {
            $msg .= ' ' . sprintf(__('backup_import_note_reapply'), $restoredUrl);
        }
        usk_flash($msg);
        header('Location: ' . usk_admin_url('license'));
        exit;
    }

    $msg = sprintf(
        __('backup_import_ok'),
        (int) ($stats['sql_statements'] ?? 0),
        (int) ($stats['files_copied'] ?? 0)
    );
    if ($needsReapply) {
        if ($restoredUrl !== '') {
            $msg .= ' ' . sprintf(__('backup_import_note_reapply'), $restoredUrl);
        } else {
            $msg .= ' ' . __('backup_import_note_reapply_generic');
        }
    }
    usk_flash($msg);

    if ($needsReapply) {
        header('Location: ' . usk_admin_url('settings'));
        exit;
    }

    header('Location: ' . usk_backup_redirect_url());
    exit;
}

http_response_code(400);
header('Location: ' . usk_backup_redirect_url());
exit;
