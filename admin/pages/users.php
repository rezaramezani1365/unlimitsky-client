<?php
global $sql;
$GLOBALS['page_title'] = 'مدیریت کاربران';
$GLOBALS['active_nav'] = 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $sql->real_escape_string(trim($_POST['from_id'] ?? ''));
    $action = $_POST['action'] ?? '';
    if ($action === 'coin') {
        $amount = (int) $_POST['amount'];
        $sql->query("UPDATE `users` SET `coin` = coin + $amount WHERE `from_id`='$id'");
        usk_flash('موجودی به‌روز شد');
    }
    if ($action === 'block') {
        $sql->query("UPDATE `users` SET `status`='inactive' WHERE `from_id`='$id'");
        usk_flash('کاربر مسدود شد');
    }
    if ($action === 'unblock') {
        $sql->query("UPDATE `users` SET `status`='active' WHERE `from_id`='$id'");
        usk_flash('کاربر آزاد شد');
    }
    header('Location: ' . usk_admin_url('users'));
    exit;
}

$search = $sql->real_escape_string(trim($_GET['q'] ?? ''));
$where = $search ? "WHERE `from_id` LIKE '%$search%' OR `phone` LIKE '%$search%'" : '';
$list = $sql->query("SELECT * FROM `users` $where ORDER BY `row` DESC LIMIT 80");
?>
<div class="card">
    <form method="get" style="display:flex;gap:10px;margin-bottom:16px;">
        <input type="hidden" name="page" value="users">
        <input class="form-control" name="q" placeholder="جستجو آیدی تلگرام..." value="<?= usk_esc($_GET['q'] ?? '') ?>">
        <button class="btn btn-primary">جستجو</button>
    </form>
    <table>
        <thead><tr><th>آیدی</th><th>موجودی</th><th>سرویس</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php while ($u = $list->fetch_assoc()) : ?>
            <tr>
                <td><code><?= usk_esc($u['from_id']) ?></code></td>
                <td><?= number_format((int) $u['coin']) ?></td>
                <td><?= (int) $u['count_service'] ?></td>
                <td><span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($u['status']) ?></span></td>
                <td>
                    <form method="post" style="display:inline-flex;gap:4px;flex-wrap:wrap;">
                        <input type="hidden" name="from_id" value="<?= usk_esc($u['from_id']) ?>">
                        <input type="hidden" name="action" value="coin">
                        <input name="amount" type="number" placeholder="+تومان" style="width:80px;padding:4px;" required>
                        <button class="btn btn-sm btn-outline">موجودی</button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="from_id" value="<?= usk_esc($u['from_id']) ?>">
                        <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'block' : 'unblock' ?>">
                        <button class="btn btn-sm btn-danger"><?= $u['status'] === 'active' ? 'مسدود' : 'آزاد' ?></button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
