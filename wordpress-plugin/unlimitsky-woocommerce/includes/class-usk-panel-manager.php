<?php

defined('ABSPATH') || exit;

class USK_Panel_Manager
{
    public static function get_panels(bool $active_only = true): array
    {
        global $wpdb;
        $table = USK_table('panels');
        $where = $active_only ? "WHERE status = 'active'" : '';
        return $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY name ASC", ARRAY_A) ?: [];
    }

    public static function get_panel(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . USK_table('panels') . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_panel_by_code(string $code): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . USK_table('panels') . ' WHERE code = %s', $code),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_panel_by_name(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . USK_table('panels') . ' WHERE name = %s LIMIT 1', $name),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function save_panel(array $data): int
    {
        global $wpdb;
        $table = USK_table('panels');

        $fields = [
            'name'         => sanitize_text_field($data['name'] ?? ''),
            'login_link'   => esc_url_raw($data['login_link'] ?? ''),
            'username'     => sanitize_text_field($data['username'] ?? ''),
            'password'     => sanitize_text_field($data['password'] ?? ''),
            'token'        => sanitize_textarea_field($data['token'] ?? ''),
            'type'         => sanitize_text_field($data['type'] ?? 'marzban'),
            'protocols'    => sanitize_text_field($data['protocols'] ?? 'vless|'),
            'flow'         => sanitize_text_field($data['flow'] ?? 'flowon'),
            'qr_code'      => sanitize_text_field($data['qr_code'] ?? 'active'),
            'status'       => sanitize_text_field($data['status'] ?? 'active'),
            'backend_ip'   => sanitize_text_field($data['backend_ip'] ?? ''),
            'backend_host' => sanitize_text_field($data['backend_host'] ?? ''),
            'provision_node_id' => preg_replace('/[^a-z0-9]/', '', (string) ($data['provision_node_id'] ?? '')),
        ];

        if (!empty($data['id'])) {
            $wpdb->update($table, $fields, ['id' => (int) $data['id']]);
            return (int) $data['id'];
        }

        $fields['code'] = USK_generate_panel_code();
        $wpdb->insert($table, $fields);
        return (int) $wpdb->insert_id;
    }

    public static function delete_panel(int $id): bool
    {
        global $wpdb;
        $panel = self::get_panel($id);
        if (!$panel) {
            return false;
        }

        $wpdb->delete(USK_table('panels'), ['id' => $id]);
        $wpdb->delete(USK_table('sanayi_settings'), ['panel_code' => $panel['code']]);
        $wpdb->delete(USK_table('marzban_inbounds'), ['panel_code' => $panel['code']]);
        return true;
    }

    public static function get_sanayi_setting(string $panel_code): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . USK_table('sanayi_settings') . ' WHERE panel_code = %s LIMIT 1', $panel_code),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function save_sanayi_setting(string $panel_code, string $inbound_id, string $example_link, string $flow = ''): void
    {
        global $wpdb;
        $table = USK_table('sanayi_settings');
        $existing = self::get_sanayi_setting($panel_code);

        $data = [
            'panel_code'   => $panel_code,
            'inbound_id'   => sanitize_text_field($inbound_id),
            'example_link' => sanitize_textarea_field($example_link),
            'flow'         => sanitize_text_field($flow),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing['id']]);
        } else {
            $wpdb->insert($table, $data);
        }
    }

    public static function get_marzban_inbounds(string $panel_code): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . USK_table('marzban_inbounds') . " WHERE panel_code = %s AND status = 'active'",
                $panel_code
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function save_marzban_inbounds(string $panel_code, array $inbounds): void
    {
        global $wpdb;
        $table = USK_table('marzban_inbounds');
        $wpdb->delete($table, ['panel_code' => $panel_code]);

        foreach ($inbounds as $inbound) {
            $inbound = trim(sanitize_text_field($inbound));
            if ($inbound === '') {
                continue;
            }
            $wpdb->insert($table, [
                'panel_code' => $panel_code,
                'inbound'    => $inbound,
                'status'     => 'active',
            ]);
        }
    }

    public static function login_sanayi(string $address, string $username, string $password): ?string
    {
        $cookie_file = sys_get_temp_dir() . '/USK_sanayi_cookie_' . md5($address . $username) . '.txt';
        $ch = curl_init(rtrim($address, '/') . '/login');
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_POST             => true,
            CURLOPT_POSTFIELDS       => http_build_query(['username' => $username, 'password' => $password]),
            CURLOPT_COOKIEJAR        => $cookie_file,
            CURLOPT_COOKIEFILE       => $cookie_file,
            CURLOPT_SSL_VERIFYPEER   => false,
            CURLOPT_SSL_VERIFYHOST   => false,
            CURLOPT_TIMEOUT          => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false || !file_exists($cookie_file)) {
            return null;
        }

        $cookies = file_get_contents($cookie_file);
        if (preg_match('/session\s+([^\s]+)/', $cookies, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function refresh_panel_token(array $panel): array
    {
        if ($panel['type'] === 'marzban') {
            $login = USK_Marzban::login($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($login['access_token'])) {
                global $wpdb;
                $wpdb->update(USK_table('panels'), ['token' => $login['access_token']], ['id' => $panel['id']]);
                $panel['token'] = $login['access_token'];
            }
        } elseif ($panel['type'] === 'sanayi') {
            $session = self::login_sanayi($panel['login_link'], $panel['username'], $panel['password']);
            if ($session) {
                global $wpdb;
                $wpdb->update(USK_table('panels'), ['token' => $session], ['id' => $panel['id']]);
                $panel['token'] = $session;
            }
        } elseif ($panel['type'] === 'unlimitsky') {
            // API key stored in token field — no refresh needed
        }

        return $panel;
    }
}
