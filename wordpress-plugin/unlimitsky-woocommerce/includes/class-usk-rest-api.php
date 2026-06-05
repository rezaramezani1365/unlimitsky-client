<?php

defined('ABSPATH') || exit;

class USK_Rest_Api
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('UnlimitSky/v1', '/ip', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_default_ip'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('UnlimitSky/v1', '/ip/(?P<panel_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_panel_ip'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_default_ip(WP_REST_Request $request): WP_REST_Response
    {
        $settings = USK_Dns_Settings::get();
        return new WP_REST_Response([
            'connect_host' => USK_Dns_Settings::connect_host(),
            'backend_ip'   => $settings['default_backend_ip'],
        ], 200);
    }

    public function get_panel_ip(WP_REST_Request $request): WP_REST_Response
    {
        $panel = USK_Panel_Manager::get_panel((int) $request['panel_id']);
        if (!$panel) {
            return new WP_REST_Response(['error' => 'panel not found'], 404);
        }

        return new WP_REST_Response([
            'connect_host' => USK_Dns_Settings::connect_host(),
            'backend_ip'   => USK_Dns_Settings::backend_ip_for_panel($panel),
            'panel_name'   => $panel['name'],
        ], 200);
    }
}
