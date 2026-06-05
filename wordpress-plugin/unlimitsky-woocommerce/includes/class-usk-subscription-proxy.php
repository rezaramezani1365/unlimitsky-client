<?php

defined('ABSPATH') || exit;

class USK_Subscription_Proxy
{
    public function __construct()
    {
        add_action('init', [$this, 'register_rewrite']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve_subscription']);
    }

    public function register_rewrite(): void
    {
        add_rewrite_rule('^unlimitsky-sub/([^/]+)/?$', 'index.php?USK_sub_token=$matches[1]', 'top');
    }

    public static function flush_rules(): void
    {
        $instance = new self();
        $instance->register_rewrite();
        flush_rewrite_rules();
    }

    public function register_query_vars(array $vars): array
    {
        $vars[] = 'USK_sub_token';
        return $vars;
    }

    public function maybe_serve_subscription(): void
    {
        $token = get_query_var('USK_sub_token');
        if (empty($token)) {
            return;
        }

        $service = self::get_service_by_token(sanitize_text_field(wp_unslash($token)));
        if (!$service) {
            status_header(404);
            exit('Subscription not found');
        }

        $body = self::fetch_upstream($service['original_subscription_url'] ?: $service['subscription_url']);
        if ($body === null) {
            status_header(502);
            exit('Upstream unavailable');
        }

        $panel = USK_Panel_Manager::get_panel_by_name($service['panel_name']);
        if (!$panel) {
            status_header(500);
            exit('Panel not configured');
        }

        $connect_host = USK_Dns_Settings::connect_host();
        $output       = USK_Config_Rewriter::rewrite_to_connect_domain($body, $connect_host, $panel);

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Profile-User-Agent: unlimitsky-wc/1.0');
        echo $output;
        exit;
    }

    public static function get_service_by_token(string $token): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . USK_table('orders') . ' WHERE proxy_token = %s AND status = %s LIMIT 1',
                $token,
                'active'
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private static function fetch_upstream(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $response = wp_remote_get($url, [
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'UnlimitSkyWC/1.0'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * @return array{subscription_url:string, config_links:string, connect_host:string, proxy_token:string, original_subscription_url:string}
     */
    public static function wrap_service_urls(array $panel, string $original_sub, string $config_links): array
    {
        if (!USK_Dns_Settings::is_enabled()) {
            return [
                'subscription_url'           => $original_sub,
                'config_links'             => $config_links,
                'connect_host'             => '',
                'proxy_token'              => '',
                'original_subscription_url'=> $original_sub,
            ];
        }

        $connect_host = USK_Dns_Settings::connect_host();
        $proxy_token  = wp_generate_password(32, false, false);

        $rewritten_links = USK_Config_Rewriter::rewrite_to_connect_domain($config_links, $connect_host, $panel);

        return [
            'subscription_url'            => USK_Dns_Settings::subscription_public_url($proxy_token),
            'config_links'                => $rewritten_links,
            'connect_host'                => $connect_host,
            'proxy_token'                 => $proxy_token,
            'original_subscription_url'   => $original_sub,
        ];
    }
}
