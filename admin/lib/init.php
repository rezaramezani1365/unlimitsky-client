<?php

defined('USK_ADMIN') || define('USK_ADMIN', true);

date_default_timezone_set('Asia/Tehran');

// client/admin/lib → client root
define('USK_ROOT', dirname(__DIR__, 2));

require_once USK_ROOT . '/config.php';
require_once USK_ROOT . '/api/sanayi.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/api-keys.php';
require_once __DIR__ . '/protocols/manager.php';
require_once __DIR__ . '/protocols/limits.php';
require_once __DIR__ . '/protocols/provisioner.php';
require_once __DIR__ . '/client-dns.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/migration.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

USK_I18n::boot();
USK_License::boot();

function usk_admin_base()
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'));
    return rtrim($script, '/');
}

function usk_admin_url($page = 'dashboard', array $params = array())
{
    $q = array_merge(array('page' => $page), $params);
    return usk_admin_base() . '/index.php?' . http_build_query($q);
}

function usk_esc($s)
{
    return htmlspecialchars($s !== null ? $s : '', ENT_QUOTES, 'UTF-8');
}

function usk_flash($msg, $type = 'success')
{
    $_SESSION['usk_flash'] = array('msg' => $msg, 'type' => $type);
}

function usk_get_flash()
{
    if (empty($_SESSION['usk_flash'])) {
        return null;
    }
    $f = $_SESSION['usk_flash'];
    unset($_SESSION['usk_flash']);
    return $f;
}

function usk_panel_code()
{
    return (string) rand(11111111, 99999999);
}

function usk_service_name($code, $suffix = 'web')
{
    return base64_encode($code) . '_' . $suffix . '_' . time();
}

function usk_github_repo_url()
{
    return 'https://github.com/rezaramezani1365/unlimitsky-client';
}

function usk_panel_version()
{
    global $config;
    return (string) ($config['version'] ?? '2.5');
}

/** Legacy pages removed from the web admin panel. */
function usk_admin_removed_pages()
{
    return array('coupons', 'payments', 'password');
}

/** Sidebar navigation (single source of truth). */
function usk_admin_nav()
{
    return array(
        'dashboard' => array('icon' => 'fa-gauge-high', 'label' => __('nav_dashboard')),
        'protocols' => array('icon' => 'fa-network-wired', 'label' => __('nav_protocols')),
        'panels' => array('icon' => 'fa-server', 'label' => __('nav_panels')),
        'guides' => array('icon' => 'fa-book', 'label' => __('nav_guides')),
        'api-keys' => array('icon' => 'fa-key', 'label' => __('nav_api_keys')),
        'plans' => array('icon' => 'fa-tags', 'label' => __('nav_plans')),
        'create-service' => array('icon' => 'fa-plus-circle', 'label' => __('nav_create')),
        'services' => array('icon' => 'fa-shield-halved', 'label' => __('nav_services')),
        'users' => array('icon' => 'fa-users', 'label' => __('nav_users')),
        'license' => array('icon' => 'fa-crown', 'label' => __('nav_license')),
        'backup' => array('icon' => 'fa-database', 'label' => __('nav_backup')),
        'settings' => array('icon' => 'fa-gear', 'label' => __('nav_settings')),
    );
}
