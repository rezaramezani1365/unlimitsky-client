<?php
defined('ABSPATH') || exit;

$settings = USK_Api_Settings::get();
$api_url = $settings['api_url'];
$api_key = $settings['api_key'];
$site_url = USK_Api_Settings::site_url();
?>

<div class="wrap usk-admin-wrap">
    <div class="usk-theme-bar">
        <button type="button" id="usk-wp-theme-toggle"><span id="usk-wp-theme-icon">🌙</span> <?php esc_html_e('تم', 'unlimitsky-wc'); ?></button>
    </div>
    <h1><?php esc_html_e('unlimitsky — اتصال API', 'unlimitsky-wc'); ?></h1>

    <div class="usk-guide-box">
        <p><?php esc_html_e('پلاگین فقط از طریق API با پنل کلاینت unlimitsky ارتباط برقرار می‌کند. پروتکل، پلن و نود را در صفحه ویرایش هر محصول VPN تنظیم کنید.', 'unlimitsky-wc'); ?></p>
        <p class="description">
            <?php esc_html_e('در پنل کلاینت → کلید API، دامنه این سایت را ثبت کنید:', 'unlimitsky-wc'); ?>
            <code dir="ltr"><?php echo esc_html(wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url); ?></code>
        </p>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=unlimitsky-guides')); ?>"><?php esc_html_e('راهنمای اتصال', 'unlimitsky-wc'); ?></a>
        </p>
    </div>

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('تنظیمات API ذخیره شد.', 'unlimitsky-wc'); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['test'])) : ?>
        <div class="notice notice-<?php echo $_GET['test'] === 'ok' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo $_GET['test'] === 'ok' ? esc_html__('اتصال API موفق بود.', 'unlimitsky-wc') : esc_html__('اتصال API ناموفق بود — آدرس، کلید و دامنه را بررسی کنید.', 'unlimitsky-wc'); ?></p>
            <?php if ($_GET['test'] === 'fail' && ($err = get_transient('usk_test_api_error'))) : delete_transient('usk_test_api_error'); ?>
                <p dir="ltr" style="color:#b32d2e;"><code><?php echo esc_html($err); ?></code></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="usk-grid">
        <div class="usk-card">
            <h2><?php esc_html_e('تنظیمات API', 'unlimitsky-wc'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('usk_save_api'); ?>
                <input type="hidden" name="action" value="usk_save_api">

                <table class="form-table">
                    <tr>
                        <th><label for="api_url"><?php esc_html_e('آدرس API پنل', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <input type="url" name="api_url" id="api_url" class="regular-text" dir="ltr" placeholder="http://YOUR_IP:8082" value="<?php echo esc_attr($api_url); ?>" required>
                            <p class="description"><?php esc_html_e('آدرس کامل (مثلاً http://31.15.16.154:8082). اگر تایم‌اوت داد، مطمئن شوید پورت ۸۰۸۲ در فایروال سرور باز است.', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="api_key"><?php esc_html_e('کلید API', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <textarea name="api_key" id="api_key" class="large-text" rows="2" dir="ltr" placeholder="USK-API-..." required><?php echo esc_textarea($api_key); ?></textarea>
                            <p class="description"><?php esc_html_e('فقط کد کلید را وارد کنید (بدون عبارت Authorization: Bearer).', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('دامنه این سایت', 'unlimitsky-wc'); ?></th>
                        <td>
                            <code dir="ltr"><?php echo esc_html($site_url); ?></code>
                            <p class="description"><?php esc_html_e('این آدرس در هدر X-USK-Site-URL به API ارسال می‌شود.', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('ذخیره تنظیمات', 'unlimitsky-wc')); ?>
            </form>

            <?php if (USK_Api_Settings::is_configured()) : ?>
                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=usk_test_api'), 'usk_test_api')); ?>">
                        <?php esc_html_e('تست اتصال API', 'unlimitsky-wc'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
