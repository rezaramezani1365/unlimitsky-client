<?php

defined('ABSPATH') || exit;

class USK_Order_Handler
{
    public function __construct()
    {
        add_action('woocommerce_order_status_completed', [$this, 'provision_services'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'provision_services'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'provision_services'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'deprovision_cancelled_order'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'deprovision_cancelled_order'], 10, 1);

        add_action('woocommerce_order_details_after_order_table', [$this, 'render_order_subscriptions'], 10, 1);
        add_action('woocommerce_email_after_order_table', [$this, 'render_email_subscriptions'], 10, 4);

        add_action('init', [$this, 'register_my_account_endpoint']);
        add_filter('woocommerce_account_menu_items', [$this, 'add_my_account_menu']);
        add_action('woocommerce_account_vpn-services_endpoint', [$this, 'render_my_account_services']);
    }

    public function deprovision_cancelled_order(int $order_id): void
    {
        USK_Service_Creator::deprovision_order_services($order_id);
    }

    public function provision_services(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ($order->get_meta('_usk_provisioned') === 'yes') {
            return;
        }

        $panel = USK_Api_Settings::get_connection();
        if (!$panel) {
            return;
        }

        $has_vpn = false;
        $errors  = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product || $product->get_meta('_usk_is_vpn') !== 'yes') {
                continue;
            }

            $has_vpn = true;

            if (wc_get_order_item_meta($item_id, '_usk_provisioned', true) === 'yes') {
                continue;
            }

            $renewServiceCode = wc_get_order_item_meta($item_id, '_usk_renew_service_code', true);
            $renewPlanCode = wc_get_order_item_meta($item_id, '_usk_renew_plan_code', true);
            $renewProtocol = sanitize_key((string) wc_get_order_item_meta($item_id, '_usk_renew_protocol', true));
            $renewSig = (string) wc_get_order_item_meta($item_id, '_usk_renew_sig', true);

            $volume_gb     = (int) $product->get_meta('_usk_volume_gb');
            $duration_days = (int) $product->get_meta('_usk_duration_days');
            $protocol      = sanitize_key((string) $product->get_meta('_usk_protocol'));
            $provision_mode = sanitize_text_field($product->get_meta('_usk_provision_mode') ?: 'native');
            $external_panel_code = preg_replace('/[^0-9]/', '', (string) $product->get_meta('_usk_external_panel_code'));
            $openvpn_proto = sanitize_text_field($product->get_meta('_usk_openvpn_proto') ?: 'tcp');
            $wireguard_transport = sanitize_text_field($product->get_meta('_usk_wireguard_transport') ?: 'tcp');
            $plan_code     = preg_replace('/[^0-9]/', '', (string) $product->get_meta('_usk_plan_code'));
            $node_id       = preg_replace('/[^a-z0-9]/', '', (string) $product->get_meta('_usk_node_id'));

            if ($plan_code === '') {
                $errors[] = sprintf('%s: %s', $item->get_name(), __('پلن محصول تنظیم نشده است.', 'unlimitsky-wc'));
                continue;
            }

            if ($renewServiceCode !== '') {
                $productProtocol = sanitize_key((string) $product->get_meta('_usk_protocol'));
                if ($renewProtocol === '' || $productProtocol === '' || $renewProtocol !== $productProtocol) {
                    $errors[] = sprintf('%s: %s', $item->get_name(), usk_wc__('Renewal protocol does not match the service.'));
                    continue;
                }

                $result = USK_Service_Creator::extend_existing(
                    $panel,
                    $renewServiceCode,
                    $renewPlanCode,
                    $renewProtocol,
                    $renewSig,
                    $order_id
                );

                if (empty($result['success'])) {
                    $errors[] = sprintf('%s: %s', $item->get_name(), $result['error'] ?? usk_wc__('Unknown error'));
                    $order->add_order_note(sprintf('[unlimitsky] Renewal failed for #%s: %s', $renewServiceCode, $result['error'] ?? ''));
                    continue;
                }

                wc_update_order_item_meta($item_id, '_usk_provisioned', 'yes');
                wc_update_order_item_meta($item_id, '_usk_service_code', $renewServiceCode);
                if (!empty($result['portal_url'])) {
                    wc_update_order_item_meta($item_id, '_usk_portal_url', esc_url_raw($result['portal_url']));
                    wc_update_order_item_meta($item_id, '_usk_subscription_url', esc_url_raw($result['portal_url']));
                }

                $order->add_order_note(sprintf('[unlimitsky] Service #%s renewed.', $renewServiceCode));
                continue;
            }

            if ($provision_mode === 'external') {
                if ($external_panel_code === '') {
                    $errors[] = sprintf('%s: %s', $item->get_name(), usk_wc__('External panel (Marzban/Sanaei) not selected for this product.'));
                    continue;
                }
                $protocol = 'xray';
            } elseif ($protocol === '') {
                $errors[] = sprintf('%s: %s', $item->get_name(), __('پروتکل محصول تنظیم نشده است.', 'unlimitsky-wc'));
                continue;
            }

            $username = USK_service_username($order_id, $item_id, USK_generate_code());
            $customer_email = sanitize_email((string) $order->get_billing_email());

            $extCode = ($provision_mode === 'external') ? $external_panel_code : '';
            $result = USK_Service_Creator::create(
                $panel,
                $volume_gb,
                $duration_days,
                $username,
                $protocol,
                $order_id,
                $plan_code,
                $openvpn_proto,
                $wireguard_transport,
                $extCode,
                $customer_email,
                $node_id
            );
            $result = USK_Service_Creator::apply_dns_wrap($result);

            if (empty($result['success'])) {
                $errors[] = sprintf('%s: %s', $item->get_name(), $result['error'] ?? usk_wc__('Unknown error'));
                $order->add_order_note(sprintf('[unlimitsky] %s: %s', $item->get_name(), $result['error'] ?? ''));
                continue;
            }

            $serviceCode = preg_replace('/[^0-9]/', '', (string) ($result['service_code'] ?? ''));
            $portalUrl = trim((string) ($result['portal_url'] ?? ''));
            $subscriptionUrl = $portalUrl !== '' ? $portalUrl : ($result['subscription_url'] ?? '');

            USK_Service_Creator::save_order_record([
                'wc_order_id'               => $order_id,
                'wc_order_item_id'          => $item_id,
                'user_id'                   => $order->get_user_id(),
                'panel_name'                => 'unlimitsky',
                'panel_type'                => $result['panel']['type'] ?? 'unlimitsky',
                'protocol'                  => $result['protocol'] ?? $protocol,
                'volume_gb'                 => $volume_gb,
                'duration_days'             => $duration_days,
                'subscription_url'          => $subscriptionUrl,
                'original_subscription_url' => $result['original_subscription_url'] ?? $subscriptionUrl,
                'config_links'              => $result['config_links'] ?? '',
                'connect_host'              => $result['connect_host'] ?? '',
                'proxy_token'               => $result['proxy_token'] ?? '',
                'service_username'          => $result['username'] ?? $username,
                'service_code'              => $serviceCode !== '' ? $serviceCode : USK_generate_code(),
                'customer_email'            => $customer_email,
                'usage_id'                  => $result['usage_id'] ?? '',
                'xray_email'                => $result['xray_email'] ?? '',
                'price'                     => $item->get_total(),
                'openvpn_proto'             => $result['openvpn_proto'] ?? ($protocol === 'openvpn' ? $openvpn_proto : ''),
                'wireguard_transport'       => $result['wireguard_transport'] ?? ($protocol === 'wireguard' ? $wireguard_transport : ''),
                'qr_png'                    => $result['qr_png'] ?? '',
                'vpn_uri'                   => $result['vpn_uri'] ?? '',
                'download_url'              => $result['download_url'] ?? '',
                'portal_url'                => $portalUrl,
                'conf_filename'             => $result['conf_filename'] ?? '',
                'expires_at'                => $result['expires_at'] ?? null,
            ]);

            wc_update_order_item_meta($item_id, '_usk_provisioned', 'yes');
            wc_update_order_item_meta($item_id, '_usk_subscription_url', $subscriptionUrl);
            wc_update_order_item_meta($item_id, '_usk_config_links', $result['config_links'] ?? '');
            wc_update_order_item_meta($item_id, '_usk_service_code', $serviceCode);
            if ($portalUrl !== '') {
                wc_update_order_item_meta($item_id, '_usk_portal_url', esc_url_raw($portalUrl));
            }
            if (!empty($result['qr_png'])) {
                wc_update_order_item_meta($item_id, '_usk_qr_png', $result['qr_png']);
            }

            $order->add_order_note(sprintf('[unlimitsky] Service "%s" created. Portal: %s', $item->get_name(), $portalUrl !== '' ? $portalUrl : $subscriptionUrl));
        }

        if ($has_vpn) {
            $order->update_meta_data('_usk_provisioned', empty($errors) ? 'yes' : 'partial');
            $order->save();

            if (!empty($errors)) {
                $order->add_order_note('[unlimitsky] Some services failed: ' . implode(' | ', $errors));
            }
        }
    }

    public function render_order_subscriptions(WC_Order $order): void
    {
        $services = USK_Service_Creator::get_by_order($order->get_id());
        if (empty($services)) {
            return;
        }

        echo '<section class="UnlimitSky-order-services">';
        echo '<h2>' . esc_html(usk_wc__('VPN Services')) . '</h2>';
        foreach ($services as $service) {
            USK_Order_Display::render_service_block($service, false);
        }
        echo '</section>';
    }

    public function render_email_subscriptions(WC_Order $order, bool $sent_to_admin, bool $plain_text, $email): void
    {
        if ($sent_to_admin) {
            return;
        }

        $services = USK_Service_Creator::get_by_order($order->get_id());
        if (empty($services)) {
            return;
        }

        if ($plain_text) {
            echo "\n" . usk_wc__('VPN Services') . "\n";
            foreach ($services as $service) {
                USK_Order_Display::render_service_block($service, true);
            }
            return;
        }

        echo '<h2>' . esc_html(usk_wc__('VPN Services')) . '</h2>';
        foreach ($services as $service) {
            USK_Order_Display::render_service_block($service, false);
        }
    }

    public function register_my_account_endpoint(): void
    {
        add_rewrite_endpoint('vpn-services', EP_ROOT | EP_PAGES);
    }

    public function add_my_account_menu(array $items): array
    {
        $new = [];
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'orders') {
                $new['vpn-services'] = usk_wc__('My VPN Services');
            }
        }
        return $new;
    }

    public function render_my_account_services(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $services = USK_Service_Creator::get_by_user(get_current_user_id());

        echo '<h3>' . esc_html(usk_wc__('My VPN Services')) . '</h3>';

        if (empty($services)) {
            echo '<p>' . esc_html(usk_wc__('You have not purchased any VPN service yet.')) . '</p>';
            return;
        }

        foreach ($services as $service) {
            echo '<div style="margin-bottom:8px;"><small>' . esc_html(usk_wc__('Code')) . ': ' . esc_html($service['service_code']) . ' — ' . esc_html($service['created_at']) . '</small></div>';
            USK_Order_Display::render_service_block($service, false);
        }
    }
}
