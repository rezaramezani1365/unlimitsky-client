<?php

defined('ABSPATH') || exit;

function USK_table(string $name): string
{
    global $wpdb;
    return $wpdb->prefix . 'USK_' . $name;
}

function USK_convert_to_bytes(string $from): ?int
{
    $units  = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $number = substr($from, 0, -2);
    $suffix = strtoupper(substr($from, -2));

    if (is_numeric(substr($suffix, 0, 1))) {
        return (int) preg_replace('/[^\d]/', '', $from);
    }

    $exponent = array_flip($units)[$suffix] ?? null;
    if ($exponent === null) {
        return null;
    }

    return (int) ($number * (1024 ** $exponent));
}

function USK_generate_code(): string
{
    return (string) wp_rand(111111, 999999);
}

function USK_generate_panel_code(): string
{
    return wp_generate_password(12, false, false);
}

function USK_service_username(int $order_id, int $item_id, string $code): string
{
    return base64_encode($code) . '_wc' . $order_id . '_' . $item_id;
}
