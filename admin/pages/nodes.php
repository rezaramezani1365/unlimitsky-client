<?php

$GLOBALS['page_title'] = __('nav_nodes');
$GLOBALS['active_nav'] = 'nodes';

require_once __DIR__ . '/../lib/nodes.php';
require_once __DIR__ . '/../lib/node-ssh.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'rotate_secret') {
        $check = USK_Nodes::assert_can_use_nodes();
        if (empty($check['ok'])) {
            usk_flash(__('nodes_pro_required'), 'error');
        } else {
            USK_Nodes::rotate_register_secret();
            usk_flash(__('nodes_secret_rotated'));
        }
        header('Location: ' . usk_admin_url('nodes'));
        exit;
    }

    if ($action === 'delete') {
        $id = preg_replace('/[^a-z0-9]/', '', $_POST['node_id'] ?? '');
        USK_Nodes::delete($id);
        usk_flash(__('nodes_deleted'));
        header('Location: ' . usk_admin_url('nodes'));
        exit;
    }

    if ($action === 'test') {
        $id = preg_replace('/[^a-z0-9]/', '', $_POST['node_id'] ?? '');
        $node = USK_Nodes::get($id);
        if (!$node) {
            usk_flash(__('nodes_not_found'), 'error');
        } else {
            $cred = USK_Nodes::ssh_credentials($node);
            $test = USK_NodeSsh::test_connection($cred['host'], $cred['port'], $cred['user'], $cred['password']);
            if (!empty($test['ok'])) {
                USK_Nodes::mark_seen($id, 'online');
                usk_flash(__('nodes_test_ok'));
            } else {
                USK_Nodes::mark_seen($id, 'offline', $test['error'] ?? 'ssh_failed');
                usk_flash(__('nodes_test_failed') . ': ' . ($test['detail'] ?? ($test['error'] ?? '')), 'error');
            }
        }
        header('Location: ' . usk_admin_url('nodes'));
        exit;
    }
}

$canNodes = USK_Nodes::can_use_nodes();
$registerSecret = $canNodes ? USK_Nodes::register_secret() : '';
$nodes = USK_Nodes::all();
$sshpassOk = USK_NodeSsh::sshpass_available();

$hubCfg = USK_PanelAccess::get();
$hubHost = !empty($hubCfg['domain_enabled']) && ($hubCfg['panel_domain'] ?? '') !== ''
    ? $hubCfg['panel_domain']
    : (USK_ConnectHost::detect_ip());
$hubPort = (int) ($hubCfg['panel_port'] ?? 8082);
$hubScheme = !empty($hubCfg['https_enabled']) ? 'https' : 'http';
$hubBase = sprintf('%s://%s:%d', $hubScheme, $hubHost, $hubPort);
$installCmd = sprintf(
    "curl -fsSL %s/bin/install-node.sh | sudo bash -s -- \\\n" .
    "  --hub-ip %s --hub-port %d \\\n" .
    "  --register-secret '%s' \\\n" .
    "  --ssh-user root --ssh-pass 'YOUR_SSH_PASSWORD' \\\n" .
    "  --name YOUR_NODE_NAME --connect-host YOUR_PUBLIC_IP_OR_DOMAIN",
    $hubBase,
    $hubHost,
    $hubPort,
    $registerSecret
);
$installCmdInteractive = sprintf(
    "curl -fsSL %s/bin/install-node.sh -o install-node.sh\n" .
    'sudo bash install-node.sh',
    $hubBase
);

?>
<div class="usk-card mb-4">
    <div class="usk-card-header"><i class="fa-solid fa-sitemap"></i> <?= __('nav_nodes') ?></div>
    <div class="p-3">
        <p class="text-muted small"><?= __('nodes_intro') ?></p>

        <?php if (!$canNodes) : ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-crown"></i> <?= __('nodes_pro_required') ?>
                <a class="btn btn-sm btn-usk-primary ms-2" href="<?= usk_admin_url('license') ?>"><?= __('license_activate') ?></a>
            </div>
        <?php else : ?>
            <?php if (!$sshpassOk) : ?>
            <div class="alert alert-danger small">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= __('nodes_sshpass_missing') ?>
            </div>
            <?php endif; ?>

            <h6 class="mt-3"><?= __('nodes_install_title') ?></h6>
            <p class="small text-muted"><?= __('nodes_install_steps') ?></p>
            <ol class="small">
                <li><?= __('nodes_step_register_secret') ?></li>
                <li><?= __('nodes_step_run_on_remote') ?></li>
                <li><?= __('nodes_step_replace_placeholders') ?></li>
            </ol>

            <div class="mb-3">
                <label class="form-label small"><?= __('nodes_register_secret') ?></label>
                <code class="d-block p-2 user-select-all" dir="ltr" style="word-break:break-all;"><?= usk_esc($registerSecret) ?></code>
                <form method="post" class="mt-2">
                    <input type="hidden" name="action" value="rotate_secret">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('<?= usk_esc(__('nodes_rotate_confirm')) ?>')">
                        <?= __('nodes_rotate_secret') ?>
                    </button>
                </form>
            </div>

            <div class="mb-3">
                <label class="form-label small"><?= __('nodes_install_cmd') ?></label>
                <p class="small text-muted mb-1"><?= __('nodes_install_cmd_note') ?></p>
                <code class="d-block p-3 user-select-all" dir="ltr" style="word-break:break-all; white-space:pre-wrap;"><?= usk_esc($installCmd) ?></code>
            </div>

            <div class="mb-3">
                <label class="form-label small"><?= __('nodes_install_cmd_interactive') ?></label>
                <p class="small text-muted mb-1"><?= __('nodes_install_interactive_note') ?></p>
                <code class="d-block p-3 user-select-all" dir="ltr" style="word-break:break-all; white-space:pre-wrap;"><?= usk_esc($installCmdInteractive) ?></code>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canNodes) : ?>
<div class="usk-card">
    <div class="usk-card-header"><?= __('nodes_list') ?> (<?= count($nodes) ?>)</div>
    <div class="table-responsive">
        <table class="table table-dark table-hover mb-0">
            <thead>
                <tr>
                    <th><?= __('nodes_col_name') ?></th>
                    <th><?= __('nodes_col_connect') ?></th>
                    <th>SSH</th>
                    <th><?= __('status') ?></th>
                    <th><?= __('nodes_col_last_seen') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($nodes === array()) : ?>
                <tr><td colspan="6" class="text-muted text-center py-4"><?= __('nodes_empty') ?></td></tr>
            <?php else : ?>
                <?php foreach ($nodes as $node) : ?>
                <tr>
                    <td><strong><?= usk_esc($node['name'] ?? '') ?></strong><br><code class="small"><?= usk_esc($node['id'] ?? '') ?></code></td>
                    <td dir="ltr"><code><?= usk_esc(USK_Nodes::connect_host_for_node($node)) ?></code></td>
                    <td dir="ltr" class="small"><?= usk_esc($node['ssh_user'] ?? '') ?>@<?= usk_esc($node['ssh_host'] ?? '') ?>:<?= (int) ($node['ssh_port'] ?? 22) ?></td>
                    <td>
                        <?php
                        $st = $node['status'] ?? 'unknown';
                        $badge = $st === 'online' ? 'success' : ($st === 'offline' ? 'warning' : 'secondary');
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= usk_esc($st) ?></span>
                    </td>
                    <td class="small"><?= usk_esc(USK_Nodes::format_last_run_at($node['last_seen'] ?? '')) ?></td>
                    <td class="text-nowrap">
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="test">
                            <input type="hidden" name="node_id" value="<?= usk_esc($node['id'] ?? '') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><?= __('nodes_test') ?></button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('<?= usk_esc(__('nodes_delete_confirm')) ?>')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="node_id" value="<?= usk_esc($node['id'] ?? '') ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?= __('delete') ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
