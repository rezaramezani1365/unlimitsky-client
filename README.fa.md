# unlimitsky — پنل فروش VPN (Client)

[![GitHub](https://img.shields.io/badge/GitHub-unlimitsky--client-blue?logo=github)](https://github.com/rezaramezani1365/unlimitsky-client)

> **English:** [README.md](README.md)

پنل مدیریتی برای **فروشندگان VPN**. روی VPS اوبونتو نصب می‌کنی، پلن می‌سازی، از **ووکامرس** می‌فروشی و در صورت نیاز **Marzban / Sanaei** را وصل می‌کنی (Pro).

**ریپازیتوری:** [github.com/rezaramezani1365/unlimitsky-client](https://github.com/rezaramezani1365/unlimitsky-client)

---

## فهرست

1. [این پنل چیست؟](#این-پنل-چیست)
2. [نصب با یک دستور](#نصب-با-یک-دستور)
3. [امکانات](#امکانات)
4. [پروتکل‌های VPN](#پروتکل‌های-vpn)
5. [Free در مقابل Pro](#free-در-مقابل-pro)
6. [پیش‌نیازها](#پیش‌نیازها)
7. [بعد از نصب — اولین قدم‌ها](#بعد-از-نصب--اولین-قدم‌ها)
8. [فروشگاه ووکامرس](#فروشگاه-ووکامرس)
9. [مصرف، محدودیت و تعداد اتصال](#مصرف-محدودیت-و-تعداد-اتصال)
10. [به‌روزرسانی و مهاجرت VPS](#به‌روزرسانی-و-مهاجرت-vps)
11. [اختیاری: Marzban / Sanaei (Pro)](#اختیاری-marzban--sanaei-pro)
12. [اختیاری: Node — VPS دوم (Pro)](#اختیاری-node--vps-دوم-pro)
13. [عیب‌یابی](#عیب‌یابی)
14. [امنیت](#امنیت)
15. [ساختار پروژه](#ساختار-پروژه)

---

## این پنل چیست؟

```
شما (فروشنده VPN)
    ├── VPS اوبونتو  →  پنل unlimitsky (این پروژه)
    └── هاست وردپرس  →  ووکامرس + پلاگین unlimitsky
                              ↓
                    مشتری پرداخت می‌کند → کانفیگ خودکار تحویل
```

unlimitsky یک **پنل فروشنده self-hosted** است. داده‌ها روی **VPS خودت** می‌ماند (MySQL + فایل). برای WireGuard، OpenVPN، Xray و L2TP نیازی به پنل شخص ثالث نیست — اسکریپت‌ها VPN را مستقیم روی سرور نصب می‌کنند.

| داده | محل |
|------|-----|
| پلن‌ها، سرویس‌ها، ادمین | **MySQL** روی VPS |
| تنظیمات پنل، لایسنس | `admin/data/` روی VPS |
| وضعیت پروتکل‌ها | `data/protocols/` روی VPS |
| سفارش ووکامرس | دیتابیس وردپرس روی هاست فروشگاه |

---

## نصب با یک دستور

روی **VPS اوبونتو خام** (۲۲.۰۴ یا ۲۴.۰۴) با SSH و root اجرا کن:

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall
```

اسکریپت **nginx، MySQL، PHP** را نصب می‌کند، دیتابیس و اکانت admin را می‌سازد و فایروال را باز می‌کند.

| بعد از نصب | مقدار |
|------------|--------|
| **آدرس پنل** | `http://IP-سرور:8082/admin/login.php` |
| **نام کاربری** | `admin` |
| **رمز** | همان `--admin-pass` (حداقل ۶ کاراکتر) |
| **ذخیره روی سرور** | `sudo cat /root/unlimitsky-client.credentials` |

| فلگ | معنی |
|-----|------|
| `--port 8082` | پورت وب پنل |
| `--admin-pass '...'` | رمز مدیر (بدون آن = `admin` / `admin` + تغییر اجباری در اولین ورود) |
| `--open-firewall` | باز کردن پورت در ufw |

**اختیاری — لایسنس Pro از vendor:**

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall \
  --license-url 'https://license.yourdomain.com/api/v1.php' \
  --license-token 'SECRET'
```

**روش دیگر — clone و نصب:**

```bash
sudo git clone https://github.com/rezaramezani1365/unlimitsky-client.git /opt/unlimitsky
cd /opt/unlimitsky
sudo bash install-ubuntu.sh --auto --port 8082 --admin-pass 'Pass123' --open-firewall
```

**نصب از مرورگر** (بدون `--auto`): `install-ubuntu.sh` را بدون `--auto` اجرا کن، بعد `http://IP:8082/install/index.php` — فیلد دیتابیس خودکار است.

پورت **808۲** را در پنل هاست ابری (Hetzner، DigitalOcean و …) هم باز کن.

---

## امکانات

| امکان | توضیح |
|-------|--------|
| **VPN native روی VPS** | WireGuard، OpenVPN، Xray، L2TP — از **پنل → پروتکل‌ها** |
| **پلن فروش** | حجم (GB)، مدت (روز)، قیمت نمایشی |
| **ساخت دستی کانفیگ** | **پنل → ساخت کانفیگ** بدون پلن |
| **پلاگین ووکامرس** | تحویل خودکار بعد از پرداخت (native یا Marzban/Sanaei) |
| **QR وایرگارد** | QR در ایمیل و صفحه سفارش |
| **sync مصرف** | ثبت گیگ مصرف‌شده؛ غیرفعال‌سازی سرویس تمام‌شده |
| **تعداد اتصال (slot)** | محدودیت دستگاه همزمان (تک‌کاربره / ۲ کاربره) — هنگام اتصال اعمال می‌شود |
| **فاصله sync مصرف** | **پنل → تنظیمات** — ۱ تا ۱۲۰ دقیقه (پیش‌فرض ۵) بر اساس قدرت VPS |
| **بکاپ و مهاجرت** | export/import فایل `.uskbackup` بین سرورها |
| **پنل فارسی/انگلیسی** | تم روشن/تاریک |
| **Pro** | پلن نامحدود، Marzban/Sanaei، Node (Xray روی VPS دوم) |

---

## پروتکل‌های VPN

از **پنل → پروتکل‌ها** نصب کن. هر پروتکل **روی همین VPS** اجرا می‌شود.

| پروتکل | پورت پیش‌فرض | مناسب برای |
|--------|--------------|------------|
| **WireGuard** | 51820 | موبایل و دسکتاپ — سریع و سبک |
| **OpenVPN** | 1194 | سازگاری با اکثر دستگاه‌ها |
| **Xray (VLESS/VMess)** | 443 | عبور از فیلتر، پشت CDN |
| **L2TP/IPsec** | 1701 | روتر و VPN داخلی ویندوز |

**پیشنهاد برای تازه‌کار:** اول **WireGuard**، یک پلن، بعد ووکامرس.

### native در مقابل Marzban / Sanaei

| | native (داخلی) | Marzban / Sanaei (اختیاری، Pro) |
|---|----------------|----------------------------------|
| راه‌اندازی | **پنل → پروتکل‌ها** | Marzban یا 3x-ui جداگانه |
| پروتکل‌ها | WireGuard، OpenVPN، Xray، L2TP | فقط VLESS / VMess |
| ووکامرس | ✅ کلید API + پنل unlimitsky | ✅ از API unlimitsky یا مستقیم در WP |

---

## Free در مقابل Pro

| امکان | Free | Pro |
|-------|------|-----|
| همه پروتکل‌های native | ✅ | ✅ |
| پلن فروش | **۱ پلن** | نامحدود (طبق لایسنس) |
| پنل + پلاگین ووکامرس | ✅ | ✅ |
| Marzban / Sanaei | — | ✅ **پنل → پنل‌ها / سرور** |
| Node (Xray روی VPS دیگر) | — | ✅ **پنل → Node / سرور دوم** |
| فعال‌سازی | — | **پنل → لایسنس Pro** → کلید `USK-...` |

پیام `License server not configured` در نصب برای **Free عادی** است — پنل بدون لایسنس vendor کار می‌کند.

---

## پیش‌نیازها

| بخش | حداقل |
|-----|--------|
| **VPS پنل** | Ubuntu 20.04 / 22.04 / 24.04 · 1 GB RAM (2 GB بهتر) · root/sudo · پورت 8082 باز |
| **فروشگاه (ووکامرس)** | WordPress 5.8+ · WooCommerce 5.0+ · PHP 7.4+ با cURL · HTTPS پیشنهادی |
| **Node (Pro، اختیاری)** | VPS دوم اوبونتو · SSH با پسورد از Hub · `sshpass` روی Hub |

---

## بعد از نصب — اولین قدم‌ها

اگر تازه‌کار هستی، به این ترتیب برو:

1. **ورود** — `http://IP:8082/admin/login.php` · رمز پیش‌فرض را عوض کن.
2. **پنل → پروتکل‌ها** — **WireGuard** را نصب کن. تا وضعیت active شود صبر کن.
3. **پنل → پلن‌ها** — یک پلن (حجم + روز). Free فقط ۱ پلن.
4. **پنل → ساخت کانفیگ** — قبل از فروش یک کانفیگ تست بساز.
5. **پنل → کلید API** — برای ووکامرس (فقط یک‌بار نمایش داده می‌شود — کپی کن).
6. **پنل → تنظیمات** — فاصله sync مصرف (مثلاً ۵ دقیقه برای VPS کوچک، ۱۵–۳۰ برای سرور ضعیف).
7. **پلاگین ووکامرس** را آپلود کن (پایین).

**sudo برای اسکریپت VPN:** با اسکریپت نصب معمولاً `/etc/sudoers.d/unlimitsky` خودکار ساخته می‌شود. بدون آن نصب پروتکل و ساخت کانفیگ fail می‌شود:

```bash
sudo cat /etc/sudoers.d/unlimitsky
```

اگر نبود، بخش [امنیت](#امنیت) را ببین.

---

## فروشگاه ووکامرس

فروشگاه روی **هاست وردپرس** است (می‌تواند جدا از VPS باشد).

### ۱. نصب پلاگین

پوشه `wordpress-plugin/unlimitsky-woocommerce/` را در `wp-content/plugins/` کپی کن (یا ZIP از **افزونه‌ها → افزودن**). فعال کن و یک‌بار **تنظیمات → پیوندهای یکتا → ذخیره**.

### ۲. اتصال پنل (native — پیشنهادی)

1. روی VPS: **پنل → کلید API** — آدرس API و کلید را کپی کن.
2. **وردپرس → unlimitsky → پنل‌ها → افزودن** — نوع **unlimitsky (native)** · آدرس `http://IP_VPS:8082` · کلید · **تست اتصال**.
3. **محصولات → محصول VPN** — تیک VPN · پنل unlimitsky · حجم، روز، قیمت.
4. مشتری پرداخت می‌کند → کانفیگ در سفارش، ایمیل و **حساب کاربری → سرویس‌های VPN**.

### ۳. Marzban / Sanaei از ووکامرس (Pro)

اول پنل را روی VPS وصل کن (**پنل → پنل‌ها / سرور**)، در محصول **محل ساخت: Marzban / Sanaei** را انتخاب کن. جزئیات: [اختیاری: Marzban / Sanaei](#اختیاری-marzban--sanaei-pro).

راهنمای تکمیلی: [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)

---

## مصرف، محدودیت و تعداد اتصال

### مصرف ترافیک (GB)

- **دستی:** **پنل → سرویس‌ها → بروزرسانی مصرف همه**
- **خودکار:** نصب تازه cron دارد (`usage-sync-gate.php` هر دقیقه). sync سنگین فقط وقتی فاصله **پنل → تنظیمات** رسیده اجرا می‌شود (پیش‌فرض **۵ دقیقه**، بازه ۱–۱۲۰).
- سرویس تمام‌شده روی سرور **غیرفعال** می‌شود. رکورد در **پنل → سرویس‌ها** می‌ماند — تمدید یا حذف دستی.

لاگ cron:

```bash
sudo tail -20 /var/log/unlimitsky-limits.log
```

تست دستی:

```bash
sudo -u www-data php /var/www/unlimitsky/cron/native-limits.php
```

### مصرف Xray (Stats API — داخل پنل، بدون Prometheus)

برای **Xray/VLESS** پنل همان داده‌ای را می‌خواند که [xray-exporter](https://github.com/anatolykopyl/xray-exporter) از **StatsService** می‌گیرد: API روی `127.0.0.1:10085` (`stats` + `policy` در `config.json`). **email** هر کلاینت در Xray باید با نام کاربری سرویس یکی باشد (موقع ساخت کانفیگ خودکار تنظیم می‌شود).

| مورد | روش |
|------|-----|
| sync پنل | `collect-usage-stats.sh` → حجم تجمعی هر کاربر → **سرویس‌ها** |
| نصب / تعمیر API | خودکار با نصب Xray؛ دستی: `sudo bash /var/www/unlimitsky/bin/xray-fix-stats-api.sh` |
| عیب‌یابی | `sudo php /var/www/unlimitsky/cron/diagnose-usage-sync.php` |
| Grafana اختیاری | `sudo bash /var/www/unlimitsky/bin/optional-install-xray-exporter.sh` (فقط Prometheus — پنل به آن نیاز ندارد) |

اگر مصرف Xray **۰ GB** ماند: `xray-fix-stats-api.sh` بزن، بعد **بروزرسانی مصرف همه**. تست API: `xray api statsquery --server=127.0.0.1:10085 | head`

### تعداد اتصال (پلن چندکاربره)

پلن می‌تواند **۱، ۲، … N دستگاه همزمان** (IP متفاوت) داشته باشد. محدودیت **هنگام اتصال** اعمال می‌شود — نه با polling. در پورتال مشتری برچسب «تک‌کاربره» یا «۲ کاربره» دیده می‌شود.

روی **OpenVPN** و **Xray** native (هوک‌ها با به‌روزرسانی پنل خودکار نصب می‌شوند).

---

## به‌روزرسانی و مهاجرت VPS

### به‌روزرسانی فایل‌ها (بدون حذف دیتابیس)

```bash
sudo bash /var/www/unlimitsky/scripts/panel-self-update.sh
```

یا همان `curl install.sh` (امن — فایل‌ها را به‌روز می‌کند).

### انتقال به VPS جدید

1. **سرور قدیم** → **پنل → بکاپ و مهاجرت** → دانلود `.uskbackup`
2. **سرور جدید** → نصب unlimitsky (یک دستور بالا)
3. **سرور جدید** → import بکاپ
4. **پنل → پروتکل‌ها** → نصب مجدد (کلید VPN سطح OS در بکاپ نیست)
5. در صورت نیاز **کلید API** جدید برای ووکامرس
6. اگر **Pro** داشتی: بعد از import موقتاً Free است → **پنل → لایسنس Pro → فعال‌سازی** (اگر IP عوض شد با فروشنده لایسنس هماهنگ کن)

---

## اختیاری: Marzban / Sanaei (Pro)

نیاز به **Pro** و نصب قبلی **Marzban** یا **Sanaei (3x-ui)**. فقط **VLESS / VMess** — نه WireGuard یا OpenVPN.

1. **پنل → لایسنس Pro** → کلید `USK-...`
2. **پنل → پنل‌ها / سرور** → افزودن → **ذخیره و تست اتصال**
3. **پنل → راهنمای اتصال** — توضیح فیلدها در خود پنل

| پنل | نمونه URL | فیلدهای اضافه |
|-----|-----------|----------------|
| **Marzban** | `https://IP:8000` | پروتکل‌ها `vless\|vmess\|`، تگ inbound |
| **Sanaei** | `http://IP:2053` | Inbound ID، قالب لینک (`%s1` UUID، `%s2` host:port، `%s3` remark) |

**ووکامرس:** پنل unlimitsky (native) + کلید API؛ در محصول **Marzban / Sanaei** را به‌عنوان محل ساخت انتخاب کن.

**تست API:**

```bash
curl -s -H "Authorization: Bearer USK-API-کلید-شما" \
  "http://IP_VPS:8082/api/v1.php?action=panels"
```

---

## اختیاری: Node — VPS دوم (Pro)

**Xray روی VPS دوم**؛ پنل روی Hub می‌ماند. Hub با **SSH** وصل می‌شود (یک **رمز ثبت** مشترک از پنل — نه توکن جدا برای هر Node).

| محل | مسیر |
|-----|------|
| منو | **Node / سرور دوم** (بعد از پنل‌ها / سرور) |
| URL مستقیم | `http://IP:8082/admin/index.php?page=nodes` |

**پیش‌نیاز Hub:**

```bash
sudo apt install -y sshpass
sudo bash /var/www/unlimitsky/scripts/panel-self-update.sh
```

**روی VPS Node** (placeholderها را عوض کن؛ رمز ثبت از **پنل → Node**):

```bash
curl -fsSL http://IP_HUB:8082/bin/install-node.sh | sudo bash -s -- \
  --hub-ip IP_HUB --hub-port 8082 \
  --register-secret 'SECRET_FROM_PANEL' \
  --ssh-user root --ssh-pass 'NODE_SSH_PASSWORD' \
  --name node-1 --connect-host NODE_PUBLIC_IP_OR_DOMAIN
```

روش تعاملی: `curl -fsSL ... -o install-node.sh` سپس `sudo bash install-node.sh`.

بعد **تست SSH** و ساخت Xray با **سرور ساخت کانفیگ → Node**.

| مشکل | راه‌حل |
|------|--------|
| منوی Node نیست | `panel-self-update.sh` · Pro · refresh مرورگر |
| تست SSH fail | پسورد SSH · پورت 22 Hub→Node · `sshpass` |
| ساخت کانفیگ fail | روی Node: `sudo bash /opt/unlimitsky-node/bin/repair-xray.sh` |

---

## عیب‌یابی

### نصب کامل نشد؟

خطا معمولاً در `[*] Running CLI install (database + admin)...` دیده می‌شود.

**مرحله ۱ — آخرین نسخه**

در لاگ باید commit جدید باشد (مثلاً `fba58e7` یا بالاتر):

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'رمز-شما' --open-firewall
```

یا اگر قبلاً نصب کردی:

```bash
sudo bash /var/www/unlimitsky/scripts/panel-self-update.sh
```

**مرحله ۲ — پاک کردن lock نصب** (فقط اگر نصب موفق نشده):

```bash
sudo rm -f /var/www/unlimitsky/install/unlimitsky.install
```

بعد دوباره `curl install.sh` را اجرا کن.

**مرحله ۳ — دیتابیس نیمه‌کاره**

```bash
sudo mysql -e "DROP DATABASE IF EXISTS usk_client;"
sudo rm -f /var/www/unlimitsky/install/unlimitsky.install
# بعد curl install دوباره
```

**مرحله ۴ — فقط repair اسکیما** (`config.php` پر است، جداول ناقص):

```bash
sudo php /var/www/unlimitsky/install/repair-schema.php
```

| پیام خطا | علت | راه‌حل |
|----------|-----|--------|
| `Duplicate column name 'connections'` | نسخه قدیمی یا نصب نیمه‌کاره | به‌روزرسانی + مراحل ۲–۳ |
| `Undefined constant "USK_ROOT"` | نسخه قبل از fix لایسنس | `panel-self-update.sh` یا curl install |
| `Database setup failed: ...` | MySQL / دسترسی | `systemctl status mysql` · `/root/unlimitsky-client.credentials` |
| HTTP 500 در نصب | host اشتباه DB | host باید `localhost` باشد |

**بررسی نصب موفق:**

```bash
sudo cat /root/unlimitsky-client.credentials
ls -la /var/www/unlimitsky/install/unlimitsky.install
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8082/admin/login.php
```

آخرین دستور باید `200` برگرداند.

---

### مشکلات پنل و VPN

| مشکل | راه‌حل |
|------|--------|
| صفحه باز نمی‌شود | پورت 8082 در ufw + پنل ابری |
| نصب پروتکل fail | `/etc/sudoers.d/unlimitsky` |
| ساخت کانفیگ / ووکامرس permission | خط `add-user-*.sh` در sudoers — [امنیت](#امنیت) |
| مصرف همیشه ۰ GB | یک‌بار **بروزرسانی مصرف همه** · لاگ cron · `xray-fix-stats-api.sh`، `openvpn-fix-status.sh` |
| ووکامرس کانفیگ نمی‌دهد | تست پنل در WP · وضعیت Completed · کلید API |
| پلن دوم نمی‌سازم | **Pro** فعال کن |
| لیست پنل خارجی خالی | Marzban/Sanaei + Pro + کلید API |
| `panels_pro_required` | Pro روی VPS فعال نیست |
| خطای cURL پلاگین | cURL در PHP هاست |

---

## امنیت

| لایه | محافظت |
|------|--------|
| MySQL | فقط `localhost` · رمز تصادفی |
| nginx | مسدود: `/sql/`، `/admin/data/`، `config.php` |
| ورود admin | ۵ تلاش ناموفق → قفل ۱۵ دقیقه |
| ویزارد نصب | بعد از نصب غیرفعال (`unlimitsky.install`) |

**توصیه:** HTTPS · تغییر رمز `admin` در اولین ورود.

**sudo برای www-data** (کپی دستی بدون اسکریپت نصب):

```bash
sudo visudo -f /etc/sudoers.d/unlimitsky
```

```
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/install-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/add-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/disable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/enable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/remove-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/collect-usage-stats.sh
```

```bash
sudo chmod 440 /etc/sudoers.d/unlimitsky
```

---

## ساختار پروژه

```
unlimitsky-client/
├── admin/              پنل وب
├── api/                REST API (ووکامرس)
├── bin/                اسکریپت پروتکل و کاربر
├── cron/               sync مصرف، لایسنس
├── install/            نصب CLI و وب
├── scripts/            install.sh، panel-self-update.sh
├── sql/                اسکیما دیتابیس
├── wordpress-plugin/   پلاگین ووکامرس
└── install-ubuntu.sh   نصب اوبونتو
```

مسیر اجرای پنل بعد از نصب: **`/var/www/unlimitsky`**

---

## پشتیبانی

- **لایسنس Pro** → فروشنده پنل
- **نصب / VPN** → [عیب‌یابی](#عیب‌یابی)
- **راهنمای فروشنده** → [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)
