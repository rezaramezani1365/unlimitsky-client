<?php
require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/lib/auth.php';
USK_Admin_Auth::boot();

if (!USK_Admin_Auth::check()) {
    header('Location: login.php');
    exit;
}

if (!empty($_GET['lang']) && in_array($_GET['lang'], array('fa', 'en'), true)) {
    USK_I18n::set_lang($_GET['lang']);
    $page = preg_replace('/[^a-z-]/', '', $_GET['page'] ?? 'dashboard');
    $params = $_GET;
    unset($params['lang']);
    header('Location: ' . usk_admin_url($page, $params));
    exit;
}

if (USK_Admin_Auth::must_change_password() && ($_GET['page'] ?? 'dashboard') !== 'settings') {
    header('Location: ' . usk_admin_url('settings'));
    exit;
}

require_once __DIR__ . '/lib/service.php';

$page = preg_replace('/[^a-z-]/', '', $_GET['page'] ?? 'dashboard');
if ($page === 'payments' || $page === 'password' || $page === 'coupons') {
    header('Location: ' . usk_admin_url($page === 'payments' ? 'settings' : ($page === 'coupons' ? 'dashboard' : 'settings')));
    exit;
}
$file = __DIR__ . '/pages/' . $page . '.php';
if (!file_exists($file)) {
    $page = 'dashboard';
    $file = __DIR__ . '/pages/dashboard.php';
}

ob_start();
include $file;
$content = ob_get_clean();

$page_title = $GLOBALS['page_title'] ?? __('panel');
$active_nav = $GLOBALS['active_nav'] ?? $page;
include __DIR__ . '/includes/layout.php';

