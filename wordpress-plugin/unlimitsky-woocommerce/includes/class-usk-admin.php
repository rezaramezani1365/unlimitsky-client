<?php

defined('ABSPATH') || exit;

class USK_Admin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_usk_save_panel', [$this, 'handle_save_panel']);
        add_action('admin_post_usk_delete_panel', [$this, 'handle_delete_panel']);
        add_action('admin_post_usk_test_panel', [$this, 'handle_test_panel']);
        add_action('admin_post_usk_save_dns', [$this, 'handle_save_dns']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('unlimitsky', 'unlimitsky-wc'),
            __('unlimitsky', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky',
            [$this, 'render_panels_page'],
            'dashicons-cloud',
            56
        );

        add_submenu_page(
            'unlimitsky',
            __('مدیریت پنل‌ها', 'unlimitsky-wc'),
            __('پنل‌ها', 'unlimitsky-wc'),
            'manage_woocommerce',
            'unlimitsky',
            [$this, 'render_panels_page']
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
        if (strpos($hook, 'UnlimitSky') === false) {
            return;
        }
        wp_enqueue_style('UnlimitSky-admin', USK_WC_PLUGIN_URL . 'assets/css/admin.css', [], USK_WC_VERSION);
        wp_enqueue_script('UnlimitSky-theme', USK_WC_PLUGIN_URL . 'assets/js/theme.js', [], USK_WC_VERSION, true);
    }

    public function render_guides_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        include USK_WC_PLUGIN_DIR . 'admin/views/guides.php';
    }

    public function render_panels_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $panel   = $edit_id ? USK_Panel_Manager::get_panel($edit_id) : null;
        $panels  = USK_Panel_Manager::get_panels(false);

        $panel_nodes = [];
        $panel_node_protocols = [];
        $panel_nodes_error = '';
        $panel_node_labels = [];

        foreach ($panels as $p) {
            if (($p['type'] ?? '') !== 'unlimitsky' || empty($p['provision_node_id']) || empty($p['login_link']) || empty($p['token'])) {
                continue;
            }
            $nl = USK_UnlimitSky_Panel::list_nodes($p['login_link'], $p['token']);
            foreach ($nl['nodes'] ?? [] as $node) {
                if (($node['id'] ?? '') === $p['provision_node_id']) {
                    $panel_node_labels[(int) $p['id']] = ($node['name'] ?? $node['id']) . ' — ' . ($node['connect_host'] ?? '');
                    break;
                }
            }
            if (!isset($panel_node_labels[(int) $p['id']])) {
                $panel_node_labels[(int) $p['id']] = $p['provision_node_id'];
            }
        }

        if ($panel) {
            $sanayi_setting = USK_Panel_Manager::get_sanayi_setting($panel['code']);
            $marzban_inbounds = USK_Panel_Manager::get_marzban_inbounds($panel['code']);
            if (($panel['type'] ?? '') === 'unlimitsky' && !empty($panel['login_link']) && !empty($panel['token'])) {
                $nodeList = USK_UnlimitSky_Panel::list_nodes($panel['login_link'], $panel['token']);
                $panel_nodes = $nodeList['nodes'] ?? [];
                $panel_node_protocols = $nodeList['node_protocols'] ?? [];
                $panel_nodes_error = $nodeList['error'] ?? '';
            }
        }

        include USK_WC_PLUGIN_DIR . 'admin/views/panels.php';
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

    public function handle_save_panel(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('usk_save_panel')) {
            wp_die(__('دسترسی غیرمجاز', 'unlimitsky-wc'));
        }

        $type = sanitize_text_field($_POST['type'] ?? 'marzban');
        $protocols = $_POST['protocols'] ?? 'vless|';
        if ($type === 'unlimitsky') {
            $native = sanitize_text_field($_POST['native_protocol'] ?? 'wireguard');
            $protocols = $native . '|';
        }

        $panel_id = USK_Panel_Manager::save_panel([
            'id'         => absint($_POST['panel_id'] ?? 0),
            'name'       => $_POST['name'] ?? '',
            'login_link' => $_POST['login_link'] ?? '',
            'username'   => $_POST['username'] ?? '',
            'password'   => $_POST['password'] ?? '',
            'token'      => $_POST['token'] ?? '',
            'type'       => $type,
            'protocols'  => $protocols,
            'flow'         => $_POST['flow'] ?? 'flowon',
            'status'       => $_POST['status'] ?? 'active',
            'backend_ip'   => $_POST['backend_ip'] ?? '',
            'backend_host' => $_POST['backend_host'] ?? '',
            'provision_node_id' => $_POST['provision_node_id'] ?? '',
        ]);

        $panel = USK_Panel_Manager::get_panel($panel_id);

        if ($panel && ($panel['type'] ?? '') === 'unlimitsky' && !empty($panel['provision_node_id']) && trim((string) ($_POST['backend_ip'] ?? '')) === '') {
            $nodeList = USK_UnlimitSky_Panel::list_nodes($panel['login_link'], $panel['token'] ?? '');
            foreach ($nodeList['nodes'] ?? [] as $node) {
                if (($node['id'] ?? '') === $panel['provision_node_id'] && !empty($node['connect_host'])) {
                    global $wpdb;
                    $wpdb->update(
                        USK_table('panels'),
                        ['backend_ip' => sanitize_text_field($node['connect_host'])],
                        ['id' => $panel_id]
                    );
                    $panel['backend_ip'] = $node['connect_host'];
                    break;
                }
            }
        }

        if ($panel['type'] === 'sanayi') {
            USK_Panel_Manager::save_sanayi_setting(
                $panel['code'],
                $_POST['inbound_id'] ?? '',
                $_POST['example_link'] ?? '',
                $_POST['sanayi_flow'] ?? ''
            );
        }

        if ($panel['type'] === 'marzban') {
            $inbounds_raw = sanitize_textarea_field($_POST['marzban_inbounds'] ?? '');
            $inbounds     = array_filter(array_map('trim', explode("\n", $inbounds_raw)));
            USK_Panel_Manager::save_marzban_inbounds($panel['code'], $inbounds);
        }

        if ($panel['type'] !== 'unlimitsky') {
            USK_Panel_Manager::refresh_panel_token($panel);
        }

        wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_delete_panel(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('usk_delete_panel')) {
            wp_die(__('دسترسی غیرمجاز', 'unlimitsky-wc'));
        }

        USK_Panel_Manager::delete_panel(absint($_GET['id'] ?? 0));

        wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_test_panel(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('usk_test_panel')) {
            wp_die(__('دسترسی غیرمجاز', 'unlimitsky-wc'));
        }

        $panel = USK_Panel_Manager::get_panel(absint($_GET['id'] ?? 0));
        if (!$panel) {
            wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'test' => 'fail'], admin_url('admin.php')));
            exit;
        }

        if ($panel['type'] === 'unlimitsky') {
            $ok = USK_UnlimitSky_Panel::test_connection($panel['login_link'], $panel['token'] ?? '');
        } else {
            $panel = USK_Panel_Manager::refresh_panel_token($panel);
            $ok    = !empty($panel['token']);
        }

        wp_safe_redirect(add_query_arg(['page' => 'unlimitsky', 'test' => $ok ? 'ok' : 'fail'], admin_url('admin.php')));
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
