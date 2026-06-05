<?php

defined('ABSPATH') || exit;

class USK_Activator
{
    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix . 'USK_';

        dbDelta("CREATE TABLE {$prefix}panels (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            login_link varchar(255) NOT NULL,
            username varchar(100) DEFAULT NULL,
            password varchar(255) DEFAULT NULL,
            token text DEFAULT NULL,
            type varchar(30) NOT NULL DEFAULT 'marzban',
            protocols varchar(100) DEFAULT 'vless|',
            flow varchar(15) DEFAULT 'flowon',
            qr_code varchar(10) DEFAULT 'active',
            code varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'active',
            count_create int DEFAULT 0,
            dns_slug varchar(50) DEFAULT NULL,
            backend_ip varchar(45) DEFAULT NULL,
            backend_host varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}sanayi_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            panel_code varchar(50) NOT NULL,
            inbound_id varchar(20) NOT NULL,
            example_link text DEFAULT NULL,
            flow varchar(50) DEFAULT '',
            PRIMARY KEY (id),
            KEY panel_code (panel_code)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}marzban_inbounds (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            panel_code varchar(50) NOT NULL,
            inbound varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            KEY panel_code (panel_code)
        ) $charset;");

        dbDelta("CREATE TABLE {$prefix}orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wc_order_id bigint(20) unsigned NOT NULL,
            wc_order_item_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            panel_name varchar(100) NOT NULL,
            panel_type varchar(30) NOT NULL,
            volume_gb int NOT NULL,
            duration_days int NOT NULL,
            subscription_url text NOT NULL,
            original_subscription_url text DEFAULT NULL,
            config_links longtext DEFAULT NULL,
            connect_host varchar(255) DEFAULT NULL,
            proxy_token varchar(64) DEFAULT NULL,
            service_username varchar(100) NOT NULL,
            service_code varchar(20) NOT NULL,
            price decimal(12,2) DEFAULT 0,
            protocol varchar(30) DEFAULT NULL,
            qr_png longtext DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY wc_order_id (wc_order_id),
            KEY user_id (user_id),
            KEY proxy_token (proxy_token)
        ) $charset;");

        self::maybe_add_columns($prefix . 'orders');

        flush_rewrite_rules();
    }

    private static function maybe_add_columns(string $table): void
    {
        global $wpdb;
        $cols = $wpdb->get_col("DESC {$table}", 0);
        if (!is_array($cols)) {
            return;
        }
        if (!in_array('protocol', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD protocol varchar(30) DEFAULT NULL");
        }
        if (!in_array('qr_png', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD qr_png longtext DEFAULT NULL");
        }
        if (!in_array('expires_at', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD expires_at datetime DEFAULT NULL");
        }
        if (!in_array('openvpn_proto', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD openvpn_proto varchar(10) DEFAULT NULL");
        }
        if (!in_array('vpn_uri', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD vpn_uri longtext DEFAULT NULL");
        }
        if (!in_array('qr_conf_png', $cols, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD qr_conf_png longtext DEFAULT NULL");
        }
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
