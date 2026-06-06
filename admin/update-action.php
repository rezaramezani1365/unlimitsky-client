<?php
require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/panel-update.php';

USK_Admin_Auth::boot();

if (!USK_Admin_Auth::check()) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . usk_admin_url('updates'));
    exit;
}

$password = (string) ($_POST['password'] ?? '');
if (!USK_Admin_Auth::verify_password($password)) {
    usk_flash(__('update_password_invalid'), 'error');
    header('Location: ' . usk_admin_url('updates'));
    exit;
}

$result = USK_Panel_Update::start_update_async();
if (!empty($result['ok'])) {
    if (!empty($result['async'])) {
        usk_flash(__('update_started_async'));
    } else {
        usk_flash(__('update_ok'));
    }
} else {
    usk_flash(__('update_fail') . "\n" . ($result['output'] ?? ''), 'error');
}

header('Location: ' . usk_admin_url('updates'));
exit;
