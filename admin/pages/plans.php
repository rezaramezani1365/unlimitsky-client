<?php
global $sql;
require_once __DIR__ . '/../lib/license.php';

$GLOBALS['page_title'] = __('nav_plans');
$GLOBALS['active_nav'] = 'plans';

$plan_count = USK_License::current_plan_count();
$max_plans = USK_License::max_plans();
$can_add = USK_License::can_add_plan();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_main') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === 0) {
            $lic = USK_License::assert_can_add_plan(true);
            if (empty($lic['ok'])) {
                usk_flash(__('plan_limit_reached'), 'error');
                header('Location: ' . usk_admin_url('plans'));
                exit;
            }
        } else {
            $existing = $sql->query("SELECT `row` FROM `category` WHERE `row`=$id")->fetch_assoc();
            if (!$existing) {
                usk_flash(__('plan_not_found'), 'error');
                header('Location: ' . usk_admin_url('plans'));
                exit;
            }
        }

        $name = $sql->real_escape_string(trim($_POST['name'] ?? ''));
        $limit = max(0, (int) ($_POST['limit'] ?? 0));
        $date = max(0, (int) ($_POST['date'] ?? 0));
        $limit = $sql->real_escape_string((string) $limit);
        $date = $sql->real_escape_string((string) $date);
        $price = $sql->real_escape_string(trim($_POST['price'] ?? '0'));
        $status = $sql->real_escape_string($_POST['status'] ?? 'active');
        if (!in_array($status, array('active', 'inactive'), true)) {
            $status = 'active';
        }
        $connections = max(1, min(99, (int) ($_POST['connections'] ?? 1)));
        $connections_esc = $sql->real_escape_string((string) $connections);

        if ($id > 0) {
            $sql->query("UPDATE `category` SET `name`='$name',`limit`='$limit',`date`='$date',`price`='$price',`status`='$status',`connections`='$connections_esc' WHERE `row`=$id");
        } else {
            $code = (string) rand(111111, 999999);
            $sql->query("INSERT INTO `category` (`limit`,`date`,`name`,`price`,`code`,`status`,`connections`) VALUES ('$limit','$date','$name','$price','$code','$status','$connections_esc')");
        }

        usk_flash(__('plan_saved'));
        header('Location: ' . usk_admin_url('plans'));
        exit;
    }
    if ($action === 'delete_main') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $sql->query("DELETE FROM `category` WHERE `row`=$id");
        }
        usk_flash(__('plan_deleted'));
        header('Location: ' . usk_admin_url('plans'));
        exit;
    }
}

$edit_id = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($edit_id > 0) {
    $edit = $sql->query("SELECT * FROM `category` WHERE `row`=$edit_id")->fetch_assoc();
    if (!$edit) {
        usk_flash(__('plan_not_found'), 'error');
        header('Location: ' . usk_admin_url('plans'));
        exit;
    }
}

$plans = $sql->query("SELECT * FROM `category` ORDER BY `row` DESC");
$editConnections = max(1, min(99, (int) ($edit['connections'] ?? 1)));
?>
<?php if (!USK_License::is_pro()) : ?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-crown"></i> <?= __('plan_free_banner') ?> (<?= $plan_count ?>/<?= $max_plans ?>)
    <span class="d-block small mt-1 mb-0"><?= __('plan_free_edit_note') ?></span>
    <a href="<?= usk_admin_url('license') ?>" class="btn btn-usk-primary btn-sm ms-2 mt-2"><?= __('nav_license') ?></a>
</div>
<?php endif; ?>

<div class="usk-card mb-4">
    <h3 class="mb-3"><?= $edit ? __('plan_form_edit') : __('plan_form_add') ?></h3>
    <form method="post">
        <input type="hidden" name="action" value="save_main">
        <input type="hidden" name="id" value="<?= (int) ($edit['row'] ?? 0) ?>">
        <?php if ($edit) : ?>
        <div class="form-group mb-3">
            <label><?= __('plan_code') ?></label>
            <input class="form-control" value="<?= usk_esc($edit['code']) ?>" readonly dir="ltr" style="text-align:left;">
            <small class="text-muted"><?= __('plan_code_hint') ?></small>
        </div>
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label><?= __('plan_name') ?></label>
                <input class="form-control" name="name" required placeholder="<?= usk_esc(__('plan_name_placeholder')) ?>" value="<?= usk_esc($edit['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><?= __('plan_price') ?></label>
                <input class="form-control" name="price" type="number" min="0" required value="<?= usk_esc($edit['price'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label><?= __('plan_volume') ?></label>
                <input class="form-control" name="limit" type="number" min="0" required value="<?= usk_esc($edit['limit'] ?? '30') ?>">
                <small class="text-muted"><?= __('plan_volume_hint') ?></small>
            </div>
            <div class="form-group">
                <label><?= __('plan_duration') ?></label>
                <input class="form-control" name="date" type="number" min="0" required value="<?= usk_esc($edit['date'] ?? '30') ?>">
                <small class="text-muted"><?= __('plan_duration_hint') ?></small>
            </div>
        </div>
        <div class="form-group">
            <label><?= __('plan_connections') ?></label>
            <select class="form-control" name="connections" required>
                <?php for ($c = 1; $c <= 10; $c++) : ?>
                    <option value="<?= $c ?>"<?= $editConnections === $c ? ' selected' : (!$edit && $c === 1 ? ' selected' : '') ?>><?= $c ?> <?= __('plan_connections_unit') ?></option>
                <?php endfor; ?>
            </select>
            <small class="text-muted"><?= __('plan_connections_hint') ?></small>
        </div>
        <div class="form-group">
            <label><?= __('status') ?></label>
            <select class="form-control" name="status">
                <option value="active"<?= ($edit['status'] ?? 'active') === 'active' ? ' selected' : '' ?>><?= __('active') ?></option>
                <option value="inactive"<?= ($edit['status'] ?? '') === 'inactive' ? ' selected' : '' ?>><?= __('inactive') ?></option>
            </select>
        </div>
        <button type="submit" class="btn btn-usk-primary"<?= (!$edit && !$can_add) ? ' disabled title="' . usk_esc(__('plan_limit_reached')) . '"' : '' ?>><?= __('save') ?></button>
        <?php if ($edit) : ?>
            <a class="btn btn-outline ms-2" href="<?= usk_admin_url('plans') ?>"><?= __('cancel') ?></a>
        <?php endif; ?>
    </form>
</div>

<div class="usk-card">
    <h3 class="mb-3"><?= __('plan_list_title') ?></h3>
    <?php if (!$plans || $plans->num_rows === 0) : ?>
        <p class="text-muted mb-0"><?= __('plan_list_empty') ?></p>
    <?php else : ?>
    <div class="table-responsive">
        <table class="table table-dark table-hover mb-0">
            <thead>
                <tr>
                    <th><?= __('plan_name') ?></th>
                    <th><?= __('plan_code') ?></th>
                    <th><?= __('plan_volume') ?></th>
                    <th><?= __('plan_duration') ?></th>
                    <th><?= __('plan_connections') ?></th>
                    <th><?= __('plan_price') ?></th>
                    <th><?= __('status') ?></th>
                    <th><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php while ($p = $plans->fetch_assoc()) : ?>
                <tr<?= $edit && (int) $edit['row'] === (int) $p['row'] ? ' class="table-active"' : '' ?>>
                    <td><?= usk_esc($p['name']) ?></td>
                    <td><code class="usk-code"><?= usk_esc($p['code']) ?></code></td>
                    <td><?= usk_esc(usk_format_plan_gb($p['limit'])) ?></td>
                    <td><?= usk_esc(usk_format_plan_days($p['date'])) ?></td>
                    <td><?= usk_esc($p['connections'] ?? '1') ?></td>
                    <td><?= number_format((int) $p['price']) ?></td>
                    <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($p['status']) ?></span></td>
                    <td class="actions text-nowrap">
                        <a class="btn btn-sm btn-outline-usk" href="<?= usk_admin_url('plans', array('edit' => $p['row'])) ?>" title="<?= usk_esc(__('edit')) ?>">
                            <i class="fa-solid fa-pen"></i> <?= __('edit') ?>
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('<?= usk_esc(__('plan_delete_confirm')) ?>')">
                            <input type="hidden" name="action" value="delete_main">
                            <input type="hidden" name="id" value="<?= (int) $p['row'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="<?= usk_esc(__('delete')) ?>">
                                <i class="fa-solid fa-trash"></i> <?= __('delete') ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3"><?= __('plan_renewal_note') ?></p>
