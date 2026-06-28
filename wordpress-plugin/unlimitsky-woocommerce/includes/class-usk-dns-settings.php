<?php

defined('ABSPATH') || exit;

class USK_Dns_Settings
{
    private const OPTION_KEY = 'USK_dns_settings';

    public static function defaults(): array
    {
        return [
            'enabled'                => 'yes',
            'connect_domain'         => 'dns.iranip.online',
            'default_backend_ip'     => '',
            'subscription_on_domain' => 'no',
            'subscription_base_url'  => '',
        ];
    }

    public static function get(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), self::defaults());
    }

    public static function save(array $data): void
    {
        update_option(self::OPTION_KEY, [
            'enabled'                => !empty($data['enabled']) ? 'yes' : 'no',
            'connect_domain'         => sanitize_text_field($data['connect_domain'] ?? 'dns.iranip.online'),
            'default_backend_ip'     => sanitize_text_field($data['default_backend_ip'] ?? ''),
            'subscription_on_domain' => !empty($data['subscription_on_domain']) ? 'yes' : 'no',
            'subscription_base_url'  => esc_url_raw(trim($data['subscription_base_url'] ?? '')),
        ]);
    }

    public static function is_enabled(): bool
    {
        return self::get()['enabled'] === 'yes';
    }

    /** آدرسی که داخل کانفیگ مشتری می‌بیند — همیشه یکسان: dns.iranip.online */
    public static function connect_host(): string
    {
        return rtrim(self::get()['connect_domain'], '.');
    }

    /** IP واقعی که dns.iranip.online باید به آن forward کند */
    public static function backend_ip_for_panel(array $panel): string
    {
        if (!empty($panel['backend_ip'])) {
            return trim($panel['backend_ip']);
        }
        return trim(self::get()['default_backend_ip']);
    }

    public static function subscription_public_url(string $proxy_token): string
    {
        $settings = self::get();

        if ($settings['subscription_on_domain'] === 'yes' && !empty($settings['subscription_base_url'])) {
            return untrailingslashit($settings['subscription_base_url']) . '/unlimitsky-sub/' . rawurlencode($proxy_token);
        }

        return home_url('/unlimitsky-sub/' . rawurlencode($proxy_token));
    }
}
