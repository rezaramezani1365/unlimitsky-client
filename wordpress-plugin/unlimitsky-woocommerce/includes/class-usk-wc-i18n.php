<?php

defined('ABSPATH') || exit;

class USK_WC_I18n
{
    public static function boot(): void
    {
        load_plugin_textdomain(
            'unlimitsky-wc',
            false,
            dirname(plugin_basename(USK_WC_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Compile-free fallback when .mo is missing (fa_IR / en_US PHP catalogs).
     */
    public static function translate(string $text): string
    {
        $locale = determine_locale();
        $lang   = (strpos($locale, 'fa') === 0) ? 'fa' : 'en';
        static $catalog = null;

        if ($catalog === null) {
            $catalog = [
                'fa' => self::fa_strings(),
                'en' => [],
            ];
        }

        if ($lang === 'fa' && isset($catalog['fa'][$text])) {
            return $catalog['fa'][$text];
        }

        return $text;
    }

    private static function fa_strings(): array
    {
        return [
            'VPN Services' => 'سرویس‌های VPN',
            'My VPN Services' => 'سرویس‌های VPN من',
            'Server' => 'سرور',
            'Volume' => 'حجم',
            'Duration' => 'مدت',
            'days' => 'روز',
            'Connect host' => 'هاست اتصال',
            'Subscription link' => 'لینک اشتراک',
            'Config / subscription' => 'کانفیگ / اشتراک',
            'WireGuard QR code' => 'QR Code وایرگارد',
            'Scan with the WireGuard app on your phone.' => 'با اپ WireGuard روی موبایل اسکن کنید.',
            'Protocol' => 'پروتکل',
            'Expires' => 'انقضا',
            'Code' => 'کد',
            'Date' => 'تاریخ',
            'Additional links' => 'لینک‌های اضافه',
            'Config' => 'کانفیگ',
            'You have not purchased any VPN service yet.' => 'هنوز سرویسی خریداری نکرده‌اید.',
            'Panel for product "%s" not found.' => 'پنل محصول «%s» یافت نشد.',
            'Unknown error' => 'خطای نامشخص',
            'UnlimitSky requires WooCommerce to be installed and active.' => 'UnlimitSky نیاز به نصب و فعال‌سازی ووکامرس دارد.',
            'Volume / duration' => 'حجم / مدت',
            'Volume:' => 'حجم:',
            'Duration:' => 'مدت:',
            'days label' => 'روز',
        ];
    }
}

if (!function_exists('usk_wc__')) {
    function usk_wc__(string $text): string
    {
        $translated = __($text, 'unlimitsky-wc');
        if ($translated === $text) {
            return USK_WC_I18n::translate($text);
        }
        return $translated;
    }
}

if (!function_exists('usk_wc_e')) {
    function usk_wc_e(string $text): void
    {
        echo esc_html(usk_wc__($text));
    }
}
