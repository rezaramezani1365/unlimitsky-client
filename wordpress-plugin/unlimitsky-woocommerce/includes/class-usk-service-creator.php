<?php

defined('ABSPATH') || exit;

class USK_Service_Creator
{
    /**
     * @return array{success:bool, subscription_url?:string, config_links?:string, username?:string, error?:string}
     */
    public static function create(array $panel, int $volume_gb, int $duration_days, string $service_username, string $protocol = '', int $wc_order_id = 0, string $plan_code = '', string $openvpn_proto = 'tcp', string $wireguard_transport = 'tcp', string $external_panel_code = '', string $customer_email = '', string $node_id = ''): array
    {
        if (!empty($node_id)) {
            $panel['provision_node_id'] = preg_replace('/[^a-z0-9]/', '', $node_id);
        }

        return USK_UnlimitSky_Panel::create_service(
            $panel,
            $volume_gb,
            $duration_days,
            $service_username,
            $protocol,
            $wc_order_id,
            $plan_code,
            $openvpn_proto,
            $wireguard_transport,
            $external_panel_code,
            $customer_email
        );
    }

    public static function apply_dns_wrap(array $result): array
    {
        if (empty($result['success'])) {
            return $result;
        }

        if (USK_Dns_Settings::is_enabled()) {
            $connect_host = USK_Dns_Settings::connect_host();
            $panel = $result['panel'] ?? USK_Api_Settings::get_connection() ?? [];
            $links = USK_Config_Rewriter::rewrite_to_connect_domain(
                $result['config_links'] ?? '',
                $connect_host,
                $panel
            );
            $result['config_links'] = $links;
            if (!empty($result['portal_url'])) {
                // Portal URL stays on client panel — do not rewrite.
            } else {
                $result['subscription_url'] = $links ?: ($result['subscription_url'] ?? '');
            }
            $result['connect_host'] = $connect_host;
        }

        return $result;
    }

    public static function save_order_record(array $data): int
    {
        global $wpdb;

        $sub = $data['subscription_url'] ?? '';
        $orig = $data['original_subscription_url'] ?? $sub;
        $sub_stored = filter_var($sub, FILTER_VALIDATE_URL) ? esc_url_raw($sub) : sanitize_textarea_field($sub);
        $orig_stored = filter_var($orig, FILTER_VALIDATE_URL) ? esc_url_raw($orig) : sanitize_textarea_field($orig);

        $wpdb->insert(USK_table('orders'), [
            'wc_order_id'                 => (int) $data['wc_order_id'],
            'wc_order_item_id'            => (int) $data['wc_order_item_id'],
            'user_id'                     => (int) $data['user_id'],
            'panel_name'                  => sanitize_text_field($data['panel_name']),
            'panel_type'                  => sanitize_text_field($data['panel_type']),
            'volume_gb'                   => (int) $data['volume_gb'],
            'duration_days'               => (int) $data['duration_days'],
            'subscription_url'            => $sub_stored,
            'original_subscription_url'   => $orig_stored,
            'config_links'                => wp_kses_post($data['config_links'] ?? ''),
            'connect_host'                => sanitize_text_field($data['connect_host'] ?? ''),
            'proxy_token'                 => sanitize_text_field($data['proxy_token'] ?? ''),
            'service_username'            => sanitize_text_field($data['service_username']),
            'service_code'                => sanitize_text_field($data['service_code']),
            'price'                       => (float) $data['price'],
            'protocol'                    => sanitize_text_field($data['protocol'] ?? ''),
            'openvpn_proto'               => sanitize_text_field($data['openvpn_proto'] ?? ''),
            'qr_png'                      => sanitize_textarea_field($data['qr_png'] ?? ''),
            'vpn_uri'                     => sanitize_textarea_field($data['vpn_uri'] ?? ''),
            'download_url'                => esc_url_raw($data['download_url'] ?? ''),
            'portal_url'                  => esc_url_raw($data['portal_url'] ?? ''),
            'conf_filename'               => sanitize_text_field($data['conf_filename'] ?? ''),
            'expires_at'                  => !empty($data['expires_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['expires_at'])) : null,
            'status'                      => 'active',
        ]);

        return (int) $wpdb->insert_id;
    }

    public static function deprovision_order_services(int $order_id): void
    {
        global $wpdb;

        $services = self::get_by_order($order_id);
        if ($services === []) {
            return;
        }

        $panel = USK_Api_Settings::get_connection();
        if (!$panel) {
            return;
        }

        foreach ($services as $service) {
            if (($service['status'] ?? '') === 'cancelled') {
                continue;
            }
            if (($service['panel_type'] ?? '') !== 'unlimitsky') {
                continue;
            }

            $serviceCode = preg_replace('/[^0-9]/', '', (string) ($service['service_code'] ?? ''));
            if ($serviceCode === '') {
                continue;
            }

            $result = USK_UnlimitSky_Panel::delete_service($panel, $serviceCode);
            $wpdb->update(
                USK_table('orders'),
                ['status' => 'cancelled'],
                ['id' => (int) $service['id']],
                ['%s'],
                ['%d']
            );

            $order = wc_get_order($order_id);
            if ($order) {
                $note = empty($result['success'])
                    ? sprintf('[unlimitsky] Deprovision failed for #%s: %s', $serviceCode, $result['error'] ?? '')
                    : sprintf('[unlimitsky] Service #%s removed from server.', $serviceCode);
                $order->add_order_note($note);
            }
        }
    }

    public static function get_by_order(int $order_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . USK_table('orders') . ' WHERE wc_order_id = %d', $order_id),
            ARRAY_A
        ) ?: [];
    }

    public static function get_by_user(int $user_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . USK_table('orders') . ' WHERE user_id = %d ORDER BY created_at DESC', $user_id),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return array{success:bool,error?:string,portal_url?:string,service_code?:string,expires_at?:string,volume_gb?:int,duration_days?:int}
     */
    public static function extend_existing(array $panel, string $serviceCode, string $planCode, string $protocol, string $renewSig, int $wcOrderId): array
    {
        $result = USK_UnlimitSky_Panel::extend_service($panel, $serviceCode, $planCode, $protocol, $renewSig, $wcOrderId);
        if (empty($result['success'])) {
            return $result;
        }

        self::update_renewed_record($serviceCode, $result, $wcOrderId);
        return $result;
    }

    public static function update_renewed_record(string $serviceCode, array $result, int $wcOrderId): void
    {
        global $wpdb;

        $serviceCode = preg_replace('/[^0-9]/', '', $serviceCode);
        if ($serviceCode === '') {
            return;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . USK_table('orders') . ' WHERE service_code = %s ORDER BY id DESC LIMIT 1', $serviceCode),
            ARRAY_A
        );
        if (!$row) {
            return;
        }

        $updates = [];
        if (!empty($result['portal_url'])) {
            $updates['subscription_url'] = esc_url_raw($result['portal_url']);
            $updates['portal_url'] = esc_url_raw($result['portal_url']);
        }
        if (isset($result['volume_gb']) && $result['volume_gb'] !== null) {
            $updates['volume_gb'] = (int) $result['volume_gb'];
        }
        if (isset($result['duration_days']) && $result['duration_days'] !== null) {
            $updates['duration_days'] = (int) $result['duration_days'];
        }
        if (!empty($result['expires_at'])) {
            $updates['expires_at'] = gmdate('Y-m-d H:i:s', strtotime($result['expires_at']));
        }
        $updates['status'] = 'active';

        if ($updates === []) {
            return;
        }

        $wpdb->update(USK_table('orders'), $updates, ['id' => (int) $row['id']]);
    }
}
