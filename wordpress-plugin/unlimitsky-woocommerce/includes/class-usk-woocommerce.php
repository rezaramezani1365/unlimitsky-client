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
            'label'         => __('سرویس VPN (UnlimitSky)', 'unlimitsky-wc'),
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

        $panels = USK_Panel_Manager::get_panels();
        $options = ['' => __('— انتخاب پنل —', 'unlimitsky-wc')];
        $panel_types = [];
        foreach ($panels as $panel) {
            $options[$panel['id']] = $panel['name'] . ' (' . $panel['type'] . ')';
            $panel_types[(int) $panel['id']] = $panel['type'];
        }

        $external_panel_options = ['' => __('— انتخاب پنل Marzban/Sanaei —', 'unlimitsky-wc')];
        foreach ($panels as $panel) {
            if (($panel['type'] ?? '') !== 'unlimitsky' || empty($panel['token'])) {
                continue;
            }
            $remote = USK_UnlimitSky_Panel::list_external_panels($panel['login_link'], $panel['token']);
            foreach ($remote as $rp) {
                $label = ($rp['name'] ?? '') . ' (' . ($rp['type'] ?? '') . ' — VLESS/VMess)';
                $external_panel_options[(string) ($rp['code'] ?? '')] = $label;
            }
            if (!empty($remote)) {
                break;
            }
        }

        $current_provision = get_post_meta($post->ID, '_usk_provision_mode', true) ?: 'native';
        $current_ext_panel = get_post_meta($post->ID, '_usk_external_panel_code', true) ?: '';

        woocommerce_wp_select([
            'id'      => '_usk_panel_id',
            'label'   => __('اتصال UnlimitSky / پنل', 'unlimitsky-wc'),
            'options' => $options,
            'desc_tip'    => true,
            'description' => __('برای پروتکل native: UnlimitSky. برای Marzban/Sanaei روی VPS: همان اتصال API + حالت «پنل خارجی».', 'unlimitsky-wc'),
        ]);

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
            'description' => __('پنل‌های Marzban/Sanaei باید در پنل UnlimitSky روی VPS (Pro) متصل شده باشند.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'          => '_usk_external_panel_code',
            'label'       => __('پنل Marzban / Sanaei (روی VPS)', 'unlimitsky-wc'),
            'options'     => $external_panel_options,
            'value'       => $current_ext_panel,
            'wrapper_class' => 'usk-external-panel-field',
            'desc_tip'    => true,
            'description' => __('لیست از پنل UnlimitSky روی VPS خوانده می‌شود. فقط VLESS/VMess (Xray).', 'unlimitsky-wc'),
        ]);

        if (count($external_panel_options) <= 1) {
            echo '<p class="form-field usk-external-panel-field"><span class="description" style="color:#b32d2e;">';
            echo esc_html__('پنل Marzban/Sanaei در UnlimitSky VPS یافت نشد — ابتدا در پنل کلاینت (Pro) پنل را متصل کنید.', 'unlimitsky-wc');
            echo '</span></p>';
        }

        echo '<p class="form-field usk-xray-panel-note" style="display:none;"><span class="description">';
        echo esc_html__('این محصول روی پنل Marzban/Sanaei مستقیم ساخته می‌شود — فقط VLESS/VMess (Xray).', 'unlimitsky-wc');
        echo '</span></p>';

        woocommerce_wp_text_input([
            'id'                => '_usk_volume_gb',
            'label'             => __('حجم (GB)', 'unlimitsky-wc'),
            'type'              => 'number',
            'custom_attributes' => ['min' => '1', 'step' => '1'],
            'desc_tip'          => true,
            'description'       => __('حجم ترافیک قابل استفاده', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_text_input([
            'id'                => '_usk_duration_days',
            'label'             => __('مدت (روز)', 'unlimitsky-wc'),
            'type'              => 'number',
            'custom_attributes' => ['min' => '1', 'step' => '1'],
            'desc_tip'          => true,
            'description'       => __('مدت اعتبار سرویس به روز', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_text_input([
            'id'                => '_usk_plan_code',
            'label'             => __('کد پلن پنل (اختیاری)', 'unlimitsky-wc'),
            'type'              => 'text',
            'custom_attributes' => ['dir' => 'ltr'],
            'desc_tip'          => true,
            'description'       => __('کد پلن از پنل UnlimitSky → پلن‌ها. اگر پلن غیرفعال باشد، سفارش خودکار ساخته نمی‌شود.', 'unlimitsky-wc'),
        ]);

        woocommerce_wp_select([
            'id'      => '_usk_protocol',
            'label'   => __('پروتکل native (اختیاری)', 'unlimitsky-wc'),
            'options' => [
                ''          => __('— پیش‌فرض پنل —', 'unlimitsky-wc'),
                'wireguard' => 'WireGuard',
                'openvpn'   => 'OpenVPN',
                'xray'      => 'Xray (VLESS Reality)',
                'l2tp'      => 'L2TP/IPsec',
                'amnezia'   => 'Amnezia (AmneziaWG)',
            ],
            'wrapper_class' => 'usk-native-protocol-field',
            'desc_tip'    => true,
            'description' => __('فقط برای حالت «پروتکل native» — Marzban/Sanaei از VLESS/VMess پشتیبانی می‌کنند.', 'unlimitsky-wc'),
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
            'description'   => __('فقط برای محصولات OpenVPN — در ایران TCP معمولاً بهتر کار می‌کند.', 'unlimitsky-wc'),
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
            'description'   => __('TCP نیاز به udp2raw روی کلاینت دارد — فقط برای WireGuard.', 'unlimitsky-wc'),
        ]);

        echo '</div>';

        $panel_types_json = wp_json_encode($panel_types);

        wc_enqueue_js("
            var uskPanelTypes = {$panel_types_json};
            function uskPanelType() {
                var id = $('#_usk_panel_id').val();
                return uskPanelTypes[id] || '';
            }
            function UnlimitSkyToggleFields() {
                if ($('#_usk_is_vpn').is(':checked')) {
                    $('.unlimitsky-fields').show();
                } else {
                    $('.unlimitsky-fields').hide();
                }
            }
            function UnlimitSkyToggleProvision() {
                var ptype = uskPanelType();
                var mode = $('#_usk_provision_mode').val();
                var isUnlimitSky = ptype === 'unlimitsky';
                var isXrayPanel = ptype === 'marzban' || ptype === 'sanayi';
                $('.usk-provision-mode-field').toggle(isUnlimitSky);
                $('.usk-external-panel-field').toggle(isUnlimitSky && mode === 'external');
                $('.usk-native-protocol-field, .usk-openvpn-proto-field, .usk-wireguard-transport-field').toggle(isUnlimitSky && mode === 'native');
                $('.usk-xray-panel-note').toggle(isXrayPanel);
            }
            function UnlimitSkyToggleOpenvpnProto() {
                if ($('#_usk_protocol').val() === 'openvpn') {
                    $('.usk-openvpn-proto-field').show();
                } else {
                    $('.usk-openvpn-proto-field').hide();
                }
            }
            function UnlimitSkyToggleWireguardTransport() {
                if ($('#_usk_protocol').val() === 'wireguard') {
                    $('.usk-wireguard-transport-field').show();
                } else {
                    $('.usk-wireguard-transport-field').hide();
                }
            }
            $('#_usk_is_vpn').change(UnlimitSkyToggleFields);
            $('#_usk_panel_id, #_usk_provision_mode').change(function(){
                UnlimitSkyToggleProvision();
            });
            $('#_usk_protocol').change(function(){
                UnlimitSkyToggleOpenvpnProto();
                UnlimitSkyToggleWireguardTransport();
            });
            UnlimitSkyToggleFields();
            UnlimitSkyToggleProvision();
            UnlimitSkyToggleOpenvpnProto();
            UnlimitSkyToggleWireguardTransport();
        ");
    }

    public function save_product_fields(int $post_id): void
    {
        $is_vpn = isset($_POST['_usk_is_vpn']) ? 'yes' : 'no';
        update_post_meta($post_id, '_usk_is_vpn', $is_vpn);
        update_post_meta($post_id, '_usk_panel_id', absint($_POST['_usk_panel_id'] ?? 0));
        $provision_mode = sanitize_text_field($_POST['_usk_provision_mode'] ?? 'native');
        update_post_meta($post_id, '_usk_provision_mode', in_array($provision_mode, ['native', 'external'], true) ? $provision_mode : 'native');
        update_post_meta($post_id, '_usk_external_panel_code', preg_replace('/[^0-9]/', '', (string) ($_POST['_usk_external_panel_code'] ?? '')));
        update_post_meta($post_id, '_usk_volume_gb', absint($_POST['_usk_volume_gb'] ?? 0));
        update_post_meta($post_id, '_usk_duration_days', absint($_POST['_usk_duration_days'] ?? 0));
        update_post_meta($post_id, '_usk_plan_code', preg_replace('/[^0-9]/', '', (string) ($_POST['_usk_plan_code'] ?? '')));
        update_post_meta($post_id, '_usk_protocol', sanitize_text_field($_POST['_usk_protocol'] ?? ''));
        $ovpnProto = strtolower(sanitize_text_field($_POST['_usk_openvpn_proto'] ?? 'tcp'));
        update_post_meta($post_id, '_usk_openvpn_proto', in_array($ovpnProto, ['udp', 'tcp'], true) ? $ovpnProto : 'tcp');
        $wgTransport = strtolower(sanitize_text_field($_POST['_usk_wireguard_transport'] ?? 'tcp'));
        update_post_meta($post_id, '_usk_wireguard_transport', in_array($wgTransport, ['udp', 'tcp'], true) ? $wgTransport : 'tcp');
    }

    public function add_product_tab(array $tabs): array
    {
        $tabs['UnlimitSky'] = [
            'label'    => __('UnlimitSky VPN', 'unlimitsky-wc'),
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
        echo esc_html__('اتصال UnlimitSky (API) را از منوی پنل‌ها اضافه کنید. برای Marzban/Sanaei: در پنل VPS متصل کنید، سپس در محصول «پنل خارجی» را انتخاب کنید.', 'unlimitsky-wc');
        echo '</p></div>';
    }

    public function render_product_frontend_info(): void
    {
        global $product;
        if (!$product || $product->get_meta('_usk_is_vpn') !== 'yes') {
            return;
        }

        $panel_id      = (int) $product->get_meta('_usk_panel_id');
        $volume        = (int) $product->get_meta('_usk_volume_gb');
        $days          = (int) $product->get_meta('_usk_duration_days');
        $panel         = USK_Panel_Manager::get_panel($panel_id);

        echo '<div class="UnlimitSky-product-info" style="margin-bottom:1em;padding:1em;background:#f7f7f7;border-radius:4px;">';
        echo '<strong>' . esc_html__('مشخصات سرویس:', 'unlimitsky-wc') . '</strong><ul style="margin:0.5em 0 0;padding-right:1.2em;">';
        if ($panel) {
            echo '<li>' . esc_html__('سرور:', 'unlimitsky-wc') . ' ' . esc_html($panel['name']) . '</li>';
        }
        echo '<li>' . esc_html__('حجم:', 'unlimitsky-wc') . ' ' . esc_html($volume) . ' GB</li>';
        echo '<li>' . esc_html__('مدت:', 'unlimitsky-wc') . ' ' . esc_html($days) . ' ' . esc_html__('روز', 'unlimitsky-wc') . '</li>';
        echo '<li>' . esc_html__('تحویل: خودکار پس از پرداخت', 'unlimitsky-wc') . '</li>';
        echo '</ul></div>';
    }
}
