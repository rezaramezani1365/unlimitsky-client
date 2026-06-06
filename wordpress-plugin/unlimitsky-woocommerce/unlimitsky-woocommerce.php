<?php
/**
 * Plugin Name: unlimitsky - WooCommerce
 * Plugin URI:  https://iranip.online
 * Description: فروش خودکار کانفیگ VPN — پروتکل native (WireGuard/OpenVPN/Xray/L2TP) + Marzban/Sanaei
 * Version:     1.3.1
 * Author:      unlimitsky
 * Text Domain: unlimitsky-wc
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

define('USK_WC_VERSION', '1.3.1');
define('USK_WC_PLUGIN_FILE', __FILE__);
define('USK_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('USK_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-activator.php';
require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-plugin.php';

register_activation_hook(__FILE__, ['USK_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['USK_Activator', 'deactivate']);

add_action('plugins_loaded', static function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('unlimitsky requires WooCommerce to be installed and active.', 'unlimitsky-wc');
            echo '</p></div>';
        });
        return;
    }

    USK_Plugin::instance();
});
