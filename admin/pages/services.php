<?php
global $sql;
require_once dirname(__DIR__) . '/lib/protocols/limits.php';

$GLOBALS['page_title'] = __('nav_services');
$GLOBALS['active_nav'] = 'services';

$view = (int) ($_GET['view'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $order = $id ? $sql->query("SELECT * FROM `orders` WHERE `row`=$id")->fetch_assoc() : null;
    $native = $order ? USK_ProtocolLimits::find_client_for_order($order) : null;

    if ($action === 'delete_record' && $order) {
        $sql->query("DELETE FROM `orders` WHERE `row`=$id");
        usk_flash(__('service_record_deleted'));
        header('Location: ' . usk_admin_url('services'));
        exit;
    }

    if ($action === 'remove_server' && $native) {
        USK_ProtocolLimits::remove_from_server($native['protocol'], $native['username']);
        usk_flash(__('service_removed_server'));
        header('Location: ' . usk_admin_url('services', ['view' => $id]));
        exit;
    }

    if ($action === 'extend' && $native) {
        $days = (int) ($_POST['extra_days'] ?? 0);
        $gb = (int) ($_POST['extra_gb'] ?? 0);
        $res = USK_ProtocolLimits::extend_client($native['protocol'], $native['username'], $days, $gb);
        if (!empty($res['ok'])) {
            usk_flash(__('service_extended'));
        } else {
            usk_flash($res['error'] ?? __('service_extend_failed'), 'error');
        }
        header('Location: ' . usk_admin_url('services', ['view' => $id]));
        exit;
    }
}

$s = null;
if ($view) {
    $s = $sql->query("SELECT * FROM `orders` WHERE `row`=$view")->fetch_assoc();
}
$native_info = $s ? USK_ProtocolLimits::find_client_for_order($s) : null;

$filter = preg_replace('/[^a-z_]/', '', $_GET['filter'] ?? 'all');
$allowed_filters = array('all', 'active', 'expired', 'volume_exceeded', 'ended');
if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}

$count_expired = (int) $sql->query("SELECT COUNT(*) c FROM `orders` WHERE `status`='expired'")->fetch_assoc()['c'];
$count_volume = (int) $sql->query("SELECT COUNT(*) c FROM `orders` WHERE `status`='volume_exceeded'")->fetch_assoc()['c'];
$count_ended = $count_expired + $count_volume;

$where = '1=1';
if ($filter === 'active') {
    $where = "`status`='active'";
} elseif ($filter === 'expired') {
    $where = "`status`='expired'";
} elseif ($filter === 'volume_exceeded') {
    $where = "`status`='volume_exceeded'";
} elseif ($filter === 'ended') {
    $where = "`status` IN ('expired','volume_exceeded')";
}

$list = $sql->query("SELECT * FROM `orders` WHERE $where ORDER BY `row` DESC LIMIT 200");
$list_count = $list ? $list->num_rows : 0;

function usk_service_status_badge($status)
{
    $map = array(
        'active' => 'success',
        'expired' => 'warning',
        'volume_exceeded' => 'warning',
        'revoked' => 'danger',
    );
    $cls = $map[$status] ?? 'danger';
    $label = __('status_' . $status);
    if ($label === 'status_' . $status) {
        $label = $status;
    }
    return array('class' => $cls, 'label' => $label);
}
?>
<?php if (!empty($s)) :
    $badge = usk_service_status_badge($s['status']);
    $client = $native_info['client'] ?? null;
?>
<div class="usk-card">
    <h3><?= __('service_detail') ?> #<?= usk_esc($s['code']) ?></h3>
    <p><strong><?= __('server') ?>:</strong> <?= usk_esc($s['location']) ?></p>
    <p><strong><?= __('volume') ?>:</strong> <?= usk_esc($s['volume']) ?> GB — <strong><?= __('duration') ?>:</strong> <?= usk_esc($s['date']) ?> <?= __('days') ?></p>
    <p><strong><?= __('type') ?>:</strong> <?= usk_esc($s['type']) ?>
        <?php if (!empty($s['protocol']) && $s['protocol'] !== 'null') : ?>
            / <?= usk_esc($s['protocol']) ?>
        <?php endif; ?>
    </p>
    <p><strong><?= __('status') ?>:</strong> <span class="badge badge-<?= $badge['class'] ?>"><?= usk_esc($badge['label']) ?></span></p>

    <?php if ($client) : ?>
        <p><strong><?= __('service_username') ?>:</strong> <code><?= usk_esc($native_info['username']) ?></code></p>
        <?php if (!empty($client['expires_at'])) : ?>
            <p><strong><?= __('expires_at') ?>:</strong> <?= usk_esc($client['expires_at']) ?></p>
        <?php endif; ?>
        <?php if (($s['protocol'] ?? '') === 'wireguard' && !empty($client['volume_gb'])) :
            $used = USK_ProtocolLimits::wireguard_usage_bytes($client);
        ?>
            <p><strong><?= __('traffic_used') ?>:</strong>
                <?= $used !== null ? usk_esc(USK_ProtocolLimits::format_bytes($used)) : '—' ?>
                / <?= usk_esc($client['volume_gb']) ?> GB
            </p>
        <?php endif; ?>
        <?php if (($client['status'] ?? '') === 'expired' || ($client['status'] ?? '') === 'volume_exceeded') : ?>
            <div class="alert alert-warning mt-3"><?= __('service_disabled_notice') ?></div>
        <?php endif; ?>

        <div class="usk-card mt-4">
            <div class="usk-card-header"><?= __('service_extend_title') ?></div>
            <div class="p-3">
                <p class="text-muted small"><?= __('service_extend_hint') ?></p>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="extend">
                    <input type="hidden" name="id" value="<?= (int) $s['row'] ?>">
                    <div class="col-auto">
                        <label class="form-label small"><?= __('extra_days') ?></label>
                        <input type="number" name="extra_days" class="form-control" min="0" value="30">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small"><?= __('extra_gb') ?></label>
                        <input type="number" name="extra_gb" class="form-control" min="0" value="0">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-usk-primary"><?= __('service_extend_btn') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (($client['status'] ?? '') !== 'revoked') : ?>
        <form method="post" class="mt-3" onsubmit="return confirm('<?= usk_esc(__('service_remove_confirm')) ?>')">
            <input type="hidden" name="action" value="remove_server">
            <input type="hidden" name="id" value="<?= (int) $s['row'] ?>">
            <button type="submit" class="btn btn-outline-danger"><?= __('service_remove_server') ?></button>
            <span class="text-muted small ms-2"><?= __('service_remove_hint') ?></span>
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    require_once dirname(__DIR__) . '/lib/service-config-view.php';
    $protocol = (string) ($s['protocol'] ?? '');
    $downloadUrl = usk_service_download_url($s, $client);
    $downloadFilename = usk_service_download_filename($s, $client, $native_info['username'] ?? '');
    $primaryConfig = usk_service_primary_config($s, $client);
    $secondaryConfig = usk_service_secondary_config($s, $client);
    ?>

    <?php if ($protocol === 'xray' && $primaryConfig !== '') : ?>
        <p class="mt-3"><strong><?= __('xray_vless_link') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($primaryConfig) ?></code>
        <p class="text-muted small"><?= __('xray_vless_hint') ?></p>
    <?php elseif ($protocol === 'amnezia' && $primaryConfig !== '') : ?>
        <p class="mt-3"><strong><?= __('config_label') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($primaryConfig) ?></code>
    <?php elseif ($primaryConfig !== '') : ?>
        <p class="mt-3"><strong><?= __('config_label') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($primaryConfig) ?></code>
    <?php endif; ?>

    <?php if ($secondaryConfig !== '') : ?>
        <p class="mt-2"><strong><?= __('amnezia_wg_conf') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;direction:ltr;text-align:left;"><?= usk_esc($secondaryConfig) ?></code>
    <?php endif; ?>

    <?php if ($downloadUrl !== '') : ?>
        <p class="mt-3">
            <a class="btn btn-usk-primary" href="<?= usk_esc($downloadUrl) ?>" download="<?= usk_esc($downloadFilename) ?>">
                <i class="fa-solid fa-download"></i> <?= usk_esc(usk_service_download_label($protocol)) ?>
            </a>
            <?php if ($protocol === 'openvpn' && !empty($client['proto'])) : ?>
                <span class="text-muted small ms-2"><?= strtoupper(usk_esc($client['proto'])) ?></span>
            <?php endif; ?>
        </p>
    <?php elseif ($primaryConfig === '' && $secondaryConfig === '') : ?>
        <p class="mt-3 text-muted small"><?= __('service_config_missing') ?></p>
    <?php endif; ?>

    <div class="mt-4 d-flex gap-2 flex-wrap">
        <a class="btn btn-outline" href="<?= usk_admin_url('services') ?>"><?= __('back') ?></a>
        <form method="post" onsubmit="return confirm('<?= usk_esc(__('service_delete_record_confirm')) ?>')">
            <input type="hidden" name="action" value="delete_record">
            <input type="hidden" name="id" value="<?= (int) $s['row'] ?>">
            <button type="submit" class="btn btn-outline-secondary"><?= __('service_delete_record') ?></button>
        </form>
    </div>
</div>
<?php else : ?>
<div class="usk-card">
    <div class="alert alert-usk-info mb-3"><?= __('services_intro') ?></div>
    <?php if ($count_ended > 0) : ?>
        <div class="alert alert-warning mb-3">
            <?= sprintf(__('services_ended_count'), $count_ended) ?>
            <a href="<?= usk_admin_url('services', ['filter' => 'ended']) ?>" class="ms-2"><?= __('services_show_ended') ?></a>
        </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <a class="btn btn-usk-primary" href="<?= usk_admin_url('create-service') ?>">➕ <?= __('nav_create') ?></a>
        <div class="btn-group ms-auto">
            <?php
            $filters = array(
                'all' => __('filter_all'),
                'active' => __('status_active'),
                'ended' => __('filter_ended'),
                'expired' => __('status_expired'),
                'volume_exceeded' => __('status_volume_exceeded'),
            );
            foreach ($filters as $key => $label) :
            ?>
                <a class="btn btn-sm <?= $filter === $key ? 'btn-usk-primary' : 'btn-outline-secondary' ?>" href="<?= usk_admin_url('services', ['filter' => $key]) ?>"><?= usk_esc($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th><?= __('code') ?></th>
                    <th><?= __('server') ?></th>
                    <th><?= __('volume') ?>/<?= __('duration') ?></th>
                    <th><?= __('status') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($list_count === 0) : ?>
                <tr><td colspan="5" class="text-muted text-center py-4"><?= __('services_empty') ?></td></tr>
            <?php endif; ?>
            <?php while ($r = $list->fetch_assoc()) :
                $badge = usk_service_status_badge($r['status']);
            ?>
                <tr class="<?= in_array($r['status'], array('expired', 'volume_exceeded'), true) ? 'table-warning' : '' ?>">
                    <td><?= usk_esc($r['code']) ?></td>
                    <td><?= usk_esc($r['location']) ?></td>
                    <td><?= usk_esc($r['volume']) ?>GB / <?= usk_esc($r['date']) ?><?= __('days') ?></td>
                    <td><span class="badge badge-<?= $badge['class'] ?>"><?= usk_esc($badge['label']) ?></span></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="<?= usk_admin_url('services', ['view' => $r['row']]) ?>"><?= __('view') ?></a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
