<?php
global $sql;
$GLOBALS['page_title'] = 'کد تخفیف';
$GLOBALS['active_nav'] = 'coupons';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'add') {
        $c = $sql->real_escape_string(trim($_POST['copen'] ?? ''));
        $p = $sql->real_escape_string(trim($_POST['percent'] ?? '10'));
        $u = $sql->real_escape_string(trim($_POST['count_use'] ?? '100'));
        $sql->query("INSERT INTO `copens` (`copen`,`percent`,`count_use`,`status`) VALUES ('$c','$p','$u','active')");
        usk_flash('کد تخفیف اضافه شد');
    }
    if (($_POST['action'] ?? '') === 'delete') {
        $sql->query("DELETE FROM `copens` WHERE `copen`='" . $sql->real_escape_string($_POST['copen'] ?? '') . "'");
        usk_flash('حذف شد');
    }
    header('Location: ' . usk_admin_url('coupons'));
    exit;
}

$list = $sql->query("SELECT * FROM `copens` ORDER BY `copen`");
?>
<div class="card">
    <form method="post">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <div class="form-group"><label>کد</label><input class="form-control" name="copen" required></div>
            <div class="form-group"><label>درصد تخفیف</label><input class="form-control" name="percent" type="number" value="10"></div>
            <div class="form-group"><label>تعداد استفاده</label><input class="form-control" name="count_use" type="number" value="100"></div>
        </div>
        <button type="submit" class="btn btn-primary">افزودن</button>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>کد</th><th>درصد</th><th>باقی‌مانده</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php while ($c = $list->fetch_assoc()) : ?>
            <tr>
                <td><code><?= usk_esc($c['copen']) ?></code></td>
                <td><?= usk_esc($c['percent']) ?>%</td>
                <td><?= usk_esc($c['count_use']) ?></td>
                <td><?= usk_esc($c['status']) ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('حذف؟')">
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="copen" value="<?= usk_esc($c['copen']) ?>">
                        <button class="btn btn-sm btn-danger">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
