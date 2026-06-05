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
            return ['ok' => false, 'error' => __('آدرس API پنل UnlimitSky خالی است.', 'unlimitsky-wc')];
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
            return ['ok' => false, 'error' => __('پاسخ نامعتبر از پنل UnlimitSky.', 'unlimitsky-wc')];
        }

        if ($code >= 400 || empty($data['ok'])) {
            return ['ok' => false, 'error' => $data['error'] ?? __('خطا در API پنل UnlimitSky', 'unlimitsky-wc')];
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
     * @return array{success:bool, subscription_url?:string, config_links?:string, username?:string, error?:string}
     */
    public static function create_service(array $panel, int $volume_gb, int $duration_days, string $username, string $protocol = '', int $wc_order_id = 0, string $plan_code = ''): array
    {
        $api_key = $panel['token'] ?? '';
        if ($api_key === '') {
            return ['success' => false, 'error' => __('کلید API پنل UnlimitSky تنظیم نشده.', 'unlimitsky-wc')];
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

        if (!empty($panel['backend_ip'])) {
            $payload['server_ip'] = $panel['backend_ip'];
        }
        if ($plan_code !== '') {
            $payload['plan_code'] = $plan_code;
        }

        $result = self::request($panel['login_link'], $api_key, 'create-service', $payload, 'POST');
        if (empty($result['ok'])) {
            return ['success' => false, 'error' => $result['error'] ?? __('خطا در ساخت سرویس', 'unlimitsky-wc')];
        }

        $data = $result['data'];
        $config = $data['config_links'] ?? ($data['config'] ?? '');
        $sub    = $data['subscription_url'] ?? $config;

        return [
            'success'          => true,
            'subscription_url' => $sub,
            'config_links'     => $config,
            'username'         => $data['username'] ?? $username,
            'panel'            => $panel,
            'protocol'         => $data['protocol'] ?? $protocol,
            'qr_png'           => $data['qr_png'] ?? '',
            'expires_at'       => $data['expires_at'] ?? null,
        ];
    }
}
