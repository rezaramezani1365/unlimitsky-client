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
