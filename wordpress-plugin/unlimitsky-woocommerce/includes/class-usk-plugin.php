<?php

defined('ABSPATH') || exit;

class USK_Plugin
{
    private static ?USK_Plugin $instance = null;

    public static function instance(): USK_Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies(): void
    {
        require_once USK_WC_PLUGIN_DIR . 'includes/helpers.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/api/class-sanayi.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/api/class-marzban.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-wc-i18n.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-order-display.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-api-settings.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/api/class-unlimitsky-panel.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-service-creator.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-dns-settings.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-config-rewriter.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-subscription-proxy.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-rest-api.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-db-migrate.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-admin.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-woocommerce.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-order-handler.php';
        require_once USK_WC_PLUGIN_DIR . 'includes/class-usk-renewal.php';
    }

    private function init_hooks(): void
    {
        add_action('init', [USK_WC_I18n::class, 'boot'], 1);
        add_action('init', [USK_Db_Migrate::class, 'maybe_upgrade'], 5);

        new USK_Subscription_Proxy();
        new USK_Rest_Api();
        new USK_Admin();
        new USK_WooCommerce();
        new USK_Order_Handler();
        new USK_Renewal();
    }
}
