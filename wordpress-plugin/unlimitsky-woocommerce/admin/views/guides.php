<?php defined('ABSPATH') || exit; ?>

<div class="wrap usk-admin-wrap">
    <div class="usk-theme-bar">
        <button type="button" id="usk-wp-theme-toggle"><span id="usk-wp-theme-icon">🌙</span> <?php esc_html_e('تم', 'unlimitsky-wc'); ?></button>
    </div>
    <h1><?php esc_html_e('راهنمای اتصال پنل', 'unlimitsky-wc'); ?></h1>
    <p><?php esc_html_e('UnlimitedSky روی هاست وردپرس اجرا می‌شود؛ ساخت کانفیگ از API سرور Marzban یا Sanaei روی VPS انجام می‌شود.', 'unlimitsky-wc'); ?></p>

    <div class="usk-grid">
        <div class="usk-card">
            <h2><?php esc_html_e('Marzban', 'unlimitsky-wc'); ?></h2>
            <div class="usk-guide-step">
                <div class="usk-guide-num">1</div>
                <div>
                    <strong><?php esc_html_e('آدرس پنل', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><code>https://IP:8000</code> <?php esc_html_e('یا دامنه با SSL', 'unlimitsky-wc'); ?></p>
                </div>
            </div>
            <div class="usk-guide-step">
                <div class="usk-guide-num">2</div>
                <div>
                    <strong><?php esc_html_e('یوزر / رمز', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><?php esc_html_e('همان admin Marzban — با ذخیره، token خودکار گرفته می‌شود.', 'unlimitsky-wc'); ?></p>
                </div>
            </div>
            <div class="usk-guide-step">
                <div class="usk-guide-num">3</div>
                <div>
                    <strong><?php esc_html_e('پروتکل و Inbounds', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><code>vless|vmess|</code> — <?php esc_html_e('هر inbound tag را در یک خط بنویسید.', 'unlimitsky-wc'); ?></p>
                </div>
            </div>
            <div class="usk-guide-step">
                <div class="usk-guide-num">4</div>
                <div>
                    <strong><?php esc_html_e('IP مقصد (DNS)', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><?php esc_html_e('IP واقعی VPS — برای dns.iranip.online', 'unlimitsky-wc'); ?></p>
                </div>
            </div>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=unlimitsky')); ?>"><?php esc_html_e('افزودن پنل Marzban', 'unlimitsky-wc'); ?></a></p>
        </div>

        <div class="usk-card">
            <h2><?php esc_html_e('Sanaei (3x-ui)', 'unlimitsky-wc'); ?></h2>
            <div class="usk-guide-step">
                <div class="usk-guide-num">1</div>
                <div>
                    <strong><?php esc_html_e('آدرس پنل', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><code>http://IP:2053</code></p>
                </div>
            </div>
            <div class="usk-guide-step">
                <div class="usk-guide-num">2</div>
                <div>
                    <strong><?php esc_html_e('Inbound ID', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><?php esc_html_e('عدد ID از لیست Inbounds در 3x-ui', 'unlimitsky-wc'); ?></p>
                </div>
            </div>
            <div class="usk-guide-step">
                <div class="usk-guide-num">3</div>
                <div>
                    <strong><?php esc_html_e('قالب لینک', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><code>%s1</code> uuid · <code>%s2</code> host:port · <code>%s3</code> remark</p>
                </div>
            </div>
            <div class="usk-guide-step">
                <div class="usk-guide-num">4</div>
                <div>
                    <strong><?php esc_html_e('تست اتصال', 'unlimitsky-wc'); ?></strong>
                    <p class="description"><?php esc_html_e('بعد از ذخیره، از لیست پنل‌ها «تست» بزنید.', 'unlimitsky-wc'); ?></p>
                </div>
            </div>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=unlimitsky')); ?>"><?php esc_html_e('افزودن پنل Sanaei', 'unlimitsky-wc'); ?></a></p>
        </div>
    </div>
</div>
