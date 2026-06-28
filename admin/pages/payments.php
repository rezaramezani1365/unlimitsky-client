<?php
global $sql;
$GLOBALS['page_title'] = 'درگاه پرداخت';
$GLOBALS['active_nav'] = 'payments';

$row = $sql->query("SELECT * FROM `payment_setting` LIMIT 1")->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $z = $sql->real_escape_string(trim($_POST['zarinpal_token'] ?? 'none'));
    $i = $sql->real_escape_string(trim($_POST['idpay_token'] ?? 'none'));
    $n = $sql->real_escape_string(trim($_POST['nowpayment_token'] ?? 'none'));
    $card = $sql->real_escape_string(trim($_POST['card_number'] ?? 'none'));
    $card_name = $sql->real_escape_string(trim($_POST['card_number_name'] ?? 'none'));
    $zs = $_POST['zarinpal_status'] ?? 'inactive';
    $is = $_POST['idpay_status'] ?? 'inactive';
    $ns = $_POST['nowpayment_status'] ?? 'inactive';
    $cs = $_POST['card_status'] ?? 'inactive';
    $sql->query("UPDATE `payment_setting` SET
        `zarinpal_token`='$z', `idpay_token`='$i', `nowpayment_token`='$n',
        `card_number`='$card', `card_number_name`='$card_name',
        `zarinpal_status`='$zs', `idpay_status`='$is', `nowpayment_status`='$ns', `card_status`='$cs'");
    usk_flash('تنظیمات پرداخت ذخیره شد');
    header('Location: ' . usk_admin_url('payments'));
    exit;
}
?>
<div class="card">
    <form method="post">
        <div class="form-row">
            <div class="form-group"><label>توکن زرین‌پال</label><input class="form-control" name="zarinpal_token" value="<?= usk_esc($row['zarinpal_token'] ?? '') ?>"></div>
            <div class="form-group"><label>وضعیت زرین‌پال</label>
                <select class="form-control" name="zarinpal_status">
                    <option value="active" <?= ($row['zarinpal_status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>توکن IDPay</label><input class="form-control" name="idpay_token" value="<?= usk_esc($row['idpay_token'] ?? '') ?>"></div>
            <div class="form-group"><label>وضعیت IDPay</label>
                <select class="form-control" name="idpay_status">
                    <option value="active" <?= ($row['idpay_status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>شماره کارت</label><input class="form-control" name="card_number" value="<?= usk_esc($row['card_number'] ?? '') ?>"></div>
            <div class="form-group"><label>نام صاحب کارت</label><input class="form-control" name="card_number_name" value="<?= usk_esc($row['card_number_name'] ?? '') ?>"></div>
        </div>
        <div class="form-group"><label>کارت به کارت</label>
            <select class="form-control" name="card_status">
                <option value="active" <?= ($row['card_status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="inactive">غیرفعال</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </form>
</div>
