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
            'External panel (Marzban/Sanaei) not selected for this product.' => 'پنل Marzban/Sanaei برای این محصول انتخاب نشده است.',
            'Unknown error' => 'خطای نامشخص',
            'unlimitsky requires WooCommerce to be installed and active.' => 'unlimitsky نیاز به نصب و فعال‌سازی ووکامرس دارد.',
            'Volume / duration' => 'حجم / مدت',
            'Volume:' => 'حجم:',
            'Duration:' => 'مدت:',
            'days label' => 'روز',
            'Your VPN service page' => 'صفحه سرویس VPN شما',
            'Open service page' => 'باز کردن صفحه سرویس',
            'Open this page to view your config, QR code, usage stats, and app download links.' => 'از این صفحه کانفیگ، QR، آمار مصرف و لینک اپ‌ها را ببینید.',
            'Use your service page for config, QR code, usage stats, and app download links.' => 'برای کانفیگ، QR، آمار مصرف و دانلود اپ از صفحه سرویس استفاده کنید.',
            'Renewal link is incomplete.' => 'لینک تمدید ناقص است.',
            'No unlimitsky panel is configured for renewal.' => 'پنل unlimitsky برای تمدید تنظیم نشده است.',
            'Renewal link is invalid or expired.' => 'لینk تمدید نامعتبر یا منقضی شده است.',
            'No WooCommerce product found for plan %1$s and protocol %2$s.' => 'محصول ووکامرس برای پلن %1$s و پروتکل %2$s یافت نشد.',
            'Could not add renewal product to cart.' => 'افزودن محصول تمدید به سبد ممکن نشد.',
            'Renewal plan added to cart. Complete checkout to extend your service.' => 'پلن تمدید به سبد اضافه شد. پرداخت را تکمیل کنید تا سرویس تمدید شود.',
            'Renewal' => 'تمدید',
            'Renewal protocol does not match the service.' => 'پروتکل تمدید با سرویس یکی نیست.',
            'Renewal is only supported for native unlimitsky services.' => 'تمدید فقط برای سرویس native unlimitsky پشتیبانی می‌شود.',
            'Renewal failed on panel.' => 'تمدید در پنل ناموفق بود.',
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
