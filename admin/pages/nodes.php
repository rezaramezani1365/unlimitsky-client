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
        <p class="text-muted small mb-3"><?= __('nodes_intro') ?></p>

        <div class="alert alert-usk-info small py-2 px-3 mb-3">
            <i class="fa-solid fa-circle-info"></i> <?= __('nodes_roles_info') ?>
        </div>

        <?php if (!$canNodes) : ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-crown"></i> <?= __('nodes_pro_required') ?>
                <a class="btn btn-sm btn-usk-primary ms-2" href="<?= usk_admin_url('license') ?>"><?= __('license_activate') ?></a>
            </div>
        <?php else : ?>
            <div class="border border-secondary rounded p-3 mb-3">
                <h6 class="mb-2"><i class="fa-solid fa-server text-usk"></i> <?= __('nodes_part_a_title') ?></h6>
                <p class="small text-muted mb-2"><?= __('nodes_part_a_intro') ?></p>
                <ol class="small mb-3 ps-3">
                    <li class="mb-1"><?= __('nodes_hub_step_license') ?></li>
                    <li class="mb-1">
                        <?= __('nodes_hub_step_sshpass') ?>
                        <code class="d-inline-block mt-1 user-select-all" dir="ltr">sudo apt install -y sshpass</code>
                    </li>
                    <li class="mb-1">
                        <?= __('nodes_hub_step_update') ?>
                        — <a href="<?= usk_admin_url('updates') ?>"><?= __('nav_updates') ?></a>
                    </li>
                    <li class="mb-1"><?= __('nodes_hub_step_secret') ?></li>
                    <li class="mb-1"><?= __('nodes_hub_step_note_address') ?></li>
                </ol>

                <?php if (!$sshpassOk) : ?>
                <div class="alert alert-danger small mb-3">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= __('nodes_sshpass_missing') ?>
                </div>
                <?php endif; ?>

                <p class="small mb-1"><strong><?= __('nodes_hub_address') ?></strong></p>
                <code class="d-block p-2 user-select-all mb-3" dir="ltr"><?= usk_esc($hubHost) ?>:<?= $hubPort ?></code>

                <label class="form-label small"><?= __('nodes_register_secret') ?></label>
                <code class="d-block p-2 user-select-all" dir="ltr" style="word-break:break-all;"><?= usk_esc($registerSecret) ?></code>
                <form method="post" class="mt-2">
                    <input type="hidden" name="action" value="rotate_secret">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('<?= usk_esc(__('nodes_rotate_confirm')) ?>')">
                        <?= __('nodes_rotate_secret') ?>
                    </button>
                </form>
            </div>

            <div class="border border-secondary rounded p-3 mb-3">
                <h6 class="mb-2"><i class="fa-solid fa-cloud text-usk"></i> <?= __('nodes_part_b_title') ?></h6>
                <p class="small text-muted mb-2"><?= __('nodes_part_b_intro') ?></p>
                <ol class="small mb-3 ps-3">
                    <li class="mb-1"><?= __('nodes_node_step_ubuntu') ?></li>
                    <li class="mb-1"><?= __('nodes_node_step_firewall') ?> <code dir="ltr"><?= usk_esc($hubHost) ?></code></li>
                    <li class="mb-1"><?= __('nodes_node_step_ssh_auth') ?></li>
                    <li class="mb-1"><?= __('nodes_node_step_run_cmd') ?></li>
                </ol>

                <div class="mb-3">
                    <label class="form-label small"><?= __('nodes_install_cmd') ?></label>
                    <p class="small text-muted mb-1"><?= __('nodes_install_cmd_note') ?></p>
                    <code class="d-block p-3 user-select-all" dir="ltr" style="word-break:break-all; white-space:pre-wrap;"><?= usk_esc($installCmd) ?></code>
                </div>

                <p class="small text-muted mb-1"><?= __('nodes_node_step_interactive') ?></p>
                <div class="mb-0">
                    <label class="form-label small"><?= __('nodes_install_cmd_interactive') ?></label>
                    <p class="small text-muted mb-1"><?= __('nodes_install_interactive_note') ?></p>
                    <code class="d-block p-3 user-select-all" dir="ltr" style="word-break:break-all; white-space:pre-wrap;"><?= usk_esc($installCmdInteractive) ?></code>
                </div>
            </div>

            <div class="border border-secondary rounded p-3 mb-3">
                <h6 class="mb-2"><i class="fa-solid fa-check-double text-usk"></i> <?= __('nodes_part_c_title') ?></h6>
                <p class="small text-muted mb-2"><?= __('nodes_part_c_intro') ?></p>
                <ol class="small mb-0 ps-3">
                    <li class="mb-1"><?= __('nodes_hub_step_test_ssh') ?></li>
                    <li class="mb-1">
                        <?= __('nodes_hub_step_create_service') ?>
                        — <a href="<?= usk_admin_url('create-service') ?>"><?= __('create_title') ?></a>
                        <span class="text-muted">(<?= __('nodes_xray_only') ?>)</span>
                    </li>
                    <li class="mb-1"><?= __('nodes_hub_step_open_port') ?></li>
                </ol>
            </div>

            <div class="alert alert-warning small mb-0">
                <strong><i class="fa-solid fa-triangle-exclamation"></i> <?= __('nodes_common_errors_title') ?></strong>
                <ul class="mb-0 mt-2 ps-3">
                    <li class="mb-1"><?= __('nodes_error_sshpass') ?></li>
                    <li class="mb-1"><?= __('nodes_error_ssh_firewall') ?></li>
                    <li><?= __('nodes_error_pipe_flags') ?></li>
                </ul>
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
