<?php
global $sql;
require_once dirname(__DIR__) . '/lib/protocols/limits.php';
require_once dirname(__DIR__) . '/lib/service-config-view.php';

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
$search_base = usk_admin_base() . '/services-search.php';
$stats_base = usk_admin_base() . '/service-stats.php';
$stats_stream = usk_admin_base() . '/live-stream.php';
$sync_action = usk_admin_base() . '/services-action.php';
$lastSync = USK_ProtocolLimits::get_last_run();
?>
<?php if (!empty($s)) :
    $badge = usk_service_status_badge($s['status']);
    $client = $native_info['client'] ?? null;
    $usageStats = $client ? usk_service_usage_stats($s, $client) : null;
    $protocol = (string) ($s['protocol'] ?? '');
    $downloadUrl = usk_service_download_url($s, $client);
    $portalUrl = usk_service_portal_url($s, $client);
    $downloadFilename = usk_service_download_filename($s, $client, $native_info['username'] ?? '');
    $primaryConfig = usk_service_primary_config($s, $client);
    $secondaryConfig = usk_service_secondary_config($s, $client);
?>
<div class="usk-card">
    <h3><?= __('service_detail') ?> #<?= usk_esc($s['code']) ?></h3>
    <p><strong><?= __('server') ?>:</strong> <?= usk_esc($s['location']) ?></p>
    <p><strong><?= __('volume') ?>:</strong> <?= usk_esc(usk_format_plan_gb($s['volume'])) ?> — <strong><?= __('duration') ?>:</strong> <?= usk_esc(usk_format_plan_days($s['date'])) ?></p>
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
        <?php if ($usageStats && (int) ($s['volume'] ?? 0) > 0) : ?>
            <p><strong><?= __('traffic_used') ?>:</strong>
                <span id="usk-detail-usage-text">
                <?= usk_esc((string) ($usageStats['used_gb'] ?? 0)) ?> GB
                / <?= usk_esc($s['volume']) ?> GB
                (<?= usk_esc(__('portal_left')) ?>: <?= usk_esc((string) ($usageStats['remaining_gb'] ?? 0)) ?> GB)
                </span>
            </p>
            <p class="text-muted small mb-0" id="usk-detail-synced-at">
            <?php if (!empty($client['usage_synced_at'])) : ?>
                <?= sprintf(__('services_usage_synced_at'), usk_esc(USK_ProtocolLimits::format_last_run_at($client['usage_synced_at']))) ?>
            <?php else : ?>
                <?= usk_esc(__('stats_live_hint')) ?>
            <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($usageStats && !empty($usageStats['connections_tracked'])) : ?>
            <p><strong><?= __('services_connections_col') ?>:</strong>
                <span id="usk-detail-connections-text" class="<?= !empty($usageStats['connections_near_limit']) ? 'text-danger' : (!empty($usageStats['connections_warning']) ? 'text-warning' : '') ?>">
                <?= usk_esc((string) ($usageStats['connections_display'] ?? '')) ?> <?= __('plan_connections_unit') ?>
                </span>
            </p>
        <?php elseif (!empty($client['max_connections'])) : ?>
            <p><strong><?= __('portal_max_connections') ?>:</strong> <?= (int) $client['max_connections'] ?> <?= __('plan_connections_unit') ?></p>
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

    <?php if ($portalUrl !== '') : ?>
        <p class="mt-3">
            <strong><?= __('portal_customer_link') ?>:</strong>
            <a class="btn btn-usk-primary btn-sm ms-2" href="<?= usk_esc($portalUrl) ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-external-link"></i> <?= __('portal_open_page') ?>
            </a>
        </p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($portalUrl) ?></code>
    <?php endif; ?>

    <?php if ($protocol === 'xray' && $primaryConfig !== '') : ?>
        <p class="mt-3"><strong><?= __('xray_vless_link') ?>:</strong></p>
        <code class="d-block p-3" style="white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left;"><?= usk_esc($primaryConfig) ?></code>
        <p class="text-muted small"><?= __('xray_vless_hint') ?></p>
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

    <div class="mt-4 d-flex gap-2 flex-wrap align-items-center">
        <form method="post" action="<?= usk_esc($sync_action) ?>" class="d-inline usk-sync-form" onsubmit="return uskSyncUsageSubmit(this)">
            <input type="hidden" name="action" value="sync_usage">
            <input type="hidden" name="view" value="<?= (int) $s['row'] ?>">
            <button type="submit" class="btn btn-outline-usk usk-sync-btn">
                <i class="fa-solid fa-arrows-rotate"></i> <?= __('services_sync_btn') ?>
            </button>
        </form>
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
    <div class="usk-card mb-3 border border-secondary">
        <div class="p-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div>
                <strong><i class="fa-solid fa-gauge-high"></i> <?= __('services_sync_title') ?></strong>
                <p class="text-muted small mb-0 mt-1"><?= __('services_sync_hint') ?></p>
                <?php if (is_array($lastSync) && !empty($lastSync['ran_at'])) : ?>
                    <p class="text-muted small mb-0 mt-1"><?= sprintf(
                        __('services_sync_last'),
                        usk_esc(USK_ProtocolLimits::format_last_run_at($lastSync['ran_at'])),
                        (int) ($lastSync['usage_updated'] ?? 0),
                        (int) ($lastSync['disabled'] ?? 0),
                        (int) ($lastSync['checked'] ?? 0)
                    ) ?></p>
                <?php else : ?>
                    <p class="text-muted small mb-0 mt-1"><?= __('services_sync_never') ?></p>
                <?php endif; ?>
            </div>
            <form method="post" action="<?= usk_esc($sync_action) ?>" class="mb-0 usk-sync-form" onsubmit="return uskSyncUsageSubmit(this)">
                <input type="hidden" name="action" value="sync_usage">
                <input type="hidden" name="filter" value="<?= usk_esc($filter) ?>">
                <button type="submit" class="btn btn-usk-primary usk-sync-btn">
                    <i class="fa-solid fa-arrows-rotate"></i> <?= __('services_sync_btn') ?>
                </button>
            </form>
        </div>
    </div>
    <?php
    $syncMeta = is_array($lastSync) ? ($lastSync['usage_meta'] ?? array()) : array();
    $syncWarn = array();
    if (is_array($syncMeta)) {
        if (empty($syncMeta['sudo_ok']) && ($syncMeta['source'] ?? '') === 'collect_script') {
            $syncWarn[] = __('services_sync_diag_collect_failed');
        } elseif (empty($syncMeta['sudo_ok'])) {
            $syncWarn[] = __('services_sync_diag_no_sudo');
        }
        if (!empty($syncMeta['xray_cfg_clients']) && empty($syncMeta['xray_api_ok'])) {
            $syncWarn[] = __('services_sync_diag_xray_stats');
        }
        if (!empty($syncMeta['node_errors'])) {
            $syncWarn[] = __('services_sync_diag_node_hint');
        }
    }
    if ($syncWarn !== array()) : ?>
        <div class="alert alert-warning mb-3 small">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> <?= __('services_sync_diag_title') ?></strong>
            <ul class="mb-0 mt-1 ps-3"><?php foreach ($syncWarn as $w) : ?><li><?= usk_esc($w) ?></li><?php endforeach; ?></ul>
            <p class="mb-0 mt-2 text-muted"><?= __('services_sync_diag_cmd') ?>: <code dir="ltr">sudo bash <?= usk_esc(USK_ROOT) ?>/bin/collect-usage-stats.sh</code></p>
        </div>
    <?php endif; ?>
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

    <div class="usk-service-search mb-4" id="uskServiceSearch" data-endpoint="<?= usk_esc($search_base) ?>">
        <label class="form-label mb-1" for="uskServiceSearchInput"><i class="fa-solid fa-magnifying-glass"></i> <?= __('services_search_label') ?></label>
        <div class="input-group">
            <input type="search" class="form-control" id="uskServiceSearchInput" placeholder="<?= usk_esc(__('services_search_ph')) ?>" autocomplete="off" dir="ltr" style="text-align:left;">
            <span class="input-group-text d-none" id="uskServiceSearchSpinner"><i class="fa-solid fa-spinner fa-spin"></i></span>
        </div>
        <small class="text-muted d-block mt-1"><?= __('services_search_hint') ?></small>
        <div class="usk-service-search-results d-none" id="uskServiceSearchResults" aria-live="polite"></div>
    </div>

    <div class="table-responsive" id="usk-services-live"
         data-stats-endpoint="<?= usk_esc($stats_base) ?>"
         data-stats-stream="<?= usk_esc($stats_stream) ?>">
        <p class="text-muted small mb-2" id="usk-live-status">
            <i class="fa-solid fa-signal"></i> <?= __('stats_live_hint') ?>
            <span class="usk-live-badge ms-1 d-none" id="usk-live-badge"></span>
        </p>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th><?= __('code') ?></th>
                    <th><?= __('server') ?></th>
                    <th><?= __('volume') ?>/<?= __('duration') ?></th>
                    <th><?= __('traffic_used') ?></th>
                    <th><?= __('services_connections_col') ?></th>
                    <th><?= __('status') ?></th>
                    <th><?= __('portal_customer_link') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($list_count === 0) : ?>
                <tr><td colspan="8" class="text-muted text-center py-4"><?= __('services_empty') ?></td></tr>
            <?php endif; ?>
            <?php while ($r = $list->fetch_assoc()) :
                $row = usk_service_list_row($r);
            ?>
                <tr class="<?= usk_esc($row['row_class']) ?>" data-usk-code="<?= usk_esc($row['code']) ?>">
                    <td><code class="usk-code"><?= usk_esc($row['code']) ?></code></td>
                    <td><?= usk_esc($row['location']) ?></td>
                    <td><?= usk_esc(usk_format_plan_limits($row['volume'], $row['date'])) ?></td>
                    <td class="usk-live-usage" data-usk-code="<?= usk_esc($row['code']) ?>">
                        <?php if (!empty($row['usage_needs_sync'])) : ?>
                            <span class="small text-warning"><?= usk_esc($row['usage_display']) ?></span>
                        <?php elseif ($row['usage_percent'] !== null) : ?>
                            <div class="usk-usage-cell">
                                <span class="small"><?= usk_esc($row['usage_display']) ?></span>
                                <div class="progress usk-usage-progress mt-1" role="progressbar" aria-valuenow="<?= (float) $row['usage_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar<?= (float) $row['usage_percent'] >= 90 ? ' bg-danger' : ((float) $row['usage_percent'] >= 70 ? ' bg-warning' : '') ?>" style="width:<?= min(100, (float) $row['usage_percent']) ?>%"></div>
                                </div>
                            </div>
                        <?php else : ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="usk-live-connections" data-usk-code="<?= usk_esc($row['code']) ?>">
                        <?php if ($row['connections_display'] !== null) : ?>
                            <span class="small<?= !empty($row['connections_near_limit']) ? ' text-danger fw-semibold' : (!empty($row['connections_warning']) ? ' text-warning' : '') ?>"><?= usk_esc($row['connections_display']) ?></span>
                        <?php else : ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= usk_esc($row['badge_class']) ?>"><?= usk_esc($row['badge_label']) ?></span></td>
                    <td>
                        <?php if ($row['portal_url'] !== '') : ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= usk_esc($row['portal_url']) ?>" target="_blank" rel="noopener" title="<?= usk_esc(__('portal_open_page')) ?>">
                                <i class="fa-solid fa-user"></i>
                            </a>
                        <?php else : ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn btn-sm btn-outline-usk" href="<?= usk_esc($row['view_url']) ?>"><?= __('view') ?></a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
.usk-service-search-results {
    margin-top: 0.75rem;
    border: 1px solid var(--usk-border-strong, #334);
    border-radius: var(--usk-radius, 8px);
    background: var(--usk-surface-2, #1a1f2e);
    max-height: 420px;
    overflow-y: auto;
}
.usk-service-search-item {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--usk-border, #2a3142);
}
.usk-service-search-item:last-child { border-bottom: 0; }
.usk-service-search-item:hover { background: rgba(255,255,255,0.03); }
.usk-usage-progress { height: 6px; background: var(--usk-surface-3, #252b3a); }
.usk-usage-cell { min-width: 120px; }
.usk-connections-cell { min-width: 72px; white-space: nowrap; }
</style>
<script>
(function () {
    var root = document.getElementById('uskServiceSearch');
    if (!root) return;
    var input = document.getElementById('uskServiceSearchInput');
    var results = document.getElementById('uskServiceSearchResults');
    var spinner = document.getElementById('uskServiceSearchSpinner');
    var endpoint = root.getAttribute('data-endpoint') || '';
    var timer = null;
    var seq = 0;
    var i18n = {
        empty: <?= json_encode(__('services_search_empty'), JSON_UNESCAPED_UNICODE) ?>,
        min: <?= json_encode(__('services_search_min_chars'), JSON_UNESCAPED_UNICODE) ?>,
        view: <?= json_encode(__('services_search_view'), JSON_UNESCAPED_UNICODE) ?>,
        usage: <?= json_encode(__('traffic_used'), JSON_UNESCAPED_UNICODE) ?>,
        connections: <?= json_encode(__('services_connections_col'), JSON_UNESCAPED_UNICODE) ?>,
        days: <?= json_encode(__('days'), JSON_UNESCAPED_UNICODE) ?>,
        unlimited: <?= json_encode(__('plan_unlimited'), JSON_UNESCAPED_UNICODE) ?>,
    };

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderProgress(percent) {
        if (percent == null) return '';
        var p = Math.min(100, Math.max(0, Number(percent) || 0));
        var barCls = p >= 90 ? ' bg-danger' : (p >= 70 ? ' bg-warning' : '');
        return '<div class="progress usk-usage-progress mt-1" role="progressbar">'
            + '<div class="progress-bar' + barCls + '" style="width:' + p + '%"></div></div>';
    }

    function renderConnectionsLine(item) {
        if (!item.connections_display) return '';
        var cls = item.connections_near_limit ? 'text-danger fw-semibold' : (item.connections_warning ? 'text-warning' : '');
        return '<div class="small mb-2"><strong>' + esc(i18n.connections) + ':</strong> <span' + (cls ? ' class="' + cls + '"' : '') + '>' + esc(item.connections_display) + '</span></div>';
    }

    function renderItem(item) {
        var proto = item.protocol && item.protocol !== 'null' ? ' · ' + esc(item.protocol) : '';
        var volGb = parseInt(item.volume, 10);
        var volDays = parseInt(item.date, 10);
        var vol = (volGb > 0 ? esc(volGb) + 'GB' : esc(i18n.unlimited)) + ' / '
            + (volDays > 0 ? esc(volDays) + i18n.days : esc(i18n.unlimited));
        var portal = item.portal_url
            ? ' <a class="btn btn-sm btn-outline-primary ms-1" href="' + esc(item.portal_url) + '" target="_blank" rel="noopener"><i class="fa-solid fa-user"></i></a>'
            : '';
        return '<div class="usk-service-search-item">'
            + '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">'
            + '<strong dir="ltr"><code class="usk-code">' + esc(item.code) + '</code></strong>'
            + '<span class="badge badge-' + esc(item.badge_class) + '">' + esc(item.badge_label) + '</span>'
            + '</div>'
            + '<div class="small text-muted mb-1">' + esc(item.location) + proto + ' · ' + vol + '</div>'
            + '<div class="small mb-2"><strong>' + esc(i18n.usage) + ':</strong> ' + esc(item.usage_display) + renderProgress(item.usage_percent) + '</div>'
            + renderConnectionsLine(item)
            + '<a class="btn btn-sm btn-usk-primary" href="' + esc(item.view_url) + '"><i class="fa-solid fa-eye"></i> ' + esc(i18n.view) + '</a>'
            + portal
            + '</div>';
    }

    function setLoading(on) {
        if (spinner) spinner.classList.toggle('d-none', !on);
    }

    function runSearch() {
        var q = (input.value || '').trim();
        if (q.length < 2) {
            results.classList.add('d-none');
            results.innerHTML = q.length ? '<div class="usk-service-search-item text-muted small">' + esc(i18n.min) + '</div>' : '';
            if (q.length) results.classList.remove('d-none');
            return;
        }
        var mySeq = ++seq;
        setLoading(true);
        fetch(endpoint + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mySeq !== seq) return;
                setLoading(false);
                if (!data || !data.ok) {
                    results.innerHTML = '<div class="usk-service-search-item text-danger small">' + esc(i18n.empty) + '</div>';
                    results.classList.remove('d-none');
                    return;
                }
                if (!data.results || !data.results.length) {
                    results.innerHTML = '<div class="usk-service-search-item text-muted small">' + esc(i18n.empty) + '</div>';
                } else {
                    results.innerHTML = data.results.map(renderItem).join('');
                }
                results.classList.remove('d-none');
            })
            .catch(function () {
                if (mySeq !== seq) return;
                setLoading(false);
                results.innerHTML = '<div class="usk-service-search-item text-danger small">' + esc(i18n.empty) + '</div>';
                results.classList.remove('d-none');
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(runSearch, 280);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            input.value = '';
            results.classList.add('d-none');
            results.innerHTML = '';
        }
    });
})();
</script>
<?php endif; ?>
<script>
function uskSyncUsageSubmit(form) {
    var btn = form.querySelector('.usk-sync-btn');
    if (btn && btn.disabled) {
        return false;
    }
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + <?= json_encode(__('services_sync_running'), JSON_UNESCAPED_UNICODE) ?>;
    }
    return true;
}

(function () {
    var root = document.getElementById('usk-services-live');
    var detailCode = <?= json_encode(!empty($s) && !empty($usageStats) && (int) ($s['volume'] ?? 0) > 0 ? (string) ($s['code'] ?? '') : '') ?>;
    var endpoint = root ? root.getAttribute('data-stats-endpoint') : <?= json_encode($stats_base, JSON_UNESCAPED_UNICODE) ?>;
    var streamEndpoint = root ? root.getAttribute('data-stats-stream') : <?= json_encode($stats_stream, JSON_UNESCAPED_UNICODE) ?>;
    if (!endpoint) return;

    var i18n = {
        pending: <?= json_encode(__('services_usage_pending'), JSON_UNESCAPED_UNICODE) ?>,
        left: <?= json_encode(__('portal_left'), JSON_UNESCAPED_UNICODE) ?>,
        synced: <?= json_encode(__('services_usage_synced_at'), JSON_UNESCAPED_UNICODE) ?>,
        liveOk: <?= json_encode(__('stats_live_ok'), JSON_UNESCAPED_UNICODE) ?>,
        liveStale: <?= json_encode(__('stats_live_stale'), JSON_UNESCAPED_UNICODE) ?>,
    };

    var pollTimer = null;
    var eventSource = null;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function progressBar(percent) {
        var p = Math.min(100, Math.max(0, Number(percent) || 0));
        var barCls = p >= 90 ? ' bg-danger' : (p >= 70 ? ' bg-warning' : '');
        return '<div class="progress usk-usage-progress mt-1" role="progressbar" aria-valuenow="' + p + '">'
            + '<div class="progress-bar' + barCls + '" style="width:' + p + '%"></div></div>';
    }

    function renderConnectionsCell(item) {
        if (!item.connections_display) {
            return '<span class="text-muted">—</span>';
        }
        var cls = 'small usk-connections-cell';
        if (item.connections_near_limit) cls += ' text-danger fw-semibold';
        else if (item.connections_warning) cls += ' text-warning';
        return '<span class="' + cls + '">' + esc(item.connections_display) + '</span>';
    }

    function renderUsageCell(item) {
        if (item.usage_needs_sync) {
            return '<span class="small text-warning">' + esc(i18n.pending) + '</span>';
        }
        if (item.usage_percent == null) {
            return '<span class="text-muted">—</span>';
        }
        return '<div class="usk-usage-cell"><span class="small">' + esc(item.usage_display) + '</span>' + progressBar(item.usage_percent) + '</div>';
    }

    function formatSyncedAt(iso) {
        if (!iso) return '';
        try {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return iso;
            return d.toLocaleString();
        } catch (e) {
            return iso;
        }
    }

    function updateLiveBadge(live) {
        var badge = document.getElementById('usk-live-badge');
        if (!badge || !live) return;
        badge.classList.remove('d-none', 'text-success', 'text-warning');
        if (live.cache_fresh) {
            badge.className = 'usk-live-badge ms-1 text-success';
            badge.textContent = i18n.liveOk;
        } else {
            badge.className = 'usk-live-badge ms-1 text-warning';
            badge.textContent = i18n.liveStale;
        }
    }

    function applyItem(code, item) {
        var cell = document.querySelector('.usk-live-usage[data-usk-code="' + code + '"]');
        if (cell && item) {
            cell.innerHTML = renderUsageCell(item);
        }
        var connCell = document.querySelector('.usk-live-connections[data-usk-code="' + code + '"]');
        if (connCell && item) {
            connCell.innerHTML = renderConnectionsCell(item);
        }
        if (detailCode && detailCode === code && item) {
            var txt = document.getElementById('usk-detail-usage-text');
            if (txt && !item.usage_needs_sync && item.used_gb != null) {
                txt.textContent = item.used_gb + ' GB / ' + item.limit_gb + ' GB (' + i18n.left + ': ' + (item.remaining_gb != null ? item.remaining_gb : '0') + ' GB)';
            }
            var connTxt = document.getElementById('usk-detail-connections-text');
            if (connTxt && item.connections_display) {
                connTxt.textContent = item.connections_display + ' ' + <?= json_encode(__('plan_connections_unit'), JSON_UNESCAPED_UNICODE) ?>;
                connTxt.className = item.connections_near_limit ? 'text-danger fw-semibold' : (item.connections_warning ? 'text-warning' : '');
            }
            var synced = document.getElementById('usk-detail-synced-at');
            if (synced && item.synced_at) {
                synced.textContent = i18n.synced.replace('%s', formatSyncedAt(item.synced_at));
            }
        }
    }

    function applyPayload(data) {
        if (!data || !data.ok || !data.items) return;
        updateLiveBadge(data.live);
        Object.keys(data.items).forEach(function (code) {
            applyItem(code, data.items[code]);
        });
    }

    function collectCodes() {
        var codes = [];
        if (detailCode) {
            codes.push(detailCode);
        }
        if (root) {
            root.querySelectorAll('tr[data-usk-code]').forEach(function (tr) {
                var c = tr.getAttribute('data-usk-code');
                if (c && codes.indexOf(c) === -1) codes.push(c);
            });
        }
        return codes;
    }

    function refreshStats() {
        var codes = collectCodes();
        if (!codes.length) return;
        fetch(endpoint + '?codes=' + encodeURIComponent(codes.join(',')), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        })
            .then(function (r) { return r.json(); })
            .then(applyPayload)
            .catch(function () {});
    }

    function startPolling(ms) {
        if (pollTimer) clearInterval(pollTimer);
        refreshStats();
        pollTimer = setInterval(refreshStats, ms);
    }

    function startStream() {
        var codes = collectCodes();
        if (!streamEndpoint || !codes.length || typeof EventSource === 'undefined') {
            startPolling(3000);
            return;
        }
        var url = streamEndpoint + '?codes=' + encodeURIComponent(codes.join(','));
        try {
            eventSource = new EventSource(url);
            eventSource.addEventListener('stats', function (e) {
                try { applyPayload(JSON.parse(e.data)); } catch (err) {}
            });
            eventSource.onerror = function () {
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }
                startPolling(3000);
            };
            refreshStats();
        } catch (e) {
            startPolling(3000);
        }
    }

    startStream();
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refreshStats();
    });
})();
</script>
