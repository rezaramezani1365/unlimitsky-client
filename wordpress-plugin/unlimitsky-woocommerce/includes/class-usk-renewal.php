<?php

defined('ABSPATH') || exit;

class USK_Renewal
{
    public function __construct()
    {
        add_action('template_redirect', [$this, 'handle_renew_link'], 5);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'load_cart_item_from_session'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copy_renew_meta_to_order'], 10, 4);
        add_filter('woocommerce_cart_item_name', [$this, 'cart_item_name'], 10, 3);
    }

    public function handle_renew_link(): void
    {
        if (is_admin() || empty($_GET['usk_renew'])) {
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        $serviceCode = preg_replace('/[^0-9]/', '', (string) ($_GET['usk_service'] ?? ''));
        $planCode = preg_replace('/[^0-9]/', '', (string) ($_GET['usk_plan'] ?? ''));
        $protocol = sanitize_key((string) ($_GET['usk_protocol'] ?? ''));
        $signature = sanitize_text_field((string) ($_GET['usk_sig'] ?? ''));

        if ($serviceCode === '' || $planCode === '' || $protocol === '' || $signature === '') {
            wc_add_notice(usk_wc__('Renewal link is incomplete.'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $panel = $this->resolve_panel_for_renew();
        if (!$panel) {
            wc_add_notice(usk_wc__('No unlimitsky panel is configured for renewal.'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $verify = USK_UnlimitSky_Panel::verify_renew($panel, $serviceCode, $planCode, $protocol, $signature);
        if (empty($verify['ok'])) {
            wc_add_notice(usk_wc__('Renewal link is invalid or expired.'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $productId = self::find_product_id($planCode, $protocol);
        if ($productId <= 0) {
            wc_add_notice(
                sprintf(
                    usk_wc__('No WooCommerce product found for plan %1$s and protocol %2$s.'),
                    $planCode,
                    strtoupper($protocol)
                ),
                'error'
            );
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        WC()->cart->empty_cart();
        $added = WC()->cart->add_to_cart($productId, 1, 0, [], [
            'usk_renew_service_code' => $serviceCode,
            'usk_renew_plan_code'    => $planCode,
            'usk_renew_protocol'     => $protocol,
            'usk_renew_sig'          => $signature,
        ]);

        if (!$added) {
            wc_add_notice(usk_wc__('Could not add renewal product to cart.'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        wc_add_notice(usk_wc__('Renewal plan added to cart. Complete checkout to extend your service.'), 'success');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public function add_cart_item_data(array $cartItemData, int $productId, int $variationId): array
    {
        foreach (['usk_renew_service_code', 'usk_renew_plan_code', 'usk_renew_protocol', 'usk_renew_sig'] as $key) {
            if (!empty($_REQUEST[$key])) {
                $cartItemData[$key] = sanitize_text_field((string) wp_unslash($_REQUEST[$key]));
            }
        }
        return $cartItemData;
    }

    public function load_cart_item_from_session(array $cartItem, array $values): array
    {
        foreach (['usk_renew_service_code', 'usk_renew_plan_code', 'usk_renew_protocol', 'usk_renew_sig'] as $key) {
            if (!empty($values[$key])) {
                $cartItem[$key] = $values[$key];
            }
        }
        return $cartItem;
    }

    public function copy_renew_meta_to_order($item, $cartItemKey, $values, $order): void
    {
        foreach (['usk_renew_service_code', 'usk_renew_plan_code', 'usk_renew_protocol', 'usk_renew_sig'] as $key) {
            if (!empty($values[$key])) {
                $item->add_meta_data('_' . $key, sanitize_text_field((string) $values[$key]), true);
            }
        }
    }

    public function cart_item_name(string $name, array $cartItem, string $cartItemKey): string
    {
        if (!empty($cartItem['usk_renew_service_code'])) {
            $name .= ' <small>(' . esc_html(usk_wc__('Renewal')) . ' #' . esc_html($cartItem['usk_renew_service_code']) . ')</small>';
        }
        return $name;
    }

    public static function find_product_id(string $planCode, string $protocol): int
    {
        $planCode = preg_replace('/[^0-9]/', '', $planCode);
        $protocol = sanitize_key($protocol);
        if ($planCode === '' || $protocol === '') {
            return 0;
        }

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_usk_is_vpn', 'value' => 'yes'],
                ['key' => '_usk_plan_code', 'value' => $planCode],
                ['key' => '_usk_protocol', 'value' => $protocol],
            ],
        ]);

        if (!empty($query->posts[0])) {
            return (int) $query->posts[0];
        }

        return 0;
    }

    private function resolve_panel_for_renew(): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM " . USK_table('panels') . " WHERE type = 'unlimitsky' AND status = 'active' ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        return $row ?: null;
    }
}
