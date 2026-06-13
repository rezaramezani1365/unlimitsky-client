<?php

defined('ABSPATH') || exit;

class USK_WooCommerce
{
    public function __construct()
    {
        add_filter('product_type_options', [$this, 'add_product_option']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_fields']);

        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_product_tab_panel']);

        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_product_frontend_info']);
    }

    public function add_product_option(array $options): array
    {
        $options['USK_vpn'] = [
            'id'            => '_usk_is_vpn',
            'wrapper_class' => 'show_if_simple',
            'label'         => __('سرویس VPN (unlimitsky)', 'unlimitsky-wc'),
            'description'   => __('با فعال‌سازی، پس از پرداخت کانفیگ خودکار ساخته می‌شود.', 'unlimitsky-wc'),
            'default'       => 'no',
        ];
        return $options;
    }

    public function render_product_fields(): void
    {
        global $post;

        echo '<div class="options_group show_if_simple unlimitsky-fields">';

        woocommerce_wp_checkbox([
            'id'          => '_usk_is_vpn',
            'label'       => __('محصول VPN', 'unlimitsky-wc'),
            'description' => __('این محصول کانفیگ VPN است', 'unlimitsky-wc'),
        ]);

        $api = USK_Api_Settings::get();
        $api_configured = USK_Api_Settings::is_configured();

        $protocol_options = ['' => __('— انتخاب پروتکل —', 'unlimitsky-wc')];
        $plan_options = ['' => __('— انتخاب پلن —', 'unlimitsky-wc')];
        $node_options = ['' => __('سرور اصلی (Hub)', 'unlimitsky-wc')];
        $external_panel_options = ['' => __('— انتخاب پنل Marzban/Sanaei —', 'unlimitsky-wc')];

        $plans_json = '[]';
        $protocols_json = '[]';

        if ($api_configured) {
            $protocols = USK_UnlimitSky_Panel::list_protocols($api['api_url'], $api['api_key']);
            foreach ($protocols as $proto) {
                $id = (string) ($proto['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $protocol_options[$id] = (string) ($proto['name'] ?? strtoupper($id));
            }
            $protocols_json = wp_json_encode($protocols);

            $plans = USK_UnlimitSky_Panel::list_plans($api['api_url'], $api['api_key']);
            $plans_json = wp_json_encode($plans);
            foreach ($plans as $p) {
                $code = (string) ($p['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $plan_options[$code] = self::plan_label($p);
            }

            $nodeList = USK_UnlimitSky_Panel::list_nodes($api['api_url'], $api['api_key']);
            foreach ($nodeList['nodes'] ?? [] as $node) {
                $nid = (string) ($node['id'] ?? '');
                if ($nid === '') {
                    continue;
                }
                $node_options[$nid] = ($node['name'] ?? $nid) . ' — ' . ($node['connect_host'] ?? '');
            }

            foreach (USK_UnlimitSky_Panel::list_external_panels($api['api_url'], $api['api_key']) as $rp) {
                $external_panel_options[(string) ($rp['code'] ?? '')] = ($rp['name'] ?? '') . ' (' . ($rp['type'] ?? '') . ' — VLESS/VMess)';
            }
        }

        $current_protocol = sanitize_key((string) get_post_meta($post->ID, '_usk_protocol', true));
        $current_plan_code = preg_replace('/[^0-9]/', '', (string) get_post_meta($post->ID, '_usk_plan_code', true));
        $current_node_id = preg_replace('/[^a-z0-9]/', '', (string) get_post_meta($post->ID, '_usk_node_id', true));
        $current_provision = get_post_meta($post->ID, '_usk_provision_mode', true) ?: 'native';
        $current_ext_panel = get_post_meta($post->ID, '_usk_external_panel_code', true) ?: '';

        if (!$api_configured) {
            echo '<p class="form-field"><span class="description" style="color:#b32d2e;">';
            echo esc_html__('ابتدا از منوی unlimitsky → اتصال API، آدرس و کلید API را تنظیم کنید.', 'unlimitsky-wc');
            echo '</span></p>';
        }

        woocommerce_wp_select([
            'id'          => '_usk_provision_mode',
            'label'       => __('محل ساخت کانفیگ', 'unlimitsky-wc'),
            'options'     => [
                'native'   => __('پروتکل native روی VPS (WireGuard, OpenVPN, Xray, …)', 'unlimitsky-wc'),
                'external' => __('پنل Marzban / Sanaei (VLESS/VMess — Xray)', 'unlimitsky-wc'),
            ],
            'value'       => $current_provision,
            'wrapper_class' => 'usk-provision-mode-field',
            'desc_tip'    => true,
            'description' => __('پنل‌های Marzban/Sanaei باید در پنل کلاینت (Pro) متصل شده باشند.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_protocol',
            'label'       => __('پروتکل', 'unlimitsky-wc'),
            'options'     => $protocol_options,
            'value'       => $current_protocol,
            'wrapper_class' => 'usk-native-protocol-field',
            'desc_tip'    => true,
            'description' => __('از پروتکل‌های نصب‌شده روی پنل کلاینت.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_plan_code',
            'label'       => __('پلن', 'unlimitsky-wc'),
            'options'     => $plan_options,
            'value'       => $current_plan_code,
            'wrapper_class' => 'usk-panel-plan-field',
            'desc_tip'    => true,
            'description' => __('حجم، مدت و اتصال همزمان از تعریف پلن در پنل کلاینت خوانده می‌شود.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_node_id',
            'label'       => __('سرور / نود', 'unlimitsky-wc'),
            'options'     => $node_options,
            'value'       => $current_node_id,
            'wrapper_class' => 'usk-node-field',
            'desc_tip'    => true,
            'description' => __('اختیاری — برای relay روی نود ثبت‌شده در پنل.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_external_panel_code',
            'label'       => __('پنل Marzban / Sanaei', 'unlimitsky-wc'),
            'options'     => $external_panel_options,
            'value'       => $current_ext_panel,
            'wrapper_class' => 'usk-external-panel-field',
            'desc_tip'    => true,
            'description' => __('فقط برای حالت پنل خارجی — VLESS/VMess (Xray).', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_openvpn_proto',
            'label'       => __('OpenVPN: UDP یا TCP', 'unlimitsky-wc'),
            'options'     => [
                'tcp' => 'TCP (' . __('پیشنهادی ایران', 'unlimitsky-wc') . ')',
                'udp' => 'UDP',
            ],
            'wrapper_class' => 'usk-openvpn-proto-field',
            'desc_tip'      => true,
            'description'   => __('فقط برای محصولات OpenVPN.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_wireguard_transport',
            'label'       => __('WireGuard: UDP یا TCP', 'unlimitsky-wc'),
            'options'     => [
                'tcp' => 'TCP (' . __('پیشنهادی ایران', 'unlimitsky-wc') . ')',
                'udp' => 'UDP',
            ],
            'wrapper_class' => 'usk-wireguard-transport-field',
            'desc_tip'      => true,
            'description'   => __('TCP نیاز به udp2raw روی کلاینت دارد.', 'unlimitsky-wc'),
        ]);

        echo '<input type="hidden" id="_usk_volume_gb" name="_usk_volume_gb" value="' . esc_attr((string) (int) get_post_meta($post->ID, '_usk_volume_gb', true)) . '">';
        echo '<input type="hidden" id="_usk_duration_days" name="_usk_duration_days" value="' . esc_attr((string) (int) get_post_meta($post->ID, '_usk_duration_days', true)) . '">';

        echo '</div>';

        wc_enqueue_js("
            var uskPlans = {$plans_json};
            var uskUnlimited = " . wp_json_encode(__('نامحدود', 'unlimitsky-wc')) . ";
            function uskPlanLabel(p) {
                var vol = (parseInt(p.volume_gb, 10) > 0) ? (p.volume_gb + ' GB') : uskUnlimited;
                var days = (parseInt(p.duration_days, 10) > 0) ? (p.duration_days + ' " . esc_js(__('روز', 'unlimitsky-wc')) . "') : uskUnlimited;
                return p.name + ' — ' + vol + ' / ' + days;
            }
            function uskApplyPlanFields() {
                var \$plan = jQuery('#_usk_plan_code');
                var code = \$plan.val();
                if (!code) return;
                jQuery.each(uskPlans, function(i, p) {
                    if (String(p.code) === String(code)) {
                        jQuery('#_usk_volume_gb').val(p.volume_gb || '');
                        jQuery('#_usk_duration_days').val(p.duration_days || '');
                    }
                });
            }
            function UnlimitSkyToggleFields() {
                if (jQuery('#_usk_is_vpn').is(':checked')) {
                    jQuery('.unlimitsky-fields').show();
                } else {
                    jQuery('.unlimitsky-fields').hide();
                }
            }
            function UnlimitSkyToggleProvision() {
                var mode = jQuery('#_usk_provision_mode').val();
                var isExternal = mode === 'external';
                jQuery('.usk-external-panel-field').toggle(isExternal);
                jQuery('.usk-native-protocol-field, .usk-openvpn-proto-field, .usk-wireguard-transport-field, .usk-node-field').toggle(!isExternal);
                jQuery('.usk-panel-plan-field').show();
            }
            function UnlimitSkyToggleOpenvpnProto() {
                jQuery('.usk-openvpn-proto-field').toggle(jQuery('#_usk_protocol').val() === 'openvpn');
            }
            function UnlimitSkyToggleWireguardTransport() {
                jQuery('.usk-wireguard-transport-field').toggle(jQuery('#_usk_protocol').val() === 'wireguard');
            }
            jQuery('#_usk_is_vpn').change(UnlimitSkyToggleFields);
            jQuery('#_usk_provision_mode').change(UnlimitSkyToggleProvision);
            jQuery('#_usk_plan_code').change(uskApplyPlanFields);
            jQuery('#_usk_protocol').change(function(){
                UnlimitSkyToggleOpenvpnProto();
                UnlimitSkyToggleWireguardTransport();
            });
            UnlimitSkyToggleFields();
            UnlimitSkyToggleProvision();
            UnlimitSkyToggleOpenvpnProto();
            UnlimitSkyToggleWireguardTransport();
            uskApplyPlanFields();
        ");
    }

    private static function plan_label(array $plan): string
    {
        $vol = (int) ($plan['volume_gb'] ?? 0);
        $days = (int) ($plan['duration_days'] ?? 0);
        $volLabel = $vol > 0 ? $vol . ' GB' : __('نامحدود', 'unlimitsky-wc');
        $daysLabel = $days > 0 ? $days . ' ' . __('روز', 'unlimitsky-wc') : __('نامحدود', 'unlimitsky-wc');

        return sprintf('%s — %s / %s', $plan['name'] ?? ($plan['code'] ?? ''), $volLabel, $daysLabel);
    }

    public function save_product_fields(int $post_id): void
    {
        $is_vpn = isset($_POST['_usk_is_vpn']) ? 'yes' : 'no';
        update_post_meta($post_id, '_usk_is_vpn', $is_vpn);

        $provision_mode = sanitize_text_field($_POST['_usk_provision_mode'] ?? 'native');
        update_post_meta($post_id, '_usk_provision_mode', in_array($provision_mode, ['native', 'external'], true) ? $provision_mode : 'native');
        update_post_meta($post_id, '_usk_external_panel_code', preg_replace('/[^0-9]/', '', (string) ($_POST['_usk_external_panel_code'] ?? '')));
        update_post_meta($post_id, '_usk_plan_code', preg_replace('/[^0-9]/', '', (string) ($_POST['_usk_plan_code'] ?? '')));
        update_post_meta($post_id, '_usk_protocol', sanitize_key((string) ($_POST['_usk_protocol'] ?? '')));
        update_post_meta($post_id, '_usk_node_id', preg_replace('/[^a-z0-9]/', '', (string) ($_POST['_usk_node_id'] ?? '')));
        update_post_meta($post_id, '_usk_volume_gb', absint($_POST['_usk_volume_gb'] ?? 0));
        update_post_meta($post_id, '_usk_duration_days', absint($_POST['_usk_duration_days'] ?? 0));

        $ovpnProto = strtolower(sanitize_text_field($_POST['_usk_openvpn_proto'] ?? 'tcp'));
        update_post_meta($post_id, '_usk_openvpn_proto', in_array($ovpnProto, ['udp', 'tcp'], true) ? $ovpnProto : 'tcp');
        $wgTransport = strtolower(sanitize_text_field($_POST['_usk_wireguard_transport'] ?? 'tcp'));
        update_post_meta($post_id, '_usk_wireguard_transport', in_array($wgTransport, ['udp', 'tcp'], true) ? $wgTransport : 'tcp');
    }

    public function add_product_tab(array $tabs): array
    {
        $tabs['UnlimitSky'] = [
            'label'    => __('unlimitsky VPN', 'unlimitsky-wc'),
            'target'   => 'USK_product_data',
            'class'    => ['show_if_simple'],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function render_product_tab_panel(): void
    {
        echo '<div id="USK_product_data" class="panel woocommerce_options_panel">';
        echo '<p class="form-field"><strong>' . esc_html__('راهنما:', 'unlimitsky-wc') . '</strong> ';
        echo esc_html__('اتصال API را در unlimitsky → اتصال API تنظیم کنید. پروتکل، پلن و نود را در همین تب «عمومی» انتخاب کنید.', 'unlimitsky-wc');
        echo '</p></div>';
    }

    public function render_product_frontend_info(): void
    {
        global $product;
        if (!$product || $product->get_meta('_usk_is_vpn') !== 'yes') {
            return;
        }

        $plan_code = preg_replace('/[^0-9]/', '', (string) $product->get_meta('_usk_plan_code'));
        $protocol  = sanitize_key((string) $product->get_meta('_usk_protocol'));
        $volume    = (int) $product->get_meta('_usk_volume_gb');
        $days      = (int) $product->get_meta('_usk_duration_days');

        echo '<div class="UnlimitSky-product-info" style="margin-bottom:1em;padding:1em;background:#f7f7f7;border-radius:4px;">';
        echo '<strong>' . esc_html__('مشخصات سرویس:', 'unlimitsky-wc') . '</strong><ul style="margin:0.5em 0 0;padding-right:1.2em;">';
        if ($protocol !== '') {
            echo '<li>' . esc_html__('پروتکل:', 'unlimitsky-wc') . ' ' . esc_html(strtoupper($protocol)) . '</li>';
        }
        if ($plan_code !== '') {
            echo '<li>' . esc_html__('پلن:', 'unlimitsky-wc') . ' <code dir="ltr">' . esc_html($plan_code) . '</code></li>';
        }
        if ($volume > 0) {
            echo '<li>' . esc_html__('حجم:', 'unlimitsky-wc') . ' ' . esc_html((string) $volume) . ' GB</li>';
        }
        if ($days > 0) {
            echo '<li>' . esc_html__('مدت:', 'unlimitsky-wc') . ' ' . esc_html((string) $days) . ' ' . esc_html__('روز', 'unlimitsky-wc') . '</li>';
        }
        echo '<li>' . esc_html__('تحویل: خودکار پس از پرداخت', 'unlimitsky-wc') . '</li>';
        echo '</ul></div>';
    }
}
