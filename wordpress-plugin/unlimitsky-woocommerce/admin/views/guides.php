<?php defined('ABSPATH') || exit; ?>

<div class="wrap usk-admin-wrap">
    <div class="usk-theme-bar">
        <button type="button" id="usk-wp-theme-toggle"><span id="usk-wp-theme-icon">🌙</span> <?php esc_html_e('تم', 'unlimitsky-wc'); ?></button>
    </div>
    <h1><?php esc_html_e('راهنمای اتصال unlimitsky', 'unlimitsky-wc'); ?></h1>
    <p><?php esc_html_e('پلاگین فقط از طریق API با پنل کلاینت unlimitsky صحبت می‌کند. پس از پرداخت، لینک صفحه سرویس مشتری (portal) به خریدار داده می‌شود.', 'unlimitsky-wc'); ?></p>

    <div class="usk-grid">
        <div class="usk-card">
            <h2><?php esc_html_e('۱. پنل کلاینت', 'unlimitsky-wc'); ?></h2>
            <ol>
                <li><?php esc_html_e('پروتکل‌ها را از منوی پروتکل‌ها نصب کنید.', 'unlimitsky-wc'); ?></li>
                <li><?php esc_html_e('پلن‌ها را در منوی پلن‌ها تعریف کنید (حجم، مدت، DNS و …).', 'unlimitsky-wc'); ?></li>
                <li><?php esc_html_e('کلید API بسازید و دامنه سایت ووکامرس را وارد کنید.', 'unlimitsky-wc'); ?></li>
            </ol>
        </div>

        <div class="usk-card">
            <h2><?php esc_html_e('۲. وردپرس / ووکامرس', 'unlimitsky-wc'); ?></h2>
            <ol>
                <li><?php esc_html_e('unlimitsky → اتصال API — آدرس پنل و کلید API را وارد کنید.', 'unlimitsky-wc'); ?></li>
                <li><?php esc_html_e('محصول ساده VPN بسازید: پروتکل، پلن و در صورت نیاز نود را انتخاب کنید.', 'unlimitsky-wc'); ?></li>
                <li><?php esc_html_e('برای Marzban/Sanaei: حالت «پنل خارجی» و پنل متصل‌شده در کلاینت را انتخاب کنید.', 'unlimitsky-wc'); ?></li>
            </ol>
        </div>

        <div class="usk-card">
            <h2><?php esc_html_e('۳. پس از خرید', 'unlimitsky-wc'); ?></h2>
            <p><?php esc_html_e('مشتری لینک صفحه سرویس (portal) را در سفارش، ایمیل و حساب کاربری می‌بیند — همان صفحه‌ای که کانفیگ، QR و آمار مصرف را نشان می‌دهد.', 'unlimitsky-wc'); ?></p>
        </div>
    </div>
</div>
