<?php

function usk_asset_base()
{
    return usk_admin_base() . '/assets';
}

function usk_bootstrap_css()
{
    $file = USK_I18n::is_rtl() ? 'bootstrap.rtl.min.css' : 'bootstrap.min.css';
    return usk_asset_base() . '/vendor/bootstrap/' . $file;
}

function usk_enqueue_head()
{
    $base = usk_asset_base();
    echo '<link href="' . usk_esc($base . '/css/fonts.css') . '" rel="stylesheet">' . "\n";
    echo '<link href="' . usk_esc($base . '/vendor/fontawesome/css/all.min.css') . '" rel="stylesheet">' . "\n";
    echo '<link href="' . usk_esc(usk_bootstrap_css()) . '" rel="stylesheet">' . "\n";
    echo '<link href="' . usk_esc($base . '/css/theme.css') . '" rel="stylesheet">' . "\n";
    echo '<script src="' . usk_esc($base . '/js/theme.js') . '"></script>' . "\n";
}

function usk_enqueue_foot()
{
    echo '<script src="' . usk_esc(usk_asset_base() . '/vendor/bootstrap/bootstrap.bundle.min.js') . '"></script>' . "\n";
}
