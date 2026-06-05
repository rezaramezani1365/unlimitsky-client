<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function usk_install_t($key, $lang)
{
    static $packs = null;
    if ($packs === null) {
        $packs = array(
            'fa' => array(
                'brand' => 'UnlimitedSky',
                'step1_title' => 'انتخاب زبان پیش‌فرض',
                'step1_sub' => 'ابتدا زبان پنل مدیریت را انتخاب کنید',
                'step2_title' => 'تنظیمات نصب',
                'step2_sub' => 'حساب مدیر — دیتابیس خودکار ساخته شده',
                'language' => 'زبان پنل',
                'lang_fa' => 'فارسی',
                'lang_en' => 'English',
                'continue' => 'ادامه',
                'change_lang' => 'تغییر زبان',
                'admin_section' => 'حساب مدیر',
                'admin_user' => 'نام کاربری مدیر',
                'admin_pass' => 'رمز عبور مدیر',
                'admin_pass_hint' => 'خالی = admin (باید در اولین ورود عوض شود)',
                'admin_pass_help' => 'برای نصب سریع خالی بگذارید: ورود با admin / admin سپس تغییر اجباری رمز.',
                'db_auto_note' => 'دیتابیس MySQL با رمز تصادفی روی همین سرور ساخته شده — نیازی به وارد کردن اطلاعات DB نیست.',
                'provision_missing' => 'ابتدا روی سرور دستور sudo bash install-ubuntu.sh --port 8082 را اجرا کنید.',
                'submit' => 'نصب و راه‌اندازی',
                'selected_lang' => 'زبان انتخاب‌شده',
            ),
            'en' => array(
                'brand' => 'UnlimitedSky',
                'step1_title' => 'Choose default language',
                'step1_sub' => 'First, select the admin panel language',
                'step2_title' => 'Installation setup',
                'step2_sub' => 'Admin account — database already provisioned',
                'language' => 'Panel language',
                'lang_fa' => 'فارسی',
                'lang_en' => 'English',
                'continue' => 'Continue',
                'change_lang' => 'Change language',
                'admin_section' => 'Admin account',
                'admin_user' => 'Admin username',
                'admin_pass' => 'Admin password',
                'admin_pass_hint' => 'Leave empty = admin (must change on first login)',
                'admin_pass_help' => 'For quick setup leave empty: login with admin / admin, then change password.',
                'db_auto_note' => 'MySQL was created automatically with a random password on this server — no DB fields needed.',
                'provision_missing' => 'Run sudo bash install-ubuntu.sh --port 8082 on the server first.',
                'submit' => 'Install & launch',
                'selected_lang' => 'Selected language',
            ),
        );
    }
    $lang = ($lang === 'en') ? 'en' : 'fa';
    return isset($packs[$lang][$key]) ? $packs[$lang][$key] : $key;
}

function usk_install_lang_label($lang)
{
    return $lang === 'en' ? 'English' : 'فارسی';
}

function usk_install_head($lang, $title)
{
    $dir = ($lang === 'en') ? 'ltr' : 'rtl';
    $bs = ($lang === 'en')
        ? '../admin/assets/vendor/bootstrap/bootstrap.min.css'
        : '../admin/assets/vendor/bootstrap/bootstrap.rtl.min.css';
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars($lang) . '" dir="' . $dir . '" data-bs-theme="dark">';
    echo '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . ' | UnlimitedSky</title>';
    echo '<link rel="stylesheet" href="../admin/assets/css/fonts.css">';
    echo '<link rel="stylesheet" href="../admin/assets/vendor/fontawesome/css/all.min.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($bs) . '">';
    echo '<link rel="stylesheet" href="style.css">';
    echo '</head><body class="install-body"><div class="install-wrap"><div class="install-card">';
}

function usk_install_foot()
{
    echo '</div></div></body></html>';
}

function usk_install_steps($current)
{
    echo '<div class="install-steps">';
    echo '<span class="step-dot' . ($current >= 1 ? ' active' : '') . ($current > 1 ? ' done' : '') . '">1</span>';
    echo '<span class="step-line"></span>';
    echo '<span class="step-dot' . ($current >= 2 ? ' active' : '') . '">2</span>';
    echo '</div>';
}

function usk_install_brand()
{
    echo '<div class="install-brand">Unlimited<span>Sky</span></div>';
}

function usk_valid_lang($lang)
{
    return in_array($lang, array('fa', 'en'), true) ? $lang : 'fa';
}
