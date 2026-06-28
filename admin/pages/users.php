<?php
global $sql;
$GLOBALS['page_title'] = __('nav_users');
$GLOBALS['active_nav'] = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $sql->real_escape_string(trim($_POST['from_id'] ?? ''));
    $action = $_POST['action'] ?? '';
    if ($action === 'coin') {
        $amount = (int) $_POST['amount'];
        $sql->query("UPDATE `users` SET `coin` = coin + $amount WHERE `from_id`='$id'");
        usk_flash(__('users_coin_updated'));
    }
    if ($action === 'block') {
        $sql->query("UPDATE `users` SET `status`='inactive' WHERE `from_id`='$id'");
        usk_flash(__('users_blocked'));
    }
    if ($action === 'unblock') {
        $sql->query("UPDATE `users` SET `status`='active' WHERE `from_id`='$id'");
        usk_flash(__('users_unblocked'));
    }
    header('Location: ' . usk_admin_url('users'));
    exit;
}

$search = $sql->real_escape_string(trim($_GET['q'] ?? ''));
$where = $search ? "WHERE `from_id` LIKE '%$search%' OR `phone` LIKE '%$search%'" : '';
$list = $sql->query("SELECT * FROM `users` $where ORDER BY `row` DESC LIMIT 80");
$count = $list ? $list->num_rows : 0;
?>
<div class="usk-card">
    <div class="alert alert-usk-info mb-3"><?= __('users_intro') ?></div>
    <form method="get" class="d-flex gap-2 mb-3 flex-wrap">
        <input type="hidden" name="page" value="users">
        <input class="form-control" name="q" placeholder="<?= __('users_search_ph') ?>" value="<?= usk_esc($_GET['q'] ?? '') ?>">
        <button class="btn btn-usk-primary"><?= __('search') ?></button>
    </form>
    <?php if ($count === 0) : ?>
        <p class="text-muted mb-0"><?= __('users_empty') ?></p>
    <?php else : ?>
    <div class="table-responsive">
    <table class="table table-sm">
        <thead><tr><th><?= __('users_telegram_id') ?></th><th><?= __('users_balance') ?></th><th><?= __('users_services_count') ?></th><th><?= __('status') ?></th><th></th></tr></thead>
        <tbody>
        <?php while ($u = $list->fetch_assoc()) : ?>
            <tr>
                <td><code><?= usk_esc($u['from_id']) ?></code></td>
                <td><?= number_format((int) $u['coin']) ?></td>
                <td><?= (int) $u['count_service'] ?></td>
                <td><span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($u['status']) ?></span></td>
                <td>
                    <form method="post" class="d-inline-flex gap-1 flex-wrap">
                        <input type="hidden" name="from_id" value="<?= usk_esc($u['from_id']) ?>">
                        <input type="hidden" name="action" value="coin">
                        <input name="amount" type="number" class="form-control form-control-sm" style="width:90px" placeholder="+<?= __('users_balance') ?>" required>
                        <button class="btn btn-sm btn-outline-secondary"><?= __('users_add_balance') ?></button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="from_id" value="<?= usk_esc($u['from_id']) ?>">
                        <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'block' : 'unblock' ?>">
                        <button class="btn btn-sm btn-outline-danger"><?= $u['status'] === 'active' ? __('users_block') : __('users_unblock') ?></button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
