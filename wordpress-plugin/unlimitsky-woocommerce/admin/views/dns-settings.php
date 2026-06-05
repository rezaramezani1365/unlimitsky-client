<?php defined('ABSPATH') || exit;

$connect = USK_Dns_Settings::connect_host();
?>

<div class="wrap usk-admin-wrap">
    <div class="usk-theme-bar">
        <button type="button" id="usk-wp-theme-toggle"><span id="usk-wp-theme-icon">🌙</span> <?php esc_html_e('تم', 'unlimitsky-wc'); ?></button>
    </div>
    <h1><?php esc_html_e('UnlimitedSky — تنظیمات DNS / اتصال', 'unlimitsky-wc'); ?></h1>

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('تنظیمات ذخیره شد.', 'unlimitsky-wc'); ?></p></div>
    <?php endif; ?>

    <div class="usk-card" style="max-width:720px;margin-top:20px;">
        <p style="font-size:14px;line-height:1.8;">
            <?php esc_html_e('DNS از قبل روی دامنه شما ست شده. این پلاگین فقط آدرس داخل کانفیگ را به dns.iranip.online تغییر می‌دهد. IP واقعی را اینجا مدیریت کنید — وقتی عوض شد، dns.iranip.online همان IP جدید را به کاربر وصل می‌کند (DNS شما).', 'unlimitsky-wc'); ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('usk_save_dns'); ?>
            <input type="hidden" name="action" value="usk_save_dns">

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('فعال', 'unlimitsky-wc'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($dns_settings['enabled'], 'yes'); ?>>
                            <?php esc_html_e('آدرس کانفیگ‌ها = dns.iranip.online', 'unlimitsky-wc'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="connect_domain"><?php esc_html_e('ساب‌دامین اتصال', 'unlimitsky-wc'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="connect_domain" name="connect_domain" value="<?php echo esc_attr($dns_settings['connect_domain']); ?>">
                        <p class="description"><?php esc_html_e('همه کاربران فقط به این آدرس وصل می‌شوند.', 'unlimitsky-wc'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="default_backend_ip"><?php esc_html_e('IP مقصد (پیش‌فرض)', 'unlimitsky-wc'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="default_backend_ip" name="default_backend_ip" value="<?php echo esc_attr($dns_settings['default_backend_ip']); ?>" placeholder="185.x.x.x">
                        <p class="description">
                            <?php esc_html_e('IP واقعی سرور VPN. dns.iranip.online باید به این IP resolve شود. با عوض کردن IP اینجا، کانفیگ مشتری عوض نمی‌شود.', 'unlimitsky-wc'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('ذخیره', 'unlimitsky-wc')); ?>
        </form>
    </div>

    <div class="usk-card" style="max-width:720px;margin-top:20px;">
        <h2><?php esc_html_e('جریان کار', 'unlimitsky-wc'); ?></h2>
        <pre style="background:#f6f7f7;padding:16px;border-radius:6px;line-height:1.7;direction:ltr;text-align:left;">User App → dns.iranip.online:443
              ↓ (DNS شما — از قبل ست شده)
         IP سرور VPN (از فیلد بالا)

Config address in app: <?php echo esc_html($connect); ?>

Subscription URL: <?php echo esc_html(home_url('/unlimitsky-sub/TOKEN')); ?></pre>

        <p><strong><?php esc_html_e('API IP (اختیاری):', 'unlimitsky-wc'); ?></strong>
            <code dir="ltr"><?php echo esc_html(rest_url('UnlimitSky/v1/ip')); ?></code>
        </p>
    </div>
</div>
