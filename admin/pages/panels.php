<?php
global $sql;
require_once __DIR__ . '/../lib/license.php';

$GLOBALS['page_title'] = __('nav_panels');
$GLOBALS['active_nav'] = 'panels';
$canUsePanels = USK_License::can_use_external_panels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lic = USK_License::assert_can_use_external_panels(true);
    if (empty($lic['ok'])) {
        usk_flash(__('panels_pro_required'), 'error');
        header('Location: ' . usk_admin_url('panels'));
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $code = usk_panel_code();
        $id = (int) ($_POST['id'] ?? 0);
        $name = $sql->real_escape_string(trim($_POST['name'] ?? ''));
        $type = $sql->real_escape_string($_POST['type'] ?? 'marzban');
        $link = $sql->real_escape_string(trim($_POST['login_link'] ?? ''));
        $user = $sql->real_escape_string(trim($_POST['username'] ?? ''));
        $pass = $sql->real_escape_string(trim($_POST['password'] ?? ''));
        $status = $sql->real_escape_string($_POST['status'] ?? 'active');
        $protocols = $sql->real_escape_string(trim($_POST['protocols'] ?? 'vless|'));
        $flow = $sql->real_escape_string($_POST['flow'] ?? 'flowon');

        if ($id > 0) {
            $sql->query("UPDATE `panels` SET `name`='$name',`login_link`='$link',`username`='$user',`password`='$pass',`type`='$type',`status`='$status',`protocols`='$protocols',`flow`='$flow' WHERE `row`=$id");
            $panel_code = $sql->query("SELECT `code` FROM `panels` WHERE `row`=$id")->fetch_assoc()['code'];
        } else {
            $sql->query("INSERT INTO `panels` (`name`,`login_link`,`username`,`password`,`code`,`type`,`status`,`protocols`,`flow`) VALUES ('$name','$link','$user','$pass','$code','$type','$status','$protocols','$flow')");
            $panel_code = $code;
            if ($type === 'sanayi') {
                $sql->query("INSERT INTO `sanayi_panel_setting` (`code`,`inbound_id`,`example_link`,`flow`) VALUES ('$code','none','none','offflow')");
            }
        }

        if ($type === 'sanayi') {
            $inbound = $sql->real_escape_string(trim($_POST['inbound_id'] ?? ''));
            $example = $sql->real_escape_string(trim($_POST['example_link'] ?? ''));
            $sql->query("UPDATE `sanayi_panel_setting` SET `inbound_id`='$inbound',`example_link`='$example' WHERE `code`='$panel_code'");
        }
        if ($type === 'marzban' && !empty($_POST['inbounds'])) {
            $sql->query("DELETE FROM `marzban_inbounds` WHERE `panel`='$panel_code'");
            foreach (array_filter(array_map('trim', explode("\n", $_POST['inbounds']))) as $ib) {
                $ib = $sql->real_escape_string($ib);
                $sql->query("INSERT INTO `marzban_inbounds` (`panel`,`inbound`,`status`) VALUES ('$panel_code','$ib','active')");
            }
        }

        $panel = $sql->query("SELECT * FROM `panels` WHERE `code`='$panel_code'")->fetch_assoc();
        $cookieFile = USK_ROOT . '/cookie.txt';
        if ($type === 'marzban') {
            $login = loginPanel($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($login['access_token'])) {
                $t = $sql->real_escape_string($login['access_token']);
                $sql->query("UPDATE `panels` SET `token`='$t',`status`='active' WHERE `code`='$panel_code'");
                usk_flash(__('panels_save_ok_marzban'));
            } else {
                usk_flash(__('panels_save_fail_marzban'), 'error');
            }
        } elseif ($type === 'sanayi') {
            $login = loginPanelSanayi($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($login['success']) && is_file($cookieFile)) {
                $parts = explode('session	', file_get_contents($cookieFile));
                $session = isset($parts[1]) ? str_replace(array(" ", "\n", "\t"), array('', '', ''), $parts[1]) : '';
                if ($session) {
                    $session = $sql->real_escape_string($session);
                    $sql->query("UPDATE `panels` SET `token`='$session',`status`='active' WHERE `code`='$panel_code'");
                }
                usk_flash(__('panels_save_ok_sanaei'));
            } else {
                usk_flash(__('panels_save_fail_sanaei'), 'error');
            }
        }
        header('Location: ' . usk_admin_url('panels'));
        exit;
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $p = $sql->query("SELECT `code` FROM `panels` WHERE `row`=$id")->fetch_assoc();
        if ($p) {
            $c = $p['code'];
            $sql->query("DELETE FROM `panels` WHERE `row`=$id");
            $sql->query("DELETE FROM `sanayi_panel_setting` WHERE `code`='$c'");
            $sql->query("DELETE FROM `marzban_inbounds` WHERE `panel`='$c'");
            usk_flash(__('panels_deleted'));
        }
        header('Location: ' . usk_admin_url('panels'));
        exit;
    }
}

$edit_id = (int) ($_GET['edit'] ?? 0);
$edit = $edit_id ? $sql->query("SELECT * FROM `panels` WHERE `row`=$edit_id")->fetch_assoc() : null;
$sanayi = null;
$inbounds = '';
if ($edit) {
    $sanayi = $sql->query("SELECT * FROM `sanayi_panel_setting` WHERE `code`='{$edit['code']}'")->fetch_assoc();
    $ib = $sql->query("SELECT `inbound` FROM `marzban_inbounds` WHERE `panel`='{$edit['code']}'");
    while ($r = $ib->fetch_assoc()) {
        $inbounds .= $r['inbound'] . "\n";
    }
}
$list = $sql->query("SELECT * FROM `panels` ORDER BY `row` DESC");
?>
<?php if (!$canUsePanels) : ?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-crown"></i> <?= __('panels_pro_banner') ?>
    <a href="<?= usk_admin_url('license') ?>" class="btn btn-usk-primary btn-sm ms-2"><?= __('panels_pro_activate') ?></a>
</div>
<?php endif; ?>

<div class="alert alert-usk-info d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <span><i class="fa-solid fa-book"></i> <?= __('guides_read_first') ?></span>
    <a href="<?= usk_admin_url('guides') ?>" class="btn btn-usk-primary btn-sm"><?= __('guides_view') ?></a>
</div>

<?php if ($canUsePanels) : ?>
<div class="usk-card mb-4">
    <h3 class="mb-3"><?= $edit ? __('panels_edit') : __('panels_add') ?></h3>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($edit['row'] ?? 0) ?>">
        <div class="form-row">
            <div class="form-group"><label><?= __('panels_name') ?></label><input class="form-control" name="name" required value="<?= usk_esc($edit['name'] ?? '') ?>"></div>
            <div class="form-group"><label><?= __('panels_type') ?></label>
                <select class="form-control" name="type">
                    <option value="marzban" <?= ($edit['type'] ?? '') === 'marzban' ? 'selected' : '' ?>>Marzban</option>
                    <option value="sanayi" <?= ($edit['type'] ?? '') === 'sanayi' ? 'selected' : '' ?>>Sanaei (3x-ui)</option>
                </select>
            </div>
        </div>
        <div class="form-group"><label><?= __('panels_url') ?></label><input class="form-control" name="login_link" required placeholder="https://ip:8000" dir="ltr" style="text-align:left;" value="<?= usk_esc($edit['login_link'] ?? '') ?>"></div>
        <div class="form-row">
            <div class="form-group"><label><?= __('username') ?></label><input class="form-control" name="username" value="<?= usk_esc($edit['username'] ?? '') ?>"></div>
            <div class="form-group"><label><?= __('password') ?></label><input class="form-control" type="password" name="password" value="<?= usk_esc($edit['password'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label><?= __('panels_marzban_protocols') ?></label><input class="form-control" name="protocols" dir="ltr" value="<?= usk_esc($edit['protocols'] ?? 'vless|') ?>"></div>
            <div class="form-group"><label><?= __('status') ?></label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>><?= __('active') ?></option>
                    <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>><?= __('inactive') ?></option>
                </select>
            </div>
        </div>
        <div class="form-group"><label><?= __('panels_marzban_inbounds') ?></label><textarea class="form-control" name="inbounds" rows="3" dir="ltr"><?= usk_esc(trim($inbounds)) ?></textarea></div>
        <div class="form-row">
            <div class="form-group"><label><?= __('panels_sanaei_inbound_id') ?></label><input class="form-control" name="inbound_id" dir="ltr" value="<?= usk_esc($sanayi['inbound_id'] ?? '') ?>"></div>
            <div class="form-group"><label>Flow</label><input class="form-control" name="flow" dir="ltr" value="<?= usk_esc($edit['flow'] ?? 'flowon') ?>"></div>
        </div>
        <div class="form-group"><label><?= __('panels_sanaei_link_template') ?></label><textarea class="form-control" name="example_link" rows="2" dir="ltr"><?= usk_esc($sanayi['example_link'] ?? '') ?></textarea></div>
        <button type="submit" class="btn btn-usk-primary"><?= __('panels_save_test') ?></button>
        <?php if ($edit) : ?><a class="btn btn-outline ms-2" href="<?= usk_admin_url('panels') ?>"><?= __('cancel') ?></a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<div class="usk-card">
    <h3 class="mb-3"><?= __('panels_list') ?></h3>
    <?php if ($list->num_rows === 0) : ?>
        <p class="text-muted mb-0"><?= __('panels_empty') ?></p>
    <?php else : ?>
    <div class="table-responsive">
        <table class="table table-dark table-hover mb-0">
            <thead><tr><th><?= __('panels_name') ?></th><th><?= __('panels_type') ?></th><th><?= __('panels_url') ?></th><th><?= __('panels_created_count') ?></th><th><?= __('status') ?></th><th><?= __('actions') ?></th></tr></thead>
            <tbody>
            <?php while ($p = $list->fetch_assoc()) : ?>
                <tr>
                    <td><?= usk_esc($p['name']) ?></td>
                    <td><?= usk_esc($p['type']) ?></td>
                    <td><code class="usk-code"><?= usk_esc($p['login_link']) ?></code></td>
                    <td><?= usk_esc($p['count_create']) ?></td>
                    <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($p['status']) ?></span></td>
                    <td class="actions">
                        <?php if ($canUsePanels) : ?>
                        <a class="btn btn-sm btn-outline" href="<?= usk_admin_url('panels', ['edit' => $p['row']]) ?>"><?= __('edit') ?></a>
                        <form method="post" style="display:inline" onsubmit="return confirm('<?= usk_esc(__('delete')) ?>?')">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['row'] ?>">
                            <button class="btn btn-sm btn-danger"><?= __('delete') ?></button>
                        </form>
                        <?php else : ?>
                        <span class="text-muted small">Pro</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
