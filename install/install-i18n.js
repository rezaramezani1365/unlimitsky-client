(function () {
    var STR = {
        fa: {
            install_step1_sub: 'ابتدا زبان پیش‌فرض پنل را انتخاب کنید',
            install_step2_sub: 'حساب مدیر و اطلاعات دیتابیس را وارد کنید',
            install_language: 'زبان پنل',
            install_lang_hint: 'این زبان در پنل مدیریت استفاده می‌شود. بعداً از تنظیمات قابل تغییر است.',
            install_continue: 'ادامه',
            install_change_lang: 'تغییر زبان',
            settings_account: 'حساب مدیر',
            install_admin_user: 'نام کاربری مدیر',
            install_admin_pass: 'رمز عبور مدیر',
            install_db_section: 'دیتابیس',
            install_db_name: 'نام دیتابیس',
            install_db_user: 'یوزرنیم دیتابیس',
            install_db_pass: 'پسورد دیتابیس',
            install_submit: 'نصب و راه‌اندازی',
            lang_label_fa: 'فارسی',
            lang_label_en: 'English'
        },
        en: {
            install_step1_sub: 'First, choose the default panel language',
            install_step2_sub: 'Enter admin account and database details',
            install_language: 'Panel language',
            install_lang_hint: 'This language will be used in the admin panel. You can change it later in Settings.',
            install_continue: 'Continue',
            install_change_lang: 'Change language',
            settings_account: 'Admin account',
            install_admin_user: 'Admin username',
            install_admin_pass: 'Admin password',
            install_db_section: 'Database',
            install_db_name: 'Database name',
            install_db_user: 'Database user',
            install_db_pass: 'Database password',
            install_submit: 'Install & launch',
            lang_label_fa: 'فارسی',
            lang_label_en: 'English'
        }
    };

    var KEY = 'usk-install-lang';
    var currentLang = 'fa';
    var previewLang = 'fa';

    function getPack(lang) {
        return STR[lang] || STR.fa;
    }

    function applyTexts(lang) {
        var pack = getPack(lang);
        document.querySelectorAll('[data-i18n]').forEach(function (el) {
            var k = el.getAttribute('data-i18n');
            if (!pack[k]) return;
            if (el.tagName === 'LABEL') {
                var icon = el.querySelector('i');
                el.textContent = '';
                if (icon) el.appendChild(icon);
                el.appendChild(document.createTextNode(' ' + pack[k]));
            } else {
                el.textContent = pack[k];
            }
        });
        var label = document.getElementById('selected-lang-label');
        if (label) {
            label.textContent = lang === 'en' ? pack.lang_label_en : pack.lang_label_fa;
        }
    }

    function applyDirection(lang) {
        document.documentElement.lang = lang;
        document.documentElement.dir = lang === 'fa' ? 'rtl' : 'ltr';
        var bs = document.getElementById('bs-css');
        if (bs) {
            bs.href = lang === 'fa'
                ? '../admin/assets/vendor/bootstrap/bootstrap.rtl.min.css'
                : '../admin/assets/vendor/bootstrap/bootstrap.min.css';
        }
        document.querySelectorAll('.lang-btn').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-lang') === previewLang);
        });
        var nextBtn = document.getElementById('btn-step1-next');
        if (nextBtn) {
            var icon = nextBtn.querySelector('i');
            if (icon) {
                icon.className = lang === 'fa' ? 'fa-solid fa-arrow-left' : 'fa-solid fa-arrow-right';
            }
        }
    }

    function setPreviewLang(lang) {
        previewLang = lang;
        applyTexts(lang);
        applyDirection(lang);
    }

    function commitLang(lang) {
        currentLang = lang;
        previewLang = lang;
        localStorage.setItem(KEY, lang);
        var input = document.getElementById('language');
        if (input) input.value = lang;
        applyTexts(lang);
        applyDirection(lang);
    }

    function showStep(step) {
        var s1 = document.getElementById('step-language');
        var s2 = document.getElementById('step-setup');
        var dots = document.querySelectorAll('.step-dot');
        if (step === 1) {
            s1.classList.remove('hidden');
            s2.classList.add('hidden');
            setPreviewLang(currentLang);
        } else {
            commitLang(previewLang);
            s1.classList.add('hidden');
            s2.classList.remove('hidden');
        }
        dots.forEach(function (d) {
            d.classList.toggle('active', parseInt(d.getAttribute('data-step'), 10) === step);
            d.classList.toggle('done', parseInt(d.getAttribute('data-step'), 10) < step);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        currentLang = localStorage.getItem(KEY) || 'fa';
        previewLang = currentLang;

        applyTexts(currentLang);
        applyDirection(currentLang);

        document.querySelectorAll('.lang-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                previewLang = btn.getAttribute('data-lang');
                setPreviewLang(previewLang);
            });
        });

        document.getElementById('btn-step1-next').addEventListener('click', function () {
            commitLang(previewLang);
            showStep(2);
        });

        document.getElementById('btn-back-lang').addEventListener('click', function () {
            showStep(1);
        });

        showStep(1);
    });
})();
