<?php
global $sql;
require_once dirname(__DIR__) . '/lib/protocols/provisioner.php';

$GLOBALS['page_title'] = __('nav_create');
$GLOBALS['active_nav'] = 'create-service';

$result = null;
$installed = USK_ProtocolManager::installed_protocols();

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
            $protocol = preg_replace('/[^a-z]/', '', $_POST['protocol'] ?? '');
            if ($protocol === '' || !isset($installed[$protocol])) {
                usk_flash(__('create_protocol_invalid'), 'error');
            } else {
                $code = (string) rand(111111, 999999);
                $username = usk_service_name($code, 'admin');
                $created = USK_Service::create_native($protocol, $volume_gb, $duration_days, $username);

                if (!$created['ok']) {
                    usk_flash($created['error'] ?? __('create_failed'), 'error');
                } else {
                    $order = USK_ProtocolProvisioner::save_order(
                        $protocol,
                        $username,
                        $volume_gb,
                        $duration_days,
                        $created['links'] ?: $created['subscription']
                    );
                    $result = $created;
                    $result['code'] = $order['code'];
                    $result['protocol'] = $protocol;
                    $result['qr_png'] = $created['qr_png'] ?? '';
                    $result['expires_at'] = $created['expires_at'] ?? null;
                    usk_flash(__('create_success'));
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
    }
    mode.addEventListener('change', toggle);
    planSource.addEventListener('change', toggle);
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
        <p class="mt-2"><strong><?= __('wireguard_qr') ?>:</strong></p>
        <p><img src="data:image/png;base64,<?= usk_esc($result['qr_png']) ?>" alt="QR" style="max-width:220px;border:1px solid #333;padding:8px;background:#fff;" /></p>
        <p class="text-muted small"><?= __('wireguard_qr_hint') ?></p>
    <?php endif; ?>
    <?php if (!empty($result['protocol'])) : ?>
        <p><strong><?= __('protocol') ?>:</strong> <?= usk_esc($result['protocol']) ?></p>
    <?php endif; ?>
    <p class="mt-2"><strong><?= __('config_label') ?>:</strong></p>
    <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($result['subscription']) ?></code>
    <?php if (!empty($result['links']) && $result['links'] !== $result['subscription']) : ?>
        <p class="mt-2"><strong><?= __('links') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($result['links']) ?></code>
    <?php endif; ?>
</div>
<?php endif; ?>
