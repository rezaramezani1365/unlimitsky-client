<?php defined('ABSPATH') || exit; ?>

<div class="wrap usk-admin-wrap">
    <h1><?php esc_html_e('سفارشات VPN', 'unlimitsky-wc'); ?></h1>

    <?php if (empty($orders)) : ?>
        <p><?php esc_html_e('هنوز سفارشی ثبت نشده.', 'unlimitsky-wc'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php esc_html_e('سفارش WC', 'unlimitsky-wc'); ?></th>
                    <th><?php esc_html_e('کاربر', 'unlimitsky-wc'); ?></th>
                    <th><?php esc_html_e('سرور', 'unlimitsky-wc'); ?></th>
                    <th><?php esc_html_e('حجم/مدت', 'unlimitsky-wc'); ?></th>
                    <th><?php esc_html_e('لینک', 'unlimitsky-wc'); ?></th>
                    <th><?php esc_html_e('تاریخ', 'unlimitsky-wc'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order) : ?>
                    <tr>
                        <td><?php echo esc_html($order['service_code']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $order['wc_order_id'] . '&action=edit')); ?>">
                                #<?php echo esc_html($order['wc_order_id']); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(get_userdata($order['user_id'])->user_login ?? $order['user_id']); ?></td>
                        <td><?php echo esc_html($order['panel_name']); ?></td>
                        <td><?php echo esc_html($order['volume_gb']); ?> GB / <?php echo esc_html($order['duration_days']); ?> <?php esc_html_e('روز', 'unlimitsky-wc'); ?></td>
                        <td><code style="font-size:11px;word-break:break-all;"><?php echo esc_html($order['subscription_url']); ?></code></td>
                        <td><?php echo esc_html($order['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
