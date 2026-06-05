<?php

defined('ABSPATH') || exit;

/**
 * Marzban panel API – ported from UnlimitSky config.php
 */
class USK_Marzban
{
    public static function login(string $address, string $username, string $password): ?array
    {
        $curl = curl_init(rtrim($address, '/') . '/api/admin/token');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['username' => $username, 'password' => $password]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    public static function create_user(
        string $username,
        int $limit_bytes,
        int $expire_timestamp,
        array $proxies,
        $inbounds,
        string $token,
        string $url
    ): ?array {
        $payload = [
            'proxies'                   => $proxies,
            'expire'                    => $expire_timestamp,
            'data_limit'                => $limit_bytes,
            'username'                  => $username,
            'data_limit_reset_strategy' => 'no_reset',
        ];

        if ($inbounds !== 'null' && !empty($inbounds)) {
            $payload['inbounds'] = $inbounds;
        }

        $curl = curl_init(rtrim($url, '/') . '/api/user');
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => wp_json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    public static function build_proxies(array $protocols, string $flow_setting): array
    {
        $proxies = [];
        foreach ($protocols as $protocol) {
            if ($protocol === 'vless' && $flow_setting === 'flowon') {
                $proxies[$protocol] = ['flow' => 'xtls-rprx-vision'];
            } else {
                $proxies[$protocol] = [];
            }
        }
        return $proxies;
    }

    public static function build_inbounds(array $protocols, array $inbound_rows): array
    {
        $inbounds = [];
        foreach ($protocols as $protocol) {
            foreach ($inbound_rows as $row) {
                $inbounds[$protocol][] = $row['inbound'];
            }
        }
        return $inbounds;
    }

    public static function get_subscription_url(array $create_result, string $panel_url): string
    {
        $sub = $create_result['subscription_url'] ?? '';
        if (strpos($sub, 'http') !== false) {
            return $sub;
        }
        return rtrim($panel_url, '/') . $sub;
    }

    public static function extract_links(array $create_result): string
    {
        $links = '';
        if (!empty($create_result['links']) && is_array($create_result['links'])) {
            foreach ($create_result['links'] as $link) {
                $links .= $link . "\n\n";
            }
        }
        return trim($links);
    }
}
