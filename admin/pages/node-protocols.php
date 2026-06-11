<?php

require_once __DIR__ . '/../lib/node-protocols.php';

$GLOBALS['active_nav'] = 'nodes';
$nodeId = preg_replace('/[^a-z0-9]/', '', $_GET['node_id'] ?? '');
$node = $nodeId !== '' ? USK_Nodes::get($nodeId) : null;

if (!$node) {
    usk_flash(__('nodes_not_found'), 'error');
    header('Location: ' . usk_admin_url('nodes'));
    exit;
}

$check = USK_Nodes::assert_can_use_nodes();
if (empty($check['ok'])) {
    usk_flash(__('nodes_pro_required'), 'error');
    header('Location: ' . usk_admin_url('nodes'));
    exit;
}

$GLOBALS['page_title'] = __('node_protocols_title') . ' — ' . ($node['name'] ?? $nodeId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_scripts') {
        $sync = USK_NodeProtocols::sync_scripts($node);
        if (!empty($sync['ok'])) {
            usk_flash(__('node_protocols_sync_ok'));
        } else {
            $failed = !empty($sync['failed']) ? implode(', ', $sync['failed']) : '';
            $hub = trim((string) ($sync['hub'] ?? ''));
            $msg = __('node_protocols_sync_failed');
            if ($hub !== '') {
                $msg .= ' (' . $hub . '/bin/)';
            }
            if ($failed !== '') {
                $msg .= ': ' . $failed;
            }
            $tail = trim(substr((string) ($sync['log'] ?? ''), -350));
            if ($tail !== '') {
                $msg .= ' — ' . $tail;
            }
            usk_flash($msg, 'error');
        }
        header('Location: ' . usk_admin_url('node-protocols', array('node_id' => $nodeId)));
        exit;
    }

    if ($action === 'install_protocol') {
        $proto = USK_ProtocolManager::sanitize_key($_POST['install_protocol'] ?? '');
        if ($proto === '' || !in_array($proto, USK_NodeProtocols::supported(), true)) {
            usk_flash(__('protocol_failed') . ': invalid_protocol', 'error');
        } else {
            $ports = USK_ProtocolManager::parse_ports($proto, $_POST);
            $res = USK_NodeProtocols::install($node, $proto, $ports);
            if (!empty($res['ok'])) {
                usk_flash(__('protocol_installed'));
            } else {
                $tail = isset($res['log']) ? trim(substr((string) $res['log'], -400)) : '';
                $msg = __('protocol_failed');
                if (!empty($res['error'])) {
                    $msg .= ': ' . USK_ProtocolProvisioner::error_label($res['error']);
                }
                if ($tail !== '') {
                    $msg .= ' — ' . $tail;
                }
                usk_flash($msg, 'error');
            }
        }
        header('Location: ' . usk_admin_url('node-protocols', array('node_id' => $nodeId)));
        exit;
    }
}

$protocols = USK_NodeProtocols::list_meta();
$statuses = USK_NodeProtocols::refresh_all($node);
$node = USK_Nodes::get($nodeId) ?: $node;
$connectHost = USK_Nodes::connect_host_for_node($node);
$sshLine = ($node['ssh_user'] ?? '') . '@' . ($node['ssh_host'] ?? '') . ':' . (int) ($node['ssh_port'] ?? 22);
$sshpassOk = USK_NodeSsh::sshpass_available();

?>
<div class="mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= usk_admin_url('nodes') ?>">
        <i class="fa-solid fa-arrow-left"></i> <?= __('back') ?>
    </a>
</div>

<div class="usk-card mb-4">
    <div class="usk-card-header">
        <i class="fa-solid fa-network-wired"></i> <?= __('node_protocols_title') ?>
    </div>
    <div class="p-3">
        <p class="mb-2"><strong><?= __('nodes_col_name') ?>:</strong> <?= usk_esc($node['name'] ?? '') ?>
            <code class="small ms-1"><?= usk_esc($nodeId) ?></code></p>
        <p class="mb-2"><strong><?= __('nodes_col_connect') ?>:</strong> <code dir="ltr"><?= usk_esc($connectHost) ?></code></p>
        <p class="mb-3"><strong>SSH:</strong> <code dir="ltr"><?= usk_esc($sshLine) ?></code>
            <?php
            $st = $node['status'] ?? 'unknown';
            $badge = $st === 'online' ? 'success' : ($st === 'offline' ? 'warning' : 'secondary');
            ?>
            <span class="badge bg-<?= $badge ?> ms-2"><?= usk_esc($st) ?></span>
        </p>

        <?php if (!$sshpassOk) : ?>
        <div class="alert alert-danger small">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= __('nodes_sshpass_missing') ?>
        </div>
        <?php endif; ?>

        <div class="alert alert-usk-info small py-2 px-3 mb-3">
            <i class="fa-solid fa-circle-info"></i> <?= __('node_protocols_intro') ?>
        </div>

        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="sync_scripts">
            <button type="submit" class="btn btn-outline-secondary btn-sm"<?= $sshpassOk ? '' : ' disabled' ?>>
                <i class="fa-solid fa-arrows-rotate"></i> <?= __('node_protocols_sync_scripts') ?>
            </button>
        </form>
    </div>
</div>

<div class="row g-3">
<?php foreach ($protocols as $key => $meta) :
    $st = $statuses[$key] ?? USK_NodeProtocols::read_cached_status($node, $key);
    $installed = !empty($st['installed']);
    $portFields = $meta['port_fields'] ?? array();
?>
    <div class="col-md-6 col-lg-4">
        <div class="usk-card h-100">
            <div class="usk-card-header">
                <i class="fa-solid <?= $meta['icon'] ?>"></i> <?= usk_esc($meta['name']) ?>
            </div>
            <div class="p-3">
                <?php if ($installed) : ?>
                    <?php if ($key === 'xray' && !empty($st['vless_port'])) : ?>
                    <p class="text-muted small mb-2">
                        VLESS Reality: <code class="usk-code">TCP <?= (int) $st['vless_port'] ?></code>
                        <?php if (!empty($st['reality_sni'])) : ?>
                        · SNI: <code class="usk-code"><?= usk_esc($st['reality_sni']) ?></code>
                        <?php endif; ?>
                    </p>
                    <?php elseif ($key === 'openvpn' && (!empty($st['udp_port']) || !empty($st['tcp_port']))) : ?>
                    <p class="text-muted small mb-2">
                        UDP: <code class="usk-code"><?= (int) ($st['udp_port'] ?? 1194) ?></code>
                        · TCP: <code class="usk-code"><?= (int) ($st['tcp_port'] ?? 443) ?></code>
                    </p>
                    <?php elseif (!empty($st['port'])) : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_active_port') ?>: <code class="usk-code"><?= (int) $st['port'] ?></code></p>
                    <?php endif; ?>
                    <?php if (!empty($meta['fixed_ports'])) : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_fixed_ports') ?>: <?= usk_esc($meta['fixed_ports']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($st['firewall_note'])) : ?>
                    <p class="small text-warning mb-2"><i class="fa-solid fa-triangle-exclamation"></i> <?= usk_esc($st['firewall_note']) ?></p>
                    <?php endif; ?>
                <?php elseif (!empty($meta['fixed_ports'])) : ?>
                    <p class="text-muted small mb-2"><?= __('protocol_fixed_ports') ?>: <?= usk_esc($meta['fixed_ports']) ?></p>
                <?php endif; ?>
                <?php if (!empty($meta['note_key'])) : ?>
                    <p class="small text-danger mb-2"><i class="fa-solid fa-circle-info"></i> <?= __($meta['note_key']) ?></p>
                <?php endif; ?>
                <p class="mb-3">
                    <?php if ($installed) : ?>
                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> <?= __('protocol_active') ?></span>
                    <?php else : ?>
                        <span class="badge badge-danger"><?= __('protocol_not_installed') ?></span>
                    <?php endif; ?>
                </p>
                <form method="post">
                    <input type="hidden" name="action" value="install_protocol">
                    <input type="hidden" name="install_protocol" value="<?= usk_esc($key) ?>">
                    <?php foreach ($portFields as $field) :
                        $fkey = $field['key'];
                        $fval = USK_ProtocolManager::effective_port($st[$fkey] ?? null, $field['default']);
                    ?>
                    <div class="form-group mb-2">
                        <label class="small mb-1"><?= usk_esc($field['label']) ?></label>
                        <input type="number" class="form-control form-control-sm" name="port_<?= usk_esc($fkey) ?>"
                               min="1" max="65535" value="<?= $fval ?>" required>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$installed) : ?>
                    <button type="submit" class="btn btn-usk-primary btn-sm w-100 mt-2"<?= $sshpassOk ? '' : ' disabled' ?>
                            onclick="return confirm('<?= usk_esc(__('protocol_install_confirm')) ?>')">
                        <i class="fa-solid fa-download"></i> <?= __('protocol_install') ?>
                    </button>
                    <?php else : ?>
                    <?php if (!empty($st['updated_at'])) : ?>
                    <p class="text-muted small mb-2 mt-2"><?= __('protocol_last_install') ?>: <?= usk_esc(date('Y-m-d H:i', strtotime($st['updated_at']))) ?></p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100 mt-2"<?= $sshpassOk ? '' : ' disabled' ?>
                            onclick="return confirm('<?= usk_esc(__('protocol_reinstall_confirm')) ?>')">
                        <i class="fa-solid fa-rotate"></i> <?= __('protocol_reinstall') ?>
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
