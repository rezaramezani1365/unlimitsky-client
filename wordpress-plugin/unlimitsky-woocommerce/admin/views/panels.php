<?php
defined('ABSPATH') || exit;

$is_edit = !empty($panel);
$types   = [
    'unlimitsky' => __('UnlimitSky (پروتکل native)', 'unlimitsky-wc'),
    'marzban'    => 'Marzban',
    'sanayi'     => 'Sanaei (3x-ui)',
];
$native_protocols = [
    'wireguard' => 'WireGuard',
    'openvpn'   => 'OpenVPN',
    'xray'      => 'Xray (VLESS/VMess)',
    'l2tp'      => 'L2TP/IPsec',
    'amnezia'   => 'Amnezia (AmneziaWG)',
    'cisco'     => 'Cisco AnyConnect',
];
?>

<div class="wrap usk-admin-wrap">
    <div class="usk-theme-bar">
        <button type="button" id="usk-wp-theme-toggle"><span id="usk-wp-theme-icon">🌙</span> <?php esc_html_e('تم', 'unlimitsky-wc'); ?></button>
    </div>
    <h1><?php esc_html_e('UnlimitedSky — مدیریت پنل‌ها', 'unlimitsky-wc'); ?></h1>

    <div class="usk-guide-box">
        <?php esc_html_e('برای راهنمای کامل فیلدها به منوی', 'unlimitsky-wc'); ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=unlimitsky-guides')); ?>"><strong><?php esc_html_e('راهنما', 'unlimitsky-wc'); ?></strong></a>
        <?php esc_html_e('بروید. برای پروتکل native از نوع UnlimitSky استفاده کنید.', 'unlimitsky-wc'); ?>
    </div>

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('پنل ذخیره شد.', 'unlimitsky-wc'); ?></p></div>
    <?php endif; ?>
    <?php if (!empty($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('پنل حذف شد.', 'unlimitsky-wc'); ?></p></div>
    <?php endif; ?>
    <?php if (isset($_GET['test'])) : ?>
        <div class="notice notice-<?php echo $_GET['test'] === 'ok' ? 'success' : 'error'; ?> is-dismissible">
            <p><?php echo $_GET['test'] === 'ok' ? esc_html__('اتصال به پنل موفق بود.', 'unlimitsky-wc') : esc_html__('اتصال به پنل ناموفق بود.', 'unlimitsky-wc'); ?></p>
        </div>
    <?php endif; ?>

    <div class="usk-grid">
        <div class="usk-card">
            <h2><?php echo $is_edit ? esc_html__('ویرایش پنل', 'unlimitsky-wc') : esc_html__('افزودن پنل جدید', 'unlimitsky-wc'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('usk_save_panel'); ?>
                <input type="hidden" name="action" value="usk_save_panel">
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="panel_id" value="<?php echo esc_attr($panel['id']); ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="name"><?php esc_html_e('نام سرور', 'unlimitsky-wc'); ?></label></th>
                        <td><input type="text" name="name" id="name" class="regular-text" required value="<?php echo esc_attr($panel['name'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="type"><?php esc_html_e('نوع پنل', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <select name="type" id="usk-panel-type">
                                <?php foreach ($types as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($panel['type'] ?? 'unlimitsky', $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="usk-unlimitsky-field">
                        <th><label for="login_link"><?php esc_html_e('آدرس API پنل', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <input type="url" name="login_link" id="login_link" class="regular-text" placeholder="http://185.x.x.x:8082" value="<?php echo esc_attr($panel['login_link'] ?? ''); ?>">
                            <p class="description"><?php esc_html_e('آدرس VPS پنل UnlimitSky — مثلاً http://IP:8082 (مسیر api/v1.php خودکار اضافه می‌شود)', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr class="usk-unlimitsky-field">
                        <th><label for="token"><?php esc_html_e('کلید API', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <textarea name="token" id="token" class="large-text" rows="2" placeholder="USK-API-..."><?php echo esc_textarea($panel['token'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('از پنل UnlimitSky → API Keys ساخته می‌شود.', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr class="usk-unlimitsky-field">
                        <th><label for="native_protocol"><?php esc_html_e('پروتکل پیش‌فرض', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <select name="native_protocol" id="native_protocol">
                                <?php
                                $current_proto = trim(str_replace('|', '', $panel['protocols'] ?? 'wireguard'));
                                foreach ($native_protocols as $pk => $plabel) :
                                ?>
                                    <option value="<?php echo esc_attr($pk); ?>" <?php selected($current_proto, $pk); ?>><?php echo esc_html($plabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="usk-external-field">
                        <th><label for="login_link_ext"><?php esc_html_e('آدرس پنل', 'unlimitsky-wc'); ?></label></th>
                        <td><input type="url" name="login_link_ext" id="login_link_ext" class="regular-text" placeholder="https://panel.example.com" value="<?php echo esc_attr($panel['login_link'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th colspan="2"><strong><?php esc_html_e('IP مقصد (برای dns.iranip.online)', 'unlimitsky-wc'); ?></strong></th>
                    </tr>
                    <tr>
                        <th><label for="backend_ip"><?php esc_html_e('IP سرور VPN', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <input type="text" name="backend_ip" id="backend_ip" class="regular-text" placeholder="185.x.x.x" value="<?php echo esc_attr($panel['backend_ip'] ?? ''); ?>">
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('کاربر در اپ: %s — DNS شما این را به IP بالا وصل می‌کند. خالی = IP پیش‌فرض از تنظیمات DNS.', 'unlimitsky-wc'),
                                    '<code>' . esc_html(USK_Dns_Settings::connect_host()) . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="backend_host"><?php esc_html_e('آدرس قبلی پنل (اختیاری)', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <input type="text" name="backend_host" id="backend_host" class="regular-text" value="<?php echo esc_attr($panel['backend_host'] ?? ''); ?>">
                            <p class="description"><?php esc_html_e('فقط برای جایگزینی در rewrite — معمولاً لازم نیست.', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr class="usk-external-field">
                        <th><label for="username"><?php esc_html_e('نام کاربری', 'unlimitsky-wc'); ?></label></th>
                        <td><input type="text" name="username" id="username" class="regular-text" value="<?php echo esc_attr($panel['username'] ?? ''); ?>"></td>
                    </tr>
                    <tr class="usk-external-field">
                        <th><label for="password"><?php esc_html_e('رمز عبور', 'unlimitsky-wc'); ?></label></th>
                        <td><input type="password" name="password" id="password" class="regular-text" value="<?php echo esc_attr($panel['password'] ?? ''); ?>"></td>
                    </tr>
                    <tr class="usk-external-field">
                        <th><label for="token_ext"><?php esc_html_e('Token (اختیاری)', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <textarea name="token_ext" id="token_ext" class="large-text" rows="2"><?php echo esc_textarea($panel['token'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('خالی بگذارید تا خودکار از username/password گرفته شود.', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr class="usk-marzban-field">
                        <th><label for="protocols"><?php esc_html_e('پروتکل‌ها', 'unlimitsky-wc'); ?></label></th>
                        <td><input type="text" name="protocols" id="protocols" class="regular-text" value="<?php echo esc_attr($panel['protocols'] ?? 'vless|'); ?>" placeholder="vless|vmess|"></td>
                    </tr>
                    <tr class="usk-marzban-field">
                        <th><label for="flow"><?php esc_html_e('Flow', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <select name="flow" id="flow">
                                <option value="flowon" <?php selected($panel['flow'] ?? 'flowon', 'flowon'); ?>>flowon</option>
                                <option value="flowoff" <?php selected($panel['flow'] ?? '', 'flowoff'); ?>>flowoff</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="usk-marzban-field">
                        <th><label for="marzban_inbounds"><?php esc_html_e('Inbounds (هر خط یکی)', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <textarea name="marzban_inbounds" id="marzban_inbounds" class="large-text" rows="4"><?php
                                if (!empty($marzban_inbounds)) {
                                    echo esc_textarea(implode("\n", array_column($marzban_inbounds, 'inbound')));
                                }
                            ?></textarea>
                        </td>
                    </tr>
                    <tr class="usk-sanayi-field">
                        <th><label for="inbound_id"><?php esc_html_e('Inbound ID', 'unlimitsky-wc'); ?></label></th>
                        <td><input type="text" name="inbound_id" id="inbound_id" class="regular-text" value="<?php echo esc_attr($sanayi_setting['inbound_id'] ?? ''); ?>"></td>
                    </tr>
                    <tr class="usk-sanayi-field">
                        <th><label for="example_link"><?php esc_html_e('قالب لینک کانفیگ', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <textarea name="example_link" id="example_link" class="large-text" rows="3"><?php echo esc_textarea($sanayi_setting['example_link'] ?? ''); ?></textarea>
                            <p class="description"><?php esc_html_e('از %s1 (uuid), %s2 (host:port), %s3 (remark) استفاده کنید.', 'unlimitsky-wc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status"><?php esc_html_e('وضعیت', 'unlimitsky-wc'); ?></label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active" <?php selected($panel['status'] ?? 'active', 'active'); ?>><?php esc_html_e('فعال', 'unlimitsky-wc'); ?></option>
                                <option value="inactive" <?php selected($panel['status'] ?? '', 'inactive'); ?>><?php esc_html_e('غیرفعال', 'unlimitsky-wc'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button($is_edit ? __('به‌روزرسانی پنل', 'unlimitsky-wc') : __('افزودن پنل', 'unlimitsky-wc')); ?>
                <?php if ($is_edit) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=unlimitsky')); ?>" class="button"><?php esc_html_e('انصراف', 'unlimitsky-wc'); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <div class="usk-card">
            <h2><?php esc_html_e('پنل‌های ثبت‌شده', 'unlimitsky-wc'); ?></h2>
            <?php if (empty($panels)) : ?>
                <p><?php esc_html_e('هنوز پنلی اضافه نشده.', 'unlimitsky-wc'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('نام', 'unlimitsky-wc'); ?></th>
                            <th><?php esc_html_e('نوع', 'unlimitsky-wc'); ?></th>
                            <th><?php esc_html_e('اتصال', 'unlimitsky-wc'); ?></th>
                            <th><?php esc_html_e('IP مقصد', 'unlimitsky-wc'); ?></th>
                            <th><?php esc_html_e('تعداد', 'unlimitsky-wc'); ?></th>
                            <th><?php esc_html_e('عملیات', 'unlimitsky-wc'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($panels as $p) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($p['name']); ?></strong></td>
                                <td><?php echo esc_html($p['type']); ?></td>
                                <td><code><?php echo esc_html(USK_Dns_Settings::connect_host()); ?></code></td>
                                <td><?php echo esc_html(USK_Dns_Settings::backend_ip_for_panel($p) ?: '—'); ?></td>
                                <td><?php echo esc_html($p['count_create']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(['page' => 'unlimitsky', 'edit' => $p['id']], admin_url('admin.php'))); ?>"><?php esc_html_e('ویرایش', 'unlimitsky-wc'); ?></a> |
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=usk_test_panel&id=' . $p['id']), 'usk_test_panel')); ?>"><?php esc_html_e('تست', 'unlimitsky-wc'); ?></a> |
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=usk_delete_panel&id=' . $p['id']), 'usk_delete_panel')); ?>" onclick="return confirm('<?php esc_attr_e('حذف شود؟', 'unlimitsky-wc'); ?>');" style="color:#b32d2e;"><?php esc_html_e('حذف', 'unlimitsky-wc'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function(){
    function togglePanelFields() {
        var type = document.getElementById('usk-panel-type').value;
        var isNative = type === 'unlimitsky';
        document.querySelectorAll('.usk-unlimitsky-field').forEach(function(el) {
            el.style.display = isNative ? '' : 'none';
        });
        document.querySelectorAll('.usk-external-field').forEach(function(el) {
            el.style.display = isNative ? 'none' : '';
        });
        document.querySelectorAll('.usk-marzban-field').forEach(function(el) {
            el.style.display = type === 'marzban' ? '' : 'none';
        });
        document.querySelectorAll('.usk-sanayi-field').forEach(function(el) {
            el.style.display = type === 'sanayi' ? '' : 'none';
        });
        var linkNative = document.getElementById('login_link');
        var linkExt = document.getElementById('login_link_ext');
        if (linkNative && linkExt) {
            if (isNative) {
                linkNative.name = 'login_link';
                linkExt.name = 'login_link_ext_unused';
                linkNative.required = true;
            } else {
                linkExt.name = 'login_link';
                linkNative.name = 'login_link_native_unused';
                linkExt.required = true;
            }
        }
        var tokenNative = document.getElementById('token');
        var tokenExt = document.getElementById('token_ext');
        if (tokenNative && tokenExt) {
            if (isNative) {
                tokenNative.name = 'token';
                tokenExt.name = 'token_ext_unused';
            } else {
                tokenExt.name = 'token';
                tokenNative.name = 'token_native_unused';
            }
        }
    }
    document.getElementById('usk-panel-type').addEventListener('change', togglePanelFields);
    togglePanelFields();
})();
</script>
