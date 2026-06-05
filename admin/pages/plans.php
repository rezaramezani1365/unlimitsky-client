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
        }
        $name = $sql->real_escape_string(trim($_POST['name'] ?? ''));
        $limit = $sql->real_escape_string(trim($_POST['limit'] ?? '0'));
        $date = $sql->real_escape_string(trim($_POST['date'] ?? '0'));
        $price = $sql->real_escape_string(trim($_POST['price'] ?? '0'));
        $status = $sql->real_escape_string($_POST['status'] ?? 'active');
        $code = (string) rand(111111, 999999);
        if ($id > 0) {
            $sql->query("UPDATE `category` SET `name`='$name',`limit`='$limit',`date`='$date',`price`='$price',`status`='$status' WHERE `row`=$id");
        } else {
            $sql->query("INSERT INTO `category` (`limit`,`date`,`name`,`price`,`code`,`status`) VALUES ('$limit','$date','$name','$price','$code','$status')");
        }
        usk_flash('پلن ذخیره شد');
        header('Location: ' . usk_admin_url('plans'));
        exit;
    }
    if ($action === 'delete_main') {
        $sql->query("DELETE FROM `category` WHERE `row`=" . (int) $_POST['id']);
        usk_flash('حذف شد');
        header('Location: ' . usk_admin_url('plans'));
        exit;
    }
}

$plans = $sql->query("SELECT * FROM `category` ORDER BY `row` DESC");
?>
<?php if (!USK_License::is_pro()) : ?>
<div class="alert alert-usk-info mb-4">
    <i class="fa-solid fa-crown"></i> <?= __('plan_free_banner') ?> (<?= $plan_count ?>/<?= $max_plans ?>)
    <a href="<?= usk_admin_url('license') ?>" class="btn btn-usk-primary btn-sm ms-2"><?= __('nav_license') ?></a>
</div>
<?php endif; ?>
<div class="card">
    <h3 style="margin-bottom:16px;">افزودن / ویرایش پلن خرید</h3>
    <form method="post">
        <input type="hidden" name="action" value="save_main">
        <input type="hidden" name="id" value="0">
        <div class="form-row">
            <div class="form-group"><label>نام پلن</label><input class="form-control" name="name" required placeholder="یک ماهه 50 گیگ"></div>
            <div class="form-group"><label>قیمت (تومان)</label><input class="form-control" name="price" type="number" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>حجم (GB)</label><input class="form-control" name="limit" type="number" required></div>
            <div class="form-group"><label>مدت (روز)</label><input class="form-control" name="date" type="number" required></div>
        </div>
        <div class="form-group"><label>وضعیت</label>
            <select class="form-control" name="status"><option value="active">فعال</option><option value="inactive">غیرفعال</option></select>
        </div>
        <button type="submit" class="btn btn-primary" <?= !$can_add ? 'disabled title="' . __('plan_limit_reached') . '"' : '' ?>><?= __('save') ?></button>
    </form>
</div>

<div class="card">
    <h3 style="margin-bottom:16px;">پلن‌های ثبت‌شده</h3>
    <table>
        <thead><tr><th>نام</th><th>کد</th><th>حجم</th><th>مدت</th><th>قیمت</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php while ($p = $plans->fetch_assoc()) : ?>
            <tr>
                <td><?= usk_esc($p['name']) ?></td>
                <td><code class="usk-code"><?= usk_esc($p['code']) ?></code></td>
                <td><?= usk_esc($p['limit']) ?> GB</td>
                <td><?= usk_esc($p['date']) ?> روز</td>
                <td><?= number_format((int) $p['price']) ?></td>
                <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($p['status']) ?></span></td>
                <td>
                    <form method="post" onsubmit="return confirm('حذف؟')" style="display:inline">
                        <input type="hidden" name="action" value="delete_main"><input type="hidden" name="id" value="<?= (int) $p['row'] ?>">
                        <button class="btn btn-sm btn-danger">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<p style="color:var(--muted);font-size:13px;">پلن‌های زمانی و حجمی (تمدید) از منوی تلگرام — در نسخه بعد وب اضافه می‌شود.</p>
