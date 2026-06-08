<?php

defined('ABSPATH') || exit;

class USK_UnlimitSky_Panel
{
    public static function normalize_api_url(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            return '';
        }
        $base = rtrim($base, '/');
        if (substr($base, -7) === 'v1.php') {
            return $base;
        }
        if (substr($base, -8) === '/api/v1') {
            return $base . '.php';
        }
        if (substr($base, -4) === '/api') {
            return $base . '/v1.php';
        }
        return $base . '/api/v1.php';
    }

    /**
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function request(string $api_url, string $api_key, string $action, array $body = [], string $method = 'GET'): array
    {
        $url = self::normalize_api_url($api_url);
        if ($url === '') {
            return ['ok' => false, 'error' => __('آدرس API پنل unlimitsky خالی است.', 'unlimitsky-wc')];
        }

        $url = add_query_arg('action', $action, $url);
        $args = [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
            'sslverify' => false,
        ];

        if ($method === 'POST') {
            $args['method'] = 'POST';
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return ['ok' => false, 'error' => __('پاسخ نامعتبر از پنل unlimitsky.', 'unlimitsky-wc')];
        }

        if ($code >= 400 || empty($data['ok'])) {
            return ['ok' => false, 'error' => $data['error'] ?? __('خطا در API پنل unlimitsky', 'unlimitsky-wc')];
        }

        return ['ok' => true, 'data' => $data];
    }

    public static function test_connection(string $api_url, string $api_key): bool
    {
        $health = self::request($api_url, '', 'health');
        if (empty($health['ok'])) {
            return false;
        }

        $auth = self::request($api_url, $api_key, 'protocols');
        return !empty($auth['ok']);
    }

    /**
     * @return array<int, array{code:string,name:string,type:string,protocols?:string,protocol_label?:string}>
     */
    public static function list_external_panels(string $api_url, string $api_key): array
    {
        $result = self::request($api_url, $api_key, 'panels');
        if (empty($result['ok'])) {
            return [];
        }
        $panels = $result['data']['panels'] ?? [];
        return is_array($panels) ? $panels : [];
    }

    /**
     * @return array<int, array{code:string,name:string,volume_gb:int,duration_days:int,price?:string,status?:string}>
     */
    public static function list_plans(string $api_url, string $api_key): array
    {
        $result = self::request($api_url, $api_key, 'plans');
        if (empty($result['ok'])) {
            return [];
        }
        $plans = $result['data']['plans'] ?? [];
        return is_array($plans) ? $plans : [];
    }

    /**
     * @return array{success:bool, subscription_url?:string, config_links?:string, username?:string, error?:string}
     */
    public static function create_service(array $panel, int $volume_gb, int $duration_days, string $username, string $protocol = '', int $wc_order_id = 0, string $plan_code = '', string $openvpn_proto = 'tcp', string $wireguard_transport = 'tcp', string $external_panel_code = '', string $customer_email = ''): array
    {
        $api_key = $panel['token'] ?? '';
        if ($api_key === '') {
            return ['success' => false, 'error' => __('کلید API پنل unlimitsky تنظیم نشده.', 'unlimitsky-wc')];
        }

        $external_panel_code = preg_replace('/[^0-9]/', '', $external_panel_code);

        if ($external_panel_code !== '') {
            $payload = [
                'panel_code'    => $external_panel_code,
                'volume_gb'     => $volume_gb,
                'duration_days' => $duration_days,
                'username'      => $username,
                'wc_order_id'   => $wc_order_id,
            ];
            $customer_email = sanitize_email($customer_email);
            if ($customer_email !== '') {
                $payload['customer_email'] = $customer_email;
            }
            if ($plan_code !== '') {
                $payload['plan_code'] = $plan_code;
            }

            $result = self::request($panel['login_link'], $api_key, 'create-service', $payload, 'POST');
            if (empty($result['ok'])) {
                return ['success' => false, 'error' => $result['error'] ?? __('خطا در ساخت سرویس روی پنل خارجی', 'unlimitsky-wc')];
            }

            $data = $result['data'];
            $config = $data['config_links'] ?? ($data['config'] ?? '');
            $sub = $data['subscription_url'] ?? $config;

            return [
                'success'          => true,
                'subscription_url' => $sub,
                'config_links'     => $config,
                'username'         => $data['username'] ?? $username,
                'panel'            => array_merge($panel, [
                    'type' => $data['panel_type'] ?? 'marzban',
                    'name' => $data['panel_name'] ?? $panel['name'],
                ]),
                'protocol'         => $data['protocol'] ?? 'xray',
                'panel_code'       => $data['panel_code'] ?? $external_panel_code,
                'service_code'     => $data['service_code'] ?? '',
            ];
        }

        $protocol = $protocol !== '' ? $protocol : trim(str_replace('|', '', $panel['protocols'] ?? 'wireguard'));
        if ($protocol === '') {
            $protocol = 'wireguard';
        }

        $payload = [
            'protocol'      => $protocol,
            'volume_gb'     => $volume_gb,
            'duration_days' => $duration_days,
            'username'      => $username,
            'wc_order_id'   => $wc_order_id,
        ];
        $customer_email = sanitize_email($customer_email);
        if ($customer_email !== '') {
            $payload['customer_email'] = $customer_email;
        }

        if (!empty($panel['backend_ip'])) {
            $payload['server_ip'] = $panel['backend_ip'];
        }
        if ($plan_code !== '') {
            $payload['plan_code'] = $plan_code;
        }
        if ($protocol === 'openvpn') {
            $openvpn_proto = strtolower($openvpn_proto);
            $payload['openvpn_proto'] = in_array($openvpn_proto, ['udp', 'tcp'], true) ? $openvpn_proto : 'tcp';
        }
        if ($protocol === 'wireguard') {
            $wireguard_transport = strtolower($wireguard_transport);
            $payload['wireguard_transport'] = in_array($wireguard_transport, ['udp', 'tcp'], true) ? $wireguard_transport : 'tcp';
        }

        $result = self::request($panel['login_link'], $api_key, 'create-service', $payload, 'POST');
        if (empty($result['ok'])) {
            return ['success' => false, 'error' => $result['error'] ?? __('خطا در ساخت سرویس', 'unlimitsky-wc')];
        }

        $data = $result['data'];
        $portalUrl = trim((string) ($data['portal_url'] ?? ''));
        $downloadUrl = $data['download_url'] ?? '';
        $config = $data['config_links'] ?? ($data['config'] ?? '');
        $vpnUri = trim((string) ($data['vpn_uri'] ?? ''));
        $protocolResolved = $data['protocol'] ?? $protocol;
        if ($portalUrl !== '') {
            $sub = $portalUrl;
        } elseif ($protocolResolved === 'amnezia' && $vpnUri !== '') {
            $sub = $vpnUri;
        } else {
            $sub = $downloadUrl !== '' ? $downloadUrl : ($data['subscription_url'] ?? $config);
        }

        return [
            'success'          => true,
            'subscription_url' => $sub,
            'portal_url'       => $portalUrl,
            'config_links'     => $config,
            'download_url'     => $downloadUrl,
            'ovpn_filename'    => $data['ovpn_filename'] ?? ($data['conf_filename'] ?? ($username . ($protocolResolved === 'amnezia' ? '.conf' : '.ovpn'))),
            'conf_filename'    => $data['conf_filename'] ?? '',
            'openvpn_proto'       => $data['openvpn_proto'] ?? '',
            'wireguard_transport' => $data['wireguard_transport'] ?? '',
            'tcp_client_cmd'      => $data['tcp_client_cmd'] ?? '',
            'username'         => $data['username'] ?? $username,
            'customer_email'   => $data['customer_email'] ?? $customer_email,
            'usage_id'         => $data['usage_id'] ?? '',
            'xray_email'       => $data['xray_email'] ?? '',
            'panel'            => $panel,
            'protocol'         => $data['protocol'] ?? $protocol,
            'qr_png'           => $data['qr_png'] ?? '',
            'vpn_uri'          => $data['vpn_uri'] ?? '',
            'wg_conf'          => $data['wg_conf'] ?? '',
            'expires_at'       => $data['expires_at'] ?? null,
        ];
    }

    /**
     * @return array{ok:bool,error?:string,service_code?:string,protocol?:string}
     */
    public static function verify_renew(array $panel, string $serviceCode, string $planCode, string $protocol, string $signature): array
    {
        $result = self::request($panel['login_link'], $panel['token'] ?? '', 'verify-renew', [
            'service_code' => preg_replace('/[^0-9]/', '', $serviceCode),
            'plan_code'    => preg_replace('/[^0-9]/', '', $planCode),
            'protocol'     => sanitize_key($protocol),
            'renew_sig'    => $signature,
        ], 'POST');

        if (empty($result['ok'])) {
            return ['ok' => false, 'error' => $result['error'] ?? 'verify_failed'];
        }

        return ['ok' => true, 'data' => $result['data'] ?? []];
    }

    /**
     * @return array{success:bool,error?:string,subscription_url?:string,portal_url?:string,service_code?:string,protocol?:string,expires_at?:string,volume_gb?:int,duration_days?:int}
     */
    public static function extend_service(array $panel, string $serviceCode, string $planCode, string $protocol, string $signature, int $wcOrderId = 0): array
    {
        $result = self::request($panel['login_link'], $panel['token'] ?? '', 'extend-service', [
            'service_code' => preg_replace('/[^0-9]/', '', $serviceCode),
            'plan_code'    => preg_replace('/[^0-9]/', '', $planCode),
            'protocol'     => sanitize_key($protocol),
            'renew_sig'    => $signature,
            'wc_order_id'  => $wcOrderId,
        ], 'POST');

        if (empty($result['ok'])) {
            return ['success' => false, 'error' => $result['error'] ?? __('Renewal failed on panel.', 'unlimitsky-wc')];
        }

        $data = $result['data'] ?? [];
        $portalUrl = trim((string) ($data['portal_url'] ?? ''));

        return [
            'success'          => true,
            'service_code'     => (string) ($data['service_code'] ?? $serviceCode),
            'protocol'         => (string) ($data['protocol'] ?? $protocol),
            'subscription_url' => $portalUrl !== '' ? $portalUrl : '',
            'portal_url'       => $portalUrl,
            'expires_at'       => $data['expires_at'] ?? null,
            'volume_gb'        => isset($data['volume_gb']) ? (int) $data['volume_gb'] : null,
            'duration_days'    => isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            'extra_days'       => isset($data['extra_days']) ? (int) $data['extra_days'] : 0,
            'extra_gb'         => isset($data['extra_gb']) ? (int) $data['extra_gb'] : 0,
        ];
    }
}
