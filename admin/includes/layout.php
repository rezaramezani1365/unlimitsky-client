<?php

/** @var string $page_title */

/** @var string $active_nav */

/** @var string $content */

require_once dirname(__DIR__) . '/lib/auth.php';

USK_Admin_Auth::require_login();

$flash = usk_get_flash();

$base = usk_admin_base();

$nav = array(
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
    'coupons' => array('icon' => 'fa-gift', 'label' => __('nav_coupons')),
    'settings' => array('icon' => 'fa-gear', 'label' => __('nav_settings')),
);

$dir = usk_dir();

$lang = usk_lang();

?>

<!DOCTYPE html>

<html lang="<?= usk_esc(USK_I18n::html_lang()) ?>" dir="<?= $dir ?>" data-bs-theme="dark">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= usk_esc($page_title ?? __('panel')) ?> | <?= __('brand') ?></title>

    <?php usk_enqueue_head(); ?>

</head>

<body class="usk-body">

<div class="container-fluid">

    <div class="row g-0">

        <nav class="col-lg-2 usk-sidebar p-3">

            <div class="mb-4 px-1">

                <a class="usk-brand text-decoration-none d-block" href="<?= usk_admin_url('dashboard') ?>">

                    Unlimited<span>Sky</span>

                </a>

            </div>

            <div class="nav flex-column">

                <?php foreach ($nav as $key => $item) : ?>

                    <a class="usk-nav-link text-decoration-none d-flex align-items-center gap-2 <?= ($active_nav ?? '') === $key ? 'active' : '' ?>"

                       href="<?= usk_admin_url($key) ?>">

                        <i class="fa-solid <?= $item['icon'] ?>"></i>

                        <?= usk_esc($item['label']) ?>

                    </a>

                <?php endforeach; ?>

                <hr style="border-color:var(--usk-border);opacity:1;margin:0.75rem 0;">

                <a class="usk-nav-link text-decoration-none d-flex align-items-center gap-2" href="<?= $base ?>/logout.php">

                    <i class="fa-solid fa-right-from-bracket"></i> <?= __('logout') ?>

                </a>

            </div>

        </nav>



        <main class="col-lg-10 px-3 px-lg-4 py-3">

            <div class="usk-topbar d-flex flex-wrap align-items-center justify-content-between gap-2">

                <div>

                    <h1 class="h5 mb-0"><?= usk_esc($page_title ?? '') ?></h1>

                    <small><?= __('brand') ?> v<?= usk_esc($GLOBALS['config']['version'] ?? '2.5') ?></small>

                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">

                    <div class="lang-switch">

                        <a href="<?= usk_admin_url($active_nav ?? 'dashboard', array('lang' => 'fa')) ?>" class="<?= $lang === 'fa' ? 'active' : '' ?>">FA</a>

                        <a href="<?= usk_admin_url($active_nav ?? 'dashboard', array('lang' => 'en')) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>

                    </div>

                    <button type="button" class="btn btn-sm btn-outline-usk" id="theme-toggle" title="<?= __('theme') ?>">

                        <i class="fa-solid fa-moon" id="theme-icon"></i>

                    </button>

                    <span class="badge badge-usk"><i class="fa-solid fa-user"></i> <?= usk_esc(USK_Admin_Auth::current_user()) ?></span>

                </div>

            </div>



            <?php if ($flash) : ?>

                <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">

                    <?= usk_esc($flash['msg']) ?>

                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

                </div>

            <?php endif; ?>



            <?= $content ?>

        </main>

    </div>

</div>

<?php usk_enqueue_foot(); ?>

</body>

</html>


