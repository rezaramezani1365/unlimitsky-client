<?php
global $sql;
$GLOBALS['page_title'] = 'مدیریت پنل / سرور';
$GLOBALS['active_nav'] = 'panels';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

        // test login
        $panel = $sql->query("SELECT * FROM `panels` WHERE `code`='$panel_code'")->fetch_assoc();
        if ($type === 'marzban') {
            $login = loginPanel($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($login['access_token'])) {
                $t = $sql->real_escape_string($login['access_token']);
                $sql->query("UPDATE `panels` SET `token`='$t',`status`='active' WHERE `code`='$panel_code'");
                usk_flash('پنل ذخیره و اتصال Marzban موفق بود');
            } else {
                usk_flash('پنل ذخیره شد اما اتصال Marzban ناموفق', 'error');
            }
        } elseif ($type === 'sanayi') {
            $login = loginPanelSanayi($panel['login_link'], $panel['username'], $panel['password']);
            if (!empty($login['success'])) {
                $session = str_replace([" ", "\n", "\t"], ['', '', ''], explode('session	', file_get_contents('cookie.txt'))[1] ?? '');
                if ($session) {
                    $session = $sql->real_escape_string($session);
                    $sql->query("UPDATE `panels` SET `token`='$session',`status`='active' WHERE `code`='$panel_code'");
                }
                usk_flash('پنل ذخیره و اتصال Sanaei موفق بود');
            } else {
                usk_flash('پنل ذخیره شد اما اتصال Sanaei ناموفق', 'error');
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
            usk_flash('پنل حذف شد');
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
    while ($r = $ib->fetch_assoc()) $inbounds .= $r['inbound'] . "\n";
}
$list = $sql->query("SELECT * FROM `panels` ORDER BY `row` DESC");
?>
<div class="alert alert-usk-info d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <span><i class="fa-solid fa-book"></i> <?= __('guides_read_first') ?></span>
    <a href="<?= usk_admin_url('guides') ?>" class="btn btn-usk-primary btn-sm"><?= __('guides_view') ?></a>
</div>
<div class="card">
    <h3 style="margin-bottom:16px;"><?= $edit ? 'ویرایش پنل' : 'افزودن پنل جدید' ?></h3>
    <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($edit['row'] ?? 0) ?>">
        <div class="form-row">
            <div class="form-group"><label>نام نمایشی</label><input class="form-control" name="name" required value="<?= usk_esc($edit['name'] ?? '') ?>"></div>
            <div class="form-group"><label>نوع</label>
                <select class="form-control" name="type">
                    <option value="marzban" <?= ($edit['type'] ?? '') === 'marzban' ? 'selected' : '' ?>>Marzban</option>
                    <option value="sanayi" <?= ($edit['type'] ?? '') === 'sanayi' ? 'selected' : '' ?>>Sanaei (3x-ui)</option>
                </select>
            </div>
        </div>
        <div class="form-group"><label>آدرس پنل</label><input class="form-control" name="login_link" required placeholder="https://ip:8000" value="<?= usk_esc($edit['login_link'] ?? '') ?>"></div>
        <div class="form-row">
            <div class="form-group"><label>یوزرنیم</label><input class="form-control" name="username" value="<?= usk_esc($edit['username'] ?? '') ?>"></div>
            <div class="form-group"><label>رمز</label><input class="form-control" type="password" name="password" value="<?= usk_esc($edit['password'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>پروتکل‌ها (Marzban)</label><input class="form-control" name="protocols" value="<?= usk_esc($edit['protocols'] ?? 'vless|') ?>"></div>
            <div class="form-group"><label>وضعیت</label>
                <select class="form-control" name="status">
                    <option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>فعال</option>
                    <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
                </select>
            </div>
        </div>
        <div class="form-group"><label>Inbounds Marzban (هر خط یکی)</label><textarea class="form-control" name="inbounds" rows="3"><?= usk_esc(trim($inbounds)) ?></textarea></div>
        <div class="form-row">
            <div class="form-group"><label>Inbound ID (Sanaei)</label><input class="form-control" name="inbound_id" value="<?= usk_esc($sanayi['inbound_id'] ?? '') ?>"></div>
            <div class="form-group"><label>Flow</label><input class="form-control" name="flow" value="<?= usk_esc($edit['flow'] ?? 'flowon') ?>"></div>
        </div>
        <div class="form-group"><label>قالب لینک Sanaei (%s1 uuid, %s2 host:port, %s3 remark)</label><textarea class="form-control" name="example_link" rows="2"><?= usk_esc($sanayi['example_link'] ?? '') ?></textarea></div>
        <button type="submit" class="btn btn-primary"><?= $edit ? 'به‌روزرسانی' : 'افزودن و تست اتصال' ?></button>
        <?php if ($edit) : ?><a class="btn btn-outline" href="<?= usk_admin_url('panels') ?>">انصراف</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3 style="margin-bottom:16px;">لیست پنل‌ها</h3>
    <table>
        <thead><tr><th>نام</th><th>نوع</th><th>آدرس</th><th>ساخته‌شده</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php while ($p = $list->fetch_assoc()) : ?>
            <tr>
                <td><?= usk_esc($p['name']) ?></td>
                <td><?= usk_esc($p['type']) ?></td>
                <td><code><?= usk_esc($p['login_link']) ?></code></td>
                <td><?= usk_esc($p['count_create']) ?></td>
                <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'danger' ?>"><?= usk_esc($p['status']) ?></span></td>
                <td class="actions">
                    <a class="btn btn-sm btn-outline" href="<?= usk_admin_url('panels', ['edit' => $p['row']]) ?>">ویرایش</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('حذف شود؟')">
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['row'] ?>">
                        <button class="btn btn-sm btn-danger">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
