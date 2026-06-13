<?php

defined('ABSPATH') || exit;

class USK_Api_Settings
{
    private const OPTION_KEY = 'usk_api_settings';

    public static function defaults(): array
    {
        return [
            'api_url' => '',
            'api_key' => '',
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return wp_parse_args($stored, self::defaults());
    }

    public static function save(array $data): void
    {
        update_option(self::OPTION_KEY, [
            'api_url' => esc_url_raw(trim((string) ($data['api_url'] ?? ''))),
            'api_key' => sanitize_textarea_field(trim((string) ($data['api_key'] ?? ''))),
        ]);
    }

    public static function is_configured(): bool
    {
        $cfg = self::get();
        return trim($cfg['api_url']) !== '' && trim($cfg['api_key']) !== '';
    }

    public static function site_url(): string
    {
        return untrailingslashit(home_url());
    }

    /**
     * Virtual panel array for API-only provisioning.
     *
     * @return array<string,mixed>|null
     */
    public static function get_connection(): ?array
    {
        if (!self::is_configured()) {
            return null;
        }

        $cfg = self::get();
        return [
            'id'                => 0,
            'name'              => 'unlimitsky',
            'type'              => 'unlimitsky',
            'login_link'        => $cfg['api_url'],
            'token'             => $cfg['api_key'],
            'protocols'         => '',
            'status'            => 'active',
            'backend_ip'        => '',
            'provision_node_id' => '',
        ];
    }

    /**
     * Migrate legacy single-panel rows into wp_options on first load.
     */
    public static function maybe_migrate_from_panels(): void
    {
        if (self::is_configured()) {
            return;
        }

        global $wpdb;
        $table = USK_table('panels');
        $row = $wpdb->get_row(
            "SELECT * FROM {$table} WHERE type = 'unlimitsky' AND status = 'active' ORDER BY id ASC LIMIT 1",
            ARRAY_A
        );
        if (!$row || empty($row['login_link']) || empty($row['token'])) {
            return;
        }

        self::save([
            'api_url' => $row['login_link'],
            'api_key' => $row['token'],
        ]);
    }
}
