<?php

defined('ABSPATH') || exit;

class USK_Order_Display
{
    public static function render_service_block(array $service, bool $plain_text = false): void
    {
        $protocol = $service['protocol'] ?? '';
        $expires  = $service['expires_at'] ?? '';
        $qr       = $service['qr_png'] ?? '';

        if ($plain_text) {
            echo self::text_block($service);
            return;
        }

        echo '<div class="usk-vpn-service" style="margin:16px 0;padding:16px;border:1px solid #ddd;border-radius:8px;">';
        echo '<h3 style="margin:0 0 12px;">' . esc_html($service['panel_name']) . '</h3>';
        echo '<ul style="margin:0 0 12px;padding-left:18px;">';
        echo '<li><strong>' . esc_html__('Volume', 'unlimitsky-wc') . ':</strong> ' . esc_html($service['volume_gb']) . ' GB</li>';
        echo '<li><strong>' . esc_html__('Duration', 'unlimitsky-wc') . ':</strong> ' . esc_html($service['duration_days']) . ' ' . esc_html__('days', 'unlimitsky-wc') . '</li>';
        if ($protocol !== '') {
            echo '<li><strong>' . esc_html__('Protocol', 'unlimitsky-wc') . ':</strong> ' . esc_html(strtoupper($protocol)) . '</li>';
        }
        if ($expires !== '') {
            echo '<li><strong>' . esc_html__('Expires', 'unlimitsky-wc') . ':</strong> ' . esc_html(self::format_expires($expires)) . '</li>';
        }
        if (!empty($service['connect_host'])) {
            echo '<li><strong>' . esc_html__('Connect host', 'unlimitsky-wc') . ':</strong> <code>' . esc_html($service['connect_host']) . '</code></li>';
        }
        echo '</ul>';

        if ($qr !== '') {
            echo '<p><strong>' . esc_html__('WireGuard QR code', 'unlimitsky-wc') . ':</strong></p>';
            echo '<p style="text-align:center;"><img src="data:image/png;base64,' . esc_attr($qr) . '" alt="WireGuard QR" style="max-width:220px;height:auto;border:1px solid #eee;padding:8px;background:#fff;" /></p>';
            echo '<p style="font-size:12px;color:#666;">' . esc_html__('Scan with the WireGuard app on your phone.', 'unlimitsky-wc') . '</p>';
        }

        echo '<p><strong>' . esc_html__('Config / subscription', 'unlimitsky-wc') . ':</strong></p>';
        self::render_config($service['subscription_url'] ?? '');

        if (!empty($service['config_links']) && ($service['config_links'] !== ($service['subscription_url'] ?? ''))) {
            echo '<p style="margin-top:12px;"><strong>' . esc_html__('Additional links', 'unlimitsky-wc') . ':</strong></p>';
            self::render_config($service['config_links']);
        }

        echo '</div>';
    }

    private static function render_config(string $content): void
    {
        if ($content === '') {
            echo '<p>—</p>';
            return;
        }
        if (filter_var($content, FILTER_VALIDATE_URL)) {
            echo '<p><a href="' . esc_url($content) . '">' . esc_html($content) . '</a></p>';
            return;
        }
        echo '<pre style="white-space:pre-wrap;font-size:12px;background:#f7f7f7;padding:12px;border-radius:6px;overflow:auto;max-height:320px;">' . esc_html($content) . '</pre>';
    }

    private static function text_block(array $service): string
    {
        $lines = [];
        $lines[] = $service['panel_name'];
        $lines[] = __('Volume', 'unlimitsky-wc') . ': ' . $service['volume_gb'] . ' GB';
        $lines[] = __('Duration', 'unlimitsky-wc') . ': ' . $service['duration_days'] . ' ' . __('days', 'unlimitsky-wc');
        if (!empty($service['protocol'])) {
            $lines[] = __('Protocol', 'unlimitsky-wc') . ': ' . strtoupper($service['protocol']);
        }
        if (!empty($service['expires_at'])) {
            $lines[] = __('Expires', 'unlimitsky-wc') . ': ' . self::format_expires($service['expires_at']);
        }
        $lines[] = __('Config', 'unlimitsky-wc') . ': ' . ($service['subscription_url'] ?? '');
        return implode("\n", $lines) . "\n\n";
    }

    public static function format_expires(string $iso): string
    {
        $ts = strtotime($iso);
        if (!$ts) {
            return $iso;
        }
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts);
    }
}
