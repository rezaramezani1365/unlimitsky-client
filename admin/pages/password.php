<?php
require_once __DIR__ . '/../lib/auth.php';
$GLOBALS['page_title'] = 'تغییر رمز عبور';
$GLOBALS['active_nav'] = 'password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password2'] ?? '';
    if (strlen($p1) < 6) {
        usk_flash('رمز حداقل ۶ کاراکتر', 'error');
    } elseif ($p1 !== $p2) {
        usk_flash('رمزها یکسان نیستند', 'error');
    } else {
        USK_Admin_Auth::change_password($p1);
        usk_flash('رمز عبور تغییر کرد');
        header('Location: ' . usk_admin_url('dashboard'));
        exit;
    }
}
?>
<div class="card" style="max-width:480px;">
    <?php if (USK_Admin_Auth::must_change_password()) : ?>
        <div class="alert alert-error">لطفاً رمز پیش‌فرض admin123 را تغییر دهید.</div>
    <?php endif; ?>
    <form method="post">
        <div class="form-group"><label>رمز جدید</label><input class="form-control" type="password" name="password" required minlength="6"></div>
        <div class="form-group"><label>تکرار رمز</label><input class="form-control" type="password" name="password2" required></div>
        <button type="submit" class="btn btn-primary">ذخیره رمز</button>
    </form>
</div>
