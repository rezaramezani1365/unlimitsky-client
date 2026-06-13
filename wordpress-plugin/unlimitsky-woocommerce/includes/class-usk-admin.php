<?php

defined('ABSPATH') || exit;

class USK_Admin
{
    public function __construct()
    {
        USK_Api_Settings::maybe_migrate_from_panels();

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_usk_save_api', [$this, 'handle_save_api']);
        add_action('admin_post_usk_test_api', [$this, 'handle_test_api']);
        add_action('admin_post_usk_save_dns', [$this, 'handle_save_dns']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('unlimitsky', 'unlimitsky-wc'),
            __('unlimitsky', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky',
            [$this, 'render_api_settings_page'],
            'dashicons-cloud',
            56
        );

        add_submenu_page(
            'unlimitsky',
            __('اتصال API', 'unlimitsky-wc'),
            __('اتصال API', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky',
            [$this, 'render_api_settings_page']
        );

        add_submenu_page(
            'unlimitsky',
            __('راهنمای اتصال', 'unlimitsky-wc'),
            __('راهنما', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky-guides',
            [$this, 'render_guides_page']
        );

        add_submenu_page(
            'unlimitsky',
            __('تنظیمات اتصال', 'unlimitsky-wc'),
            __('DNS / اتصال', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky-dns',
            [$this, 'render_dns_page']
        );

        add_submenu_page(
            'unlimitsky',
            __('سفارشات VPN', 'unlimitsky-wc'),
            __('سفارشات', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky-orders',
            [$this, 'render_orders_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'unlimitsky') === false) {
            return;
        }
        wp_enqueue_style('UnlimitSky-admin', USK_WC_PLUGIN_URL . 'assets/css/admin.css', [], USK_WC_VERSION);
        wp_enqueue_script('UnlimitSky-theme', USK_WC_PLUGIN_URL . 'assets/js/theme.js', [], USK_WC_VERSION, true);
    }

    public function render_api_settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        include USK_WC_PLUGIN_DIR . 'admin/views/api-settings.php';
    }

    public function render_guides_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        include USK_WC_PLUGIN_DIR . 'admin/views/guides.php';
    }

    public function render_dns_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $dns_settings = USK_Dns_Settings::get();
        include USK_WC_PLUGIN_DIR . 'admin/views/dns-settings.php';
    }

    public function render_orders_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        global $wpdb;
        $orders = $wpdb->get_results(
            'SELECT * FROM ' . USK_table('orders') . ' ORDER BY created_at DESC LIMIT 200',
            ARRAY_A
        ) ?: [];

        include USK_WC_PLUGIN_DIR . 'admin/views/orders.php';
    }

    public function handle_save_api(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('usk_save_api')) {
            wp_die(__('دسترسی غیرمجاز', 'unlimitsky-wc'));
        }

        USK_Api_Settings::save([
            'api_url' => $_POST['api_url'] ?? '',
            'api_key' => $_POST['api_key'] ?? '',
        ]);

        wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_test_api(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('usk_test_api')) {
            wp_die(__('دسترسی غیرمجاز', 'unlimitsky-wc'));
        }

        $cfg = USK_Api_Settings::get();
        $res = USK_UnlimitSky_Panel::test_connection($cfg['api_url'], $cfg['api_key']);

        if (!empty($res['ok'])) {
            wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'test' => 'ok'], admin_url('admin.php')));
        } else {
            set_transient('usk_test_api_error', $res['error'] ?? '', 30);
            wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'test' => 'fail'], admin_url('admin.php')));
        }
        exit;
    }

    public function handle_save_dns(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('usk_save_dns')) {
            wp_die(__('دسترسی غیرمجاز', 'unlimitsky-wc'));
        }

        USK_Dns_Settings::save($_POST);
        USK_Subscription_Proxy::flush_rules();

        wp_safe_redirect(add_query_arg(['page' => 'unlimitsky-dns', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }
}
