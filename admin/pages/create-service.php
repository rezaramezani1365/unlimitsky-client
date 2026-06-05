<?php
global $sql;
require_once dirname(__DIR__) . '/lib/protocols/provisioner.php';

$GLOBALS['page_title'] = __('nav_create');
$GLOBALS['active_nav'] = 'create-service';

$result = null;
USK_ProtocolManager::refresh_all_status();
$installed = USK_ProtocolManager::installed_protocols();
$protocolPortDefaults = array();
foreach (USK_ProtocolManager::list() as $pkey => $pmeta) {
    if (isset($installed[$pkey])) {
        $protocolPortDefaults[$pkey] = USK_ProtocolManager::port_defaults_for_create($pkey);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'native';
    $plan_source = $_POST['plan_source'] ?? 'plan';
    $volume_gb = (int) ($_POST['manual_volume_gb'] ?? 0);
    $duration_days = (int) ($_POST['manual_duration_days'] ?? 0);
    $plan_id = (int) ($_POST['plan_id'] ?? 0);
    $plan = null;

    if ($plan_source === 'plan') {
        $plan = $sql->query("SELECT * FROM `category` WHERE `row`=$plan_id AND `status`='active'")->fetch_assoc();
        if (!$plan) {
            usk_flash(__('create_plan_invalid'), 'error');
        } else {
            $volume_gb = (int) $plan['limit'];
            $duration_days = (int) $plan['date'];
        }
    } elseif ($volume_gb < 1 || $duration_days < 1) {
        usk_flash(__('create_manual_invalid'), 'error');
        $plan = null;
    } else {
        $plan = array('limit' => $volume_gb, 'date' => $duration_days, 'price' => '0', 'name' => 'manual');
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
                        $created['links'] ?: $created['subscription']
                    );
                    if (empty($order['ok'])) {
                        usk_flash(__('create_order_save_failed') . ' (' . ($order['error'] ?? '') . ')', 'error');
                    } else {
                        $raw = $created['raw'] ?? array();
                        $downloadUrl = USK_ProtocolProvisioner::finalize_order_link(
                            $protocol,
                            $raw,
                            $order['code'],
                            ''
                        );
                        if ($downloadUrl !== '') {
                            global $sql;
                            $dl_esc = $sql->real_escape_string($downloadUrl);
                            $code_esc = $sql->real_escape_string($order['code']);
                            $sql->query("UPDATE `orders` SET `link`='$dl_esc' WHERE `code`='$code_esc'");
                        }
                        $result = $created;
                        $result['code'] = $order['code'];
                        $result['protocol'] = $protocol;
                        $result['qr_png'] = $created['qr_png'] ?? '';
                        $result['vpn_uri'] = $created['vpn_uri'] ?? '';
                        $result['wg_conf'] = $created['wg_conf'] ?? '';
                        $result['expires_at'] = $created['expires_at'] ?? null;
                        $result['download_url'] = $downloadUrl;
                        $result['ovpn_filename'] = $raw['ovpn_filename'] ?? ($raw['conf_filename'] ?? ($username . ($protocol === 'amnezia' ? '.conf' : '.ovpn')));
                        $result['openvpn_proto'] = $raw['proto'] ?? ($provisionMeta['openvpn_proto'] ?? 'tcp');
                        $result['wireguard_transport'] = $raw['wireguard_transport'] ?? ($provisionMeta['wireguard_transport'] ?? '');
                        usk_flash(__('create_success'));
                    }
                }
            }
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
                    usk_flash(__('create_success'));
                }
            }
        }
    }
}

$panels = $sql->query("SELECT * FROM `panels` WHERE `status`='active'");
$plans = $sql->query("SELECT * FROM `category` WHERE `status`='active'");
?>
<div class="usk-card">
    <h3 class="mb-3"><?= __('create_title') ?></h3>
    <p class="text-muted small"><?= __('create_intro') ?></p>

    <form method="post" id="create-service-form">
        <div class="form-group mb-3">
            <label><?= __('create_mode') ?></label>
            <select class="form-control" name="mode" id="create-mode">
                <option value="native"><?= __('create_mode_native') ?></option>
                <option value="panel"><?= __('create_mode_panel') ?></option>
            </select>
        </div>

        <div class="form-group mb-3">
            <label><?= __('create_plan_source') ?></label>
            <select class="form-control" name="plan_source" id="plan-source">
                <option value="manual"><?= __('create_plan_manual') ?></option>
                <option value="plan"><?= __('create_plan_from_list') ?></option>
            </select>
        </div>

        <div class="form-row manual-plan-fields mb-3">
            <div class="form-group col-md-6">
                <label><?= __('volume') ?> (GB)</label>
                <input type="number" class="form-control" name="manual_volume_gb" min="1" value="30">
            </div>
            <div class="form-group col-md-6">
                <label><?= __('duration') ?> (<?= __('days') ?>)</label>
                <input type="number" class="form-control" name="manual_duration_days" min="1" value="30">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group native-field list-plan-field" style="display:none;">
                <label><?= __('nav_plans') ?></label>
                <select class="form-control" name="plan_id" id="plan-select">
                    <option value="">—</option>
                    <?php while ($pl = $plans->fetch_assoc()) : ?>
                        <option value="<?= (int) $pl['row'] ?>"><?= usk_esc($pl['name']) ?> — <?= usk_esc($pl['limit']) ?>GB / <?= usk_esc($pl['date']) ?> <?= __('days') ?></option>
                    <?php endwhile; ?>
                </select>
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
                        <option value="tcp" selected>TCP (<?= __('recommended_iran') ?>)</option>
                        <option value="udp">UDP</option>
                    </select>
                    <p class="text-muted small mt-1 mb-0"><?= __('create_wireguard_transport_hint') ?></p>
                </div>
                <div id="create-amnezia-hint" class="alert alert-info small py-2 px-3 mb-0 mt-2" style="display:none;">
                    <i class="fa-solid fa-circle-info"></i> <?= __('protocol_amnezia_note') ?>
                </div>
                <div id="create-l2tp-warning" class="alert alert-warning small py-2 px-3 mb-0 mt-2" style="display:none;">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= __('protocol_l2tp_iran_note') ?>
                </div>
            </div>
            <div class="form-group panel-field" style="display:none;">
                <label><?= __('create_panel_select') ?></label>
                <select class="form-control" name="panel_id">
                    <option value="">—</option>
                    <?php while ($p = $panels->fetch_assoc()) : ?>
                        <option value="<?= (int) $p['row'] ?>"><?= usk_esc($p['name']) ?> (<?= usk_esc($p['type']) ?>)</option>
                    <?php endwhile; ?>
                </select>
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
    function updatePortFields() {
        var proto = document.getElementById('native-protocol').value;
        var wrap = document.getElementById('create-port-fields');
        var single = document.getElementById('create-port-single');
        var xray = document.getElementById('create-port-xray');
        var fixed = document.getElementById('create-port-fixed');
        var openvpnProto = document.getElementById('create-openvpn-proto');
        var wgTransport = document.getElementById('create-wireguard-transport');
        var l2tpWarn = document.getElementById('create-l2tp-warning');
        var amneziaHint = document.getElementById('create-amnezia-hint');
        if (!wrap || !proto || !protocolPorts[proto]) {
            if (wrap) wrap.style.display = 'none';
            if (openvpnProto) openvpnProto.style.display = 'none';
            if (wgTransport) wgTransport.style.display = 'none';
            if (l2tpWarn) l2tpWarn.style.display = 'none';
            if (amneziaHint) amneziaHint.style.display = 'none';
            return;
        }
        wrap.style.display = '';
        single.style.display = 'none';
        xray.style.display = 'none';
        fixed.style.display = 'none';
        if (openvpnProto) openvpnProto.style.display = 'none';
        if (wgTransport) wgTransport.style.display = 'none';
        if (l2tpWarn) l2tpWarn.style.display = 'none';
        if (amneziaHint) amneziaHint.style.display = 'none';
        var cfg = protocolPorts[proto];
        if (proto === 'xray') {
            xray.style.display = '';
            document.getElementById('xray-ports-readonly').textContent =
                'VLESS ' + (cfg.vless_port || 2053) + ' · VMess ' + (cfg.vmess_port || 8443);
        } else if (proto === 'openvpn') {
            if (openvpnProto) openvpnProto.style.display = '';
            fixed.style.display = '';
            document.getElementById('create-port-fixed-text').textContent =
                'UDP ' + (cfg.udp_port || 1194) + ' · TCP ' + (cfg.tcp_port || 443);
        } else if (proto === 'wireguard') {
            if (wgTransport) wgTransport.style.display = '';
            fixed.style.display = '';
            var tcpP = cfg.tcp_port && cfg.tcp_port > 0 ? cfg.tcp_port : 51822;
            document.getElementById('create-port-fixed-text').textContent =
                'UDP ' + (cfg.port || 51820) + ' · TCP bridge ' + tcpP;
        } else if (proto === 'l2tp') {
            fixed.style.display = '';
            if (l2tpWarn) l2tpWarn.style.display = '';
            document.getElementById('create-port-fixed-text').textContent = cfg.fixed_ports || '500, 4500, 1701 (UDP)';
        } else if (proto === 'amnezia') {
            single.style.display = '';
            if (amneziaHint) amneziaHint.style.display = '';
            document.getElementById('custom-port').value = cfg.port || 443;
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
        <?php if (($result['protocol'] ?? '') === 'amnezia') : ?>
        <p class="mt-2"><strong><?= __('amnezia_qr') ?>:</strong></p>
        <p><img src="data:image/png;base64,<?= usk_esc($result['qr_png']) ?>" alt="Amnezia QR" style="max-width:220px;border:1px solid #333;padding:8px;background:#fff;" /></p>
        <p class="text-muted small"><?= __('amnezia_qr_hint') ?></p>
        <?php else : ?>
        <p class="mt-2"><strong><?= __('wireguard_qr') ?>:</strong></p>
        <p><img src="data:image/png;base64,<?= usk_esc($result['qr_png']) ?>" alt="QR" style="max-width:220px;border:1px solid #333;padding:8px;background:#fff;" /></p>
        <p class="text-muted small"><?= __('wireguard_qr_hint') ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($result['vpn_uri'])) : ?>
        <p class="mt-2"><strong><?= __('amnezia_import_link') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($result['vpn_uri']) ?></code>
        <p class="text-muted small"><?= __('amnezia_import_link_hint') ?></p>
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
    <?php if (!empty($result['download_url'])) : ?>
        <p class="mt-2">
            <a class="btn btn-usk-primary" href="<?= usk_esc($result['download_url']) ?>" download="<?= usk_esc($result['ovpn_filename'] ?? 'client.conf') ?>">
                <i class="fa-solid fa-download"></i> <?= ($result['protocol'] ?? '') === 'amnezia' ? __('download_amnezia_conf') : __('download_ovpn') ?>
            </a>
        </p>
    <?php endif; ?>
    <?php if (!empty($result['wg_conf']) && ($result['protocol'] ?? '') === 'amnezia') : ?>
        <p class="mt-2"><strong><?= __('amnezia_wg_conf') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($result['wg_conf']) ?></code>
        <p class="text-muted small"><?= __('amnezia_wg_conf_hint') ?></p>
    <?php endif; ?>
    <p class="mt-2"><strong><?= __('config_label') ?>:</strong></p>
    <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc(($result['protocol'] ?? '') === 'amnezia' ? ($result['config'] ?? $result['subscription']) : $result['subscription']) ?></code>
    <?php if (!empty($result['links']) && $result['links'] !== $result['subscription']) : ?>
        <p class="mt-2"><strong><?= __('links') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($result['links']) ?></code>
    <?php endif; ?>
    <p class="mt-3"><a class="btn btn-outline" href="<?= usk_admin_url('services') ?>"><?= __('nav_services') ?></a></p>
</div>
<?php endif; ?>
