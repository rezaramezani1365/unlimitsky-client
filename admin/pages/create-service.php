<?php
global $sql;
require_once dirname(__DIR__) . '/lib/protocols/provisioner.php';

$GLOBALS['page_title'] = __('nav_create');
$GLOBALS['active_nav'] = 'create-service';
$canUsePanels = USK_License::can_use_external_panels();
$canUseNodes = USK_Nodes::can_use_nodes();
$canUseManualPlan = USK_License::is_pro();
$nodeList = $canUseNodes ? USK_Nodes::list_for_select() : array();

$result = null;
if (!empty($_GET['created']) && !empty($_SESSION['usk_create_result'])) {
    $result = $_SESSION['usk_create_result'];
    unset($_SESSION['usk_create_result']);
}
USK_ProtocolManager::refresh_all_status();
$installed = USK_ProtocolManager::installed_protocols();
$protocolPortDefaults = array();
foreach (USK_ProtocolManager::list() as $pkey => $pmeta) {
    if (isset($installed[$pkey])) {
        $protocolPortDefaults[$pkey] = USK_ProtocolManager::port_defaults_for_create($pkey);
    }
}
$clientDnsPanel = USK_ClientDns::get();
$clientDnsForJs = array(
    'enabled' => !empty($clientDnsPanel['enabled']),
    'default' => $clientDnsPanel['default_dns'] ?? '',
    'by_protocol' => array(
        'xray' => USK_ClientDns::resolve('', 'xray'),
        'openvpn' => USK_ClientDns::resolve('', 'openvpn'),
        'wireguard' => USK_ClientDns::resolve('', 'wireguard'),
    ),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'native';
    $plan_source = $_POST['plan_source'] ?? 'plan';
    $volume_gb = (int) ($_POST['manual_volume_gb'] ?? 0);
    $duration_days = (int) ($_POST['manual_duration_days'] ?? 0);
    $plan_id = (int) ($_POST['plan_id'] ?? 0);
    $plan = null;
    $max_connections = 1;

    if ($plan_source !== 'plan' && !$canUseManualPlan) {
        usk_flash(__('create_manual_pro_required'), 'error');
        $plan = null;
    } elseif ($plan_source === 'plan') {
        $plan = $sql->query("SELECT * FROM `category` WHERE `row`=$plan_id AND `status`='active'")->fetch_assoc();
        if (!$plan) {
            usk_flash(__('create_plan_invalid'), 'error');
        } else {
            $volume_gb = (int) $plan['limit'];
            $duration_days = (int) $plan['date'];
            $max_connections = max(1, (int) ($plan['connections'] ?? 1));
        }
    } elseif ($volume_gb < 0 || $duration_days < 0) {
        usk_flash(__('create_manual_invalid'), 'error');
        $plan = null;
    } else {
        $plan = array('limit' => $volume_gb, 'date' => $duration_days, 'price' => '0', 'name' => 'manual');
        $max_connections = max(1, (int) ($_POST['manual_connections'] ?? 1));
    }

    if ($plan) {
        if ($mode === 'native') {
            $installed = USK_ProtocolManager::installed_protocols();
            $protocol = USK_ProtocolManager::sanitize_key($_POST['protocol'] ?? '');
            if ($protocol === '' || !isset($installed[$protocol])) {
                usk_flash(__('create_protocol_invalid'), 'error');
            } else {
                $code = (string) rand(111111, 999999);
                $username = usk_service_name($code, 'admin');
                $provisionMeta = array();
                $nodeBlocked = false;
                $rawCustomerEmail = trim((string) ($_POST['customer_email'] ?? ''));
                $customerEmail = USK_ProtocolProvisioner::sanitize_customer_email($rawCustomerEmail);
                if ($rawCustomerEmail !== '' && $customerEmail === '') {
                    usk_flash(__('create_customer_email_invalid'), 'error');
                    $nodeBlocked = true;
                } elseif ($customerEmail !== '') {
                    $provisionMeta['customer_email'] = $customerEmail;
                }
                if ($protocol !== 'xray' && !empty($_POST['custom_port'])) {
                    $provisionMeta['port'] = (int) $_POST['custom_port'];
                }
                if ($protocol === 'openvpn') {
                    $ovpnProto = strtolower((string) ($_POST['openvpn_proto'] ?? 'tcp'));
                    $provisionMeta['openvpn_proto'] = in_array($ovpnProto, array('udp', 'tcp'), true) ? $ovpnProto : 'tcp';
                }
                if ($protocol === 'wireguard') {
                    $wgTransport = strtolower((string) ($_POST['wireguard_transport'] ?? 'tcp'));
                    $provisionMeta['wireguard_transport'] = in_array($wgTransport, array('udp', 'tcp'), true) ? $wgTransport : 'tcp';
                }
                $clientDns = trim((string) ($_POST['client_dns'] ?? ''));
                if ($clientDns !== '') {
                    $provisionMeta['client_dns'] = preg_replace('/[^0-9a-zA-Z.,;:\- _]/', '', $clientDns);
                }
                $provisionMeta['max_connections'] = $max_connections;
                $nodeId = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['node_id'] ?? ''));
                if ($nodeId !== '') {
                    if (!$canUseNodes) {
                        usk_flash(__('nodes_pro_required'), 'error');
                        $nodeBlocked = true;
                    } elseif (!USK_Nodes::get($nodeId)) {
                        usk_flash(__('nodes_not_found'), 'error');
                        $nodeBlocked = true;
                    } elseif ($protocol !== 'xray') {
                        usk_flash(__('nodes_xray_only'), 'error');
                        $nodeBlocked = true;
                    } else {
                        $provisionMeta['node_id'] = $nodeId;
                    }
                }
                if (!$nodeBlocked) {
                $created = USK_Service::create_native($protocol, $volume_gb, $duration_days, $username, $provisionMeta);

                if (!$created['ok']) {
                    $msg = $created['error'] ?? __('create_failed');
                    usk_flash($msg, 'error');
                } else {
                    $order = USK_ProtocolProvisioner::save_order(
                        $protocol,
                        $username,
                        $volume_gb,
                        $duration_days,
                        $created['links'] ?: $created['subscription'],
                        'admin',
                        null,
                        array(
                            'max_connections' => $max_connections,
                            'node_name' => $created['node_name'] ?? '',
                            'node_id' => $created['node_id'] ?? '',
                            'customer_email' => $created['customer_email'] ?? ($provisionMeta['customer_email'] ?? ''),
                            'usage_id' => $created['usage_id'] ?? '',
                            'xray_email' => $created['xray_email'] ?? '',
                            'email' => $created['xray_email'] ?? ($created['customer_email'] ?? ''),
                        )
                    );
                    if (empty($order['ok'])) {
                        usk_flash(__('create_order_save_failed') . ' (' . ($order['error'] ?? '') . ')', 'error');
                    } else {
                        $raw = $created['raw'] ?? array();
                        $portalUrl = USK_ProtocolProvisioner::finalize_order_link(
                            $protocol,
                            $raw,
                            $order['code'],
                            ''
                        );
                        $fileDownloadUrl = USK_ProtocolProvisioner::config_file_download_url($order['code'], $raw);
                        if ($portalUrl !== '') {
                            global $sql;
                            $dl_esc = $sql->real_escape_string($portalUrl);
                            $code_esc = $sql->real_escape_string($order['code']);
                            $sql->query("UPDATE `orders` SET `link`='$dl_esc' WHERE `code`='$code_esc'");
                        }
                        unset($created['raw']);
                        $result = $created;
                        $result['code'] = $order['code'];
                        $result['protocol'] = $protocol;
                        $result['qr_png'] = $created['qr_png'] ?? '';
                        $result['vpn_uri'] = $created['vpn_uri'] ?? '';
                        $result['wg_conf'] = $created['wg_conf'] ?? '';
                        $result['expires_at'] = $created['expires_at'] ?? null;
                        $result['portal_url'] = $portalUrl;
                        $result['download_url'] = $fileDownloadUrl;
                        $result['max_connections'] = $max_connections;
                        $result['ovpn_filename'] = $raw['ovpn_filename'] ?? ($raw['json_filename'] ?? ($raw['conf_filename'] ?? ($username . ($protocol === 'xray' ? '.json' : '.ovpn'))));
                        $result['client_dns'] = $raw['client_dns'] ?? ($provisionMeta['client_dns'] ?? '');
                        $result['customer_email'] = $created['customer_email'] ?? ($provisionMeta['customer_email'] ?? '');
                        $result['usage_id'] = $created['usage_id'] ?? '';
                        $result['xray_email'] = $created['xray_email'] ?? '';
                        $result['vless'] = $raw['vless'] ?? ($created['subscription'] ?? '');
                        $result['openvpn_proto'] = $raw['proto'] ?? ($provisionMeta['openvpn_proto'] ?? 'tcp');
                        $result['wireguard_transport'] = $raw['wireguard_transport'] ?? ($provisionMeta['wireguard_transport'] ?? '');
                        $_SESSION['usk_create_result'] = $result;
                        usk_flash(__('create_success'));
                        header('Location: ' . usk_admin_url('create-service', array('created' => '1')));
                        exit;
                    }
                }
                }
            }
        } else {
            $lic = USK_License::assert_can_use_external_panels(true);
            if (empty($lic['ok'])) {
                usk_flash(__('panels_pro_required'), 'error');
            } else {
            $panel_id = (int) ($_POST['panel_id'] ?? 0);
            $panel = $sql->query("SELECT * FROM `panels` WHERE `row`=$panel_id AND `status`='active'")->fetch_assoc();

            if (!$panel) {
                usk_flash(__('create_panel_invalid'), 'error');
            } else {
                $code = (string) rand(111111, 999999);
                $username = usk_service_name($code, 'admin');
                $created = USK_Service::create_on_panel($panel, $volume_gb, $duration_days, $username);

                if (!$created['ok']) {
                    usk_flash($created['error'] ?? __('create_failed'), 'error');
                } else {
                    $from_id = '0';
                    $location = $sql->real_escape_string($panel['name']);
                    $volume = $sql->real_escape_string((string) $volume_gb);
                    $date = $sql->real_escape_string((string) $duration_days);
                    $link = $sql->real_escape_string($created['links'] ?: $created['subscription']);
                    $price = $sql->real_escape_string($plan['price'] ?? '0');
                    $ptype = $sql->real_escape_string($panel['type']);
                    $sql->query("INSERT INTO `orders` (`from_id`,`location`,`protocol`,`date`,`volume`,`link`,`price`,`code`,`status`,`type`) VALUES ('$from_id','$location','null','$date','$volume','$link','$price','$code','active','$ptype')");
                    $sql->query("UPDATE `panels` SET `count_create` = count_create + 1 WHERE `row`={$panel['row']}");
                    $result = $created;
                    $result['code'] = $code;
                    $result['panel_name'] = $panel['name'];
                    $result['panel_type'] = $panel['type'];
                    $_SESSION['usk_create_result'] = $result;
                    usk_flash(__('create_success'));
                    header('Location: ' . usk_admin_url('create-service', array('created' => '1')));
                    exit;
                }
            }
            }
        }
    }
}

$panelRows = array();
if ($canUsePanels) {
    $panelRes = $sql->query("SELECT * FROM `panels` WHERE `status`='active'");
    if ($panelRes) {
        while ($row = $panelRes->fetch_assoc()) {
            $panelRows[] = $row;
        }
    }
}
$plans = $sql->query("SELECT * FROM `category` WHERE `status`='active'");
?>
<div class="usk-card">
    <h3 class="mb-3"><?= __('create_title') ?></h3>
    <p class="text-muted small"><?= __('create_intro') ?></p>
    <p class="alert alert-usk-info small py-2 px-3 mb-3">
        <i class="fa-solid fa-server"></i>
        <?= __('create_connect_host_hint') ?>
        <code dir="ltr"><?= usk_esc(USK_ConnectHost::display()) ?></code>
        — <a href="<?= usk_admin_url('settings') ?>#connect-host"><?= __('settings_connect_host_link') ?></a>
    </p>

    <?php if (!$canUsePanels) : ?>
    <div class="alert alert-usk-info small py-2 px-3 mb-3">
        <i class="fa-solid fa-crown"></i> <?= __('panels_pro_banner') ?>
        <a href="<?= usk_admin_url('license') ?>" class="ms-1"><?= __('panels_pro_activate') ?></a>
    </div>
    <?php endif; ?>

    <form method="post" id="create-service-form">
        <div class="form-group mb-3">
            <label><?= __('create_mode') ?></label>
            <select class="form-control" name="mode" id="create-mode">
                <option value="native"><?= __('create_mode_native') ?></option>
                <?php if ($canUsePanels) : ?>
                <option value="panel"><?= __('create_mode_panel') ?></option>
                <?php endif; ?>
            </select>
        </div>

        <div class="form-group mb-3">
            <label><?= __('create_plan_source') ?></label>
            <select class="form-control" name="plan_source" id="plan-source">
                <option value="manual"<?= $canUseManualPlan ? '' : ' disabled' ?>><?= __('create_plan_manual') ?><?= $canUseManualPlan ? '' : ' — PRO' ?></option>
                <option value="plan"<?= $canUseManualPlan ? '' : ' selected' ?>><?= __('create_plan_from_list') ?></option>
            </select>
            <?php if (!$canUseManualPlan) : ?>
            <p class="text-muted small mt-1 mb-0">
                <i class="fa-solid fa-crown"></i> <?= __('create_manual_pro_note') ?>
                <a href="<?= usk_admin_url('license') ?>" class="ms-1"><?= __('panels_pro_activate') ?></a>
            </p>
            <?php endif; ?>
        </div>

        <div class="form-row manual-plan-fields mb-3">
            <div class="form-group col-md-6">
                <label><?= __('volume') ?> (GB)</label>
                <input type="number" class="form-control" name="manual_volume_gb" min="0" value="30">
                <small class="text-muted"><?= __('plan_volume_hint') ?></small>
            </div>
            <div class="form-group col-md-6">
                <label><?= __('duration') ?> (<?= __('days') ?>)</label>
                <input type="number" class="form-control" name="manual_duration_days" min="0" value="30">
                <small class="text-muted"><?= __('plan_duration_hint') ?></small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group native-field list-plan-field" style="display:none;">
                <label><?= __('nav_plans') ?></label>
                <select class="form-control" name="plan_id" id="plan-select">
                    <option value="">—</option>
                    <?php while ($pl = $plans->fetch_assoc()) : ?>
                        <option value="<?= (int) $pl['row'] ?>"><?= usk_esc($pl['name']) ?> — <?= usk_esc(usk_format_plan_limits($pl['limit'], $pl['date'])) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group native-field" id="create-node-wrap" style="display:none;">
                <label><?= __('create_node_select') ?></label>
                <select class="form-control" name="node_id" id="create-node-select">
                    <option value=""><?= __('create_node_main') ?></option>
                    <?php foreach ($nodeList as $n) : ?>
                        <option value="<?= usk_esc($n['id']) ?>"><?= usk_esc($n['name']) ?> — <?= usk_esc($n['connect_host']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($canUseNodes && empty($nodeList)) : ?>
                    <p class="text-muted small mt-1"><a href="<?= usk_admin_url('nodes') ?>"><?= __('nav_nodes') ?></a></p>
                <?php elseif (!$canUseNodes) : ?>
                    <p class="text-muted small mt-1"><?= __('nodes_pro_required') ?></p>
                <?php else : ?>
                    <p class="text-muted small mt-1 mb-0"><?= __('create_node_hint') ?></p>
                <?php endif; ?>
            </div>
            <div class="form-group native-field">
                <label><?= __('protocol') ?></label>
                <select class="form-control" name="protocol" id="native-protocol">
                    <option value="">—</option>
                    <?php foreach ($installed as $key => $meta) : ?>
                        <option value="<?= usk_esc($key) ?>"><?= usk_esc($meta['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($installed)) : ?>
                    <p class="text-muted small mt-1"><?= __('create_no_protocols') ?> <a href="<?= usk_admin_url('protocols') ?>"><?= __('nav_protocols') ?></a></p>
                <?php endif; ?>
            </div>
            <div class="form-group native-field" id="create-customer-email-wrap">
                <label><?= __('create_customer_email') ?></label>
                <input type="email" class="form-control" name="customer_email" id="customer-email-input" placeholder="<?= __('create_customer_email_placeholder') ?>" dir="ltr" style="text-align:left;">
                <p class="text-muted small mt-1 mb-0"><?= __('create_customer_email_hint') ?></p>
            </div>
            <div class="form-group native-field" id="create-port-fields" style="display:none;">
                <label><?= __('create_config_port') ?></label>
                <div id="create-port-single" style="display:none;">
                    <input type="number" class="form-control" name="custom_port" id="custom-port" min="1" max="65535">
                    <p class="text-muted small mt-1"><?= __('create_config_port_hint') ?></p>
                </div>
                <div id="create-port-xray" style="display:none;">
                    <p class="mb-1"><code class="usk-code" id="xray-ports-readonly" style="direction:ltr"></code></p>
                    <p class="text-muted small mb-0"><?= __('create_xray_ports_hint') ?></p>
                </div>
                <div id="create-port-fixed" style="display:none;">
                    <p class="text-muted small mb-0" id="create-port-fixed-text"></p>
                </div>
                <div id="create-openvpn-proto" style="display:none;">
                    <label class="small mb-1"><?= __('create_openvpn_proto') ?></label>
                    <select class="form-control" name="openvpn_proto" id="openvpn-proto-select">
                        <option value="tcp" selected>TCP (<?= __('recommended_iran') ?>)</option>
                        <option value="udp">UDP</option>
                    </select>
                    <p class="text-muted small mt-1 mb-0"><?= __('create_openvpn_proto_hint') ?></p>
                </div>
                <div id="create-wireguard-transport" style="display:none;">
                    <label class="small mb-1"><?= __('create_wireguard_transport') ?></label>
                    <select class="form-control" name="wireguard_transport" id="wireguard-transport-select">
                        <option value="udp" selected>UDP</option>
                        <option value="tcp">TCP (<?= __('recommended_iran') ?>)</option>
                    </select>
                    <p class="text-muted small mt-1 mb-0"><?= __('create_wireguard_transport_hint') ?></p>
                </div>
                <div id="create-l2tp-warning" class="alert alert-warning small py-2 px-3 mb-0 mt-2" style="display:none;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= __('protocol_l2tp_iran_note') ?>
                </div>
                <div id="create-xray-hint" class="alert alert-info small py-2 px-3 mb-0 mt-2" style="display:none;">
                    <i class="fa-solid fa-circle-info"></i> <?= __('protocol_xray_iran_note') ?>
                </div>
            </div>
            <div class="form-group native-field" id="create-client-dns-wrap" style="display:none;">
                <label><?= __('create_client_dns') ?></label>
                <input type="text" class="form-control" name="client_dns" id="client-dns-input" placeholder="<?= __('create_client_dns_placeholder') ?>" dir="ltr" style="text-align:left;">
                <p class="text-muted small mt-1 mb-0"><?= __('create_client_dns_hint') ?> <a href="<?= usk_admin_url('settings') ?>#client-dns"><?= __('settings_client_dns_link') ?></a></p>
            </div>
            <div class="form-group panel-field" style="display:none;">
                <label><?= __('create_panel_select') ?></label>
                <select class="form-control" name="panel_id">
                    <option value="">—</option>
                    <?php foreach ($panelRows as $p) : ?>
                        <option value="<?= (int) $p['row'] ?>"><?= usk_esc($p['name']) ?> (<?= usk_esc($p['type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php if ($canUsePanels && empty($panelRows)) : ?>
                    <p class="text-muted small mt-1"><a href="<?= usk_admin_url('panels') ?>"><?= __('nav_panels') ?></a></p>
                <?php endif; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-usk-primary">⚡ <?= __('create_submit') ?></button>
    </form>
</div>

<script>
(function(){
    var mode = document.getElementById('create-mode');
    var planSource = document.getElementById('plan-source');
    var protocolPorts = <?= json_encode($protocolPortDefaults, JSON_UNESCAPED_UNICODE) ?>;
    var clientDnsCfg = <?= json_encode($clientDnsForJs, JSON_UNESCAPED_UNICODE) ?>;
    function applyPanelDnsPlaceholder(proto) {
        var inp = document.getElementById('client-dns-input');
        if (!inp || !clientDnsCfg.enabled) return;
        var val = (clientDnsCfg.by_protocol && clientDnsCfg.by_protocol[proto]) || clientDnsCfg.default || '';
        if (val && !inp.value) {
            inp.value = val;
        }
    }
    function updatePortFields() {
        var proto = document.getElementById('native-protocol').value;
        var wrap = document.getElementById('create-port-fields');
        var single = document.getElementById('create-port-single');
        var xray = document.getElementById('create-port-xray');
        var fixed = document.getElementById('create-port-fixed');
        var openvpnProto = document.getElementById('create-openvpn-proto');
        var wgTransport = document.getElementById('create-wireguard-transport');
        var l2tpWarn = document.getElementById('create-l2tp-warning');
        var xrayHint = document.getElementById('create-xray-hint');
        var dnsWrap = document.getElementById('create-client-dns-wrap');
        var customerEmail = document.getElementById('customer-email-input');
        if (customerEmail) customerEmail.required = (proto === 'xray');
        if (!wrap || !proto || !protocolPorts[proto]) {
            if (wrap) wrap.style.display = 'none';
            if (openvpnProto) openvpnProto.style.display = 'none';
            if (wgTransport) wgTransport.style.display = 'none';
            if (l2tpWarn) l2tpWarn.style.display = 'none';
            if (xrayHint) xrayHint.style.display = 'none';
            if (dnsWrap) dnsWrap.style.display = 'none';
            if (customerEmail) customerEmail.required = false;
            return;
        }
        wrap.style.display = '';
        single.style.display = 'none';
        xray.style.display = 'none';
        fixed.style.display = 'none';
        if (openvpnProto) openvpnProto.style.display = 'none';
        if (wgTransport) wgTransport.style.display = 'none';
        if (l2tpWarn) l2tpWarn.style.display = 'none';
        if (xrayHint) xrayHint.style.display = 'none';
        if (dnsWrap) dnsWrap.style.display = 'none';
        var cfg = protocolPorts[proto];
        if (proto === 'xray') {
            xray.style.display = '';
            if (xrayHint) xrayHint.style.display = '';
            if (dnsWrap) { dnsWrap.style.display = ''; applyPanelDnsPlaceholder(proto); }
            document.getElementById('xray-ports-readonly').textContent =
                'VLESS Reality · TCP ' + (cfg.vless_port || 443);
        } else if (proto === 'openvpn') {
            if (openvpnProto) openvpnProto.style.display = '';
            if (dnsWrap) { dnsWrap.style.display = ''; applyPanelDnsPlaceholder(proto); }
            fixed.style.display = '';
            document.getElementById('create-port-fixed-text').textContent =
                'UDP ' + (cfg.udp_port || 1194) + ' · TCP ' + (cfg.tcp_port || 443);
        } else if (proto === 'wireguard') {
            if (wgTransport) wgTransport.style.display = '';
            if (dnsWrap) { dnsWrap.style.display = ''; applyPanelDnsPlaceholder(proto); }
            fixed.style.display = '';
            var tcpP = cfg.tcp_port && cfg.tcp_port > 0 ? cfg.tcp_port : 51822;
            document.getElementById('create-port-fixed-text').textContent =
                'UDP ' + (cfg.port || 51820) + ' · TCP bridge ' + tcpP;
        } else if (proto === 'l2tp') {
            fixed.style.display = '';
            if (l2tpWarn) l2tpWarn.style.display = '';
            document.getElementById('create-port-fixed-text').textContent = cfg.fixed_ports || '500, 4500, 1701 (UDP)';
        } else if (cfg.fixed_ports) {
            fixed.style.display = '';
            document.getElementById('create-port-fixed-text').textContent = cfg.fixed_ports;
        } else if (cfg.port) {
            single.style.display = '';
            document.getElementById('custom-port').value = cfg.port;
        }
    }
    function toggle() {
        var isNative = mode.value === 'native';
        var isManual = planSource.value === 'manual';
        document.querySelectorAll('.native-field').forEach(function(el){ el.style.display = isNative ? '' : 'none'; });
        document.querySelectorAll('.panel-field').forEach(function(el){ el.style.display = isNative ? 'none' : ''; });
        document.querySelectorAll('.manual-plan-fields').forEach(function(el){ el.style.display = isManual ? '' : 'none'; });
        document.querySelectorAll('.list-plan-field').forEach(function(el){ el.style.display = (!isManual && isNative) || (!isManual && !isNative) ? '' : 'none'; });
        var proto = document.getElementById('native-protocol');
        if (proto) proto.required = isNative;
        var panel = document.querySelector('[name=panel_id]');
        if (panel) panel.required = !isNative;
        var planSel = document.getElementById('plan-select');
        if (planSel) planSel.required = !isManual;
        if (isNative) updatePortFields();
    }
    mode.addEventListener('change', toggle);
    planSource.addEventListener('change', toggle);
    var protoEl = document.getElementById('native-protocol');
    if (protoEl) protoEl.addEventListener('change', updatePortFields);
    toggle();
})();
</script>

<?php if ($result) : ?>
<div class="usk-card mt-4" style="border-color:var(--success);">
    <h3 class="text-success mb-3">✅ <?= __('create_ready') ?></h3>
    <p><strong><?= __('code') ?>:</strong> <?= usk_esc($result['code']) ?></p>
    <?php if (!empty($result['expires_at'])) : ?>
        <p><strong><?= __('expires_at') ?>:</strong> <?= usk_esc($result['expires_at']) ?></p>
    <?php endif; ?>
    <?php if (!empty($result['qr_png'])) : ?>
        <?php if (($result['protocol'] ?? '') === 'xray') : ?>
        <p class="mt-2"><strong><?= __('portal_qr_hint') ?>:</strong></p>
        <p><img src="data:image/png;base64,<?= usk_esc($result['qr_png']) ?>" alt="VLESS QR" style="max-width:220px;border:1px solid #333;padding:8px;background:#fff;" /></p>
        <?php else : ?>
        <p class="mt-2"><strong><?= __('wireguard_qr') ?>:</strong></p>
        <p><img src="data:image/png;base64,<?= usk_esc($result['qr_png']) ?>" alt="QR" style="max-width:220px;border:1px solid #333;padding:8px;background:#fff;" /></p>
        <p class="text-muted small"><?= __('wireguard_qr_hint') ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($result['panel_name'])) : ?>
        <p><strong><?= __('create_panel_select') ?>:</strong> <?= usk_esc($result['panel_name']) ?> (<?= usk_esc($result['panel_type'] ?? '') ?>)</p>
    <?php endif; ?>
    <?php if (!empty($result['protocol'])) : ?>
        <p><strong><?= __('protocol') ?>:</strong> <?= usk_esc($result['protocol']) ?>
        <?php if (!empty($result['openvpn_proto'])) : ?>
            (<?= strtoupper(usk_esc($result['openvpn_proto'])) ?>)
        <?php elseif (!empty($result['wireguard_transport'])) : ?>
            (<?= strtoupper(usk_esc($result['wireguard_transport'])) ?>)
        <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($result['client_dns'])) : ?>
        <p><strong><?= __('create_client_dns') ?>:</strong> <code><?= usk_esc($result['client_dns']) ?></code></p>
    <?php endif; ?>
    <?php if (!empty($result['customer_email'])) : ?>
        <p><strong><?= __('create_customer_email') ?>:</strong> <code><?= usk_esc($result['customer_email']) ?></code></p>
    <?php endif; ?>
    <?php if (!empty($result['xray_email']) && ($result['protocol'] ?? '') === 'xray') : ?>
        <p><strong><?= __('create_xray_usage_id') ?>:</strong> <code><?= usk_esc($result['xray_email']) ?></code></p>
    <?php endif; ?>
    <?php if (!empty($result['vless']) && ($result['protocol'] ?? '') === 'xray') : ?>
        <p class="mt-2"><strong><?= __('xray_vless_link') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($result['vless']) ?></code>
        <p class="text-muted small"><?= __('xray_vless_hint') ?></p>
    <?php endif; ?>
    <?php if (!empty($result['portal_url'])) : ?>
        <p class="mt-2"><strong><?= __('portal_customer_link') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($result['portal_url']) ?></code>
        <p class="text-muted small"><?= __('portal_customer_link_hint') ?></p>
        <p><a class="btn btn-usk-primary" href="<?= usk_esc($result['portal_url']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-external-link"></i> <?= __('portal_open_page') ?></a></p>
    <?php endif; ?>
    <?php if (!empty($result['max_connections'])) : ?>
        <p><strong><?= __('portal_max_connections') ?>:</strong> <?= (int) $result['max_connections'] ?> <?= __('plan_connections_unit') ?></p>
    <?php endif; ?>
    <?php if (!empty($result['download_url'])) : ?>
        <p class="mt-2">
            <a class="btn btn-usk-primary" href="<?= usk_esc($result['download_url']) ?>" download="<?= usk_esc($result['ovpn_filename'] ?? 'client.conf') ?>">
                <i class="fa-solid fa-download"></i>                 <?php
                if (($result['protocol'] ?? '') === 'xray') {
                    echo __('download_xray_json');
                } else {
                    echo __('download_ovpn');
                }
                ?>
            </a>
        </p>
    <?php endif; ?>
    <?php
    $resultProto = (string) ($result['protocol'] ?? '');
    $configPreview = '';
    if ($resultProto === 'xray') {
        $configPreview = (string) ($result['vless'] ?? ($result['subscription'] ?? ''));
    } else {
        $configPreview = (string) ($result['subscription'] ?? ($result['links'] ?? ''));
    }
    $linksExtra = (string) ($result['links'] ?? '');
    $subscriptionText = (string) ($result['subscription'] ?? '');
    ?>
    <?php if ($configPreview !== '' && !($resultProto === 'xray' && !empty($result['vless']))) : ?>
        <p class="mt-2"><strong><?= __('config_label') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($configPreview) ?></code>
    <?php endif; ?>
    <?php if ($linksExtra !== '' && $linksExtra !== $subscriptionText && $linksExtra !== $configPreview) : ?>
        <p class="mt-2"><strong><?= __('links') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($linksExtra) ?></code>
    <?php endif; ?>
    <p class="mt-3"><a class="btn btn-outline" href="<?= usk_admin_url('services') ?>"><?= __('nav_services') ?></a></p>
</div>
<?php endif; ?>
