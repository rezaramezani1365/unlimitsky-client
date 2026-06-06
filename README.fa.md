# unlimitsky — پنل فروش VPN (Client)

[![GitHub](https://img.shields.io/badge/GitHub-unlimitsky--client-blue?logo=github)](https://github.com/rezaramezani1365/unlimitsky-client)

> **English:** [README.md](README.md)

پنل مدیریتی برای **فروشندگان VPN** — روی VPS اوبونتو خودت نصب می‌کنی، پلن می‌سازی و از طریق **فروشگاه ووکامرس** به مشتریانت می‌فروشی.

**ریپازیتوری:** [github.com/rezaramezani1365/unlimitsky-client](https://github.com/rezaramezani1365/unlimitsky-client)

### نصب روی اوبونتو (یک دستور)

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall
```

بعد `http://YOUR_SERVER_IP:8082/admin/login.php` — ورود: **admin** / **Pass123**.  
راهنمای کامل: [راهنمای نصب](#راهنمای-نصب--از-صفر-تا-صد) پایین صفحه.

```
شما (فروشنده VPN)
    ├── VPS اوبونتو  →  پنل unlimitsky (این پروژه)
    └── هاست وردپرس  →  ووکامرس + پلاگین unlimitsky
                              ↓
                    مشتری پرداخت می‌کند → کانفیگ خودکار تحویل
```

---

## این پنل چه کار می‌کند؟

### پروتکل‌های داخلی روی VPS شما (ویژگی اصلی)

unlimitsky **پروتکل‌های VPN را مستقیم روی VPS اوبونتو شما نصب و اجرا می‌کند** — بدون نیاز به پنل شخص ثالث:

| پروتکل | کاربرد |
|--------|--------|
| **WireGuard** | VPN سریع برای موبایل و دسکتاپ |
| **OpenVPN** | سازگاری با اکثر دستگاه‌ها |
| **Xray (VLESS/VMess)** | مسیریابی پیشرفته، پورت 443 |
| **L2TP/IPsec** | روتر و VPN داخلی ویندوز |

از **پنل → پروتکل‌ها** با یک کلیک نصب کن. اسکریپت کانفیگ سرور، فایروال و سرویس systemd را خودش انجام می‌دهد.

**محدودیت‌ها:** cron اکانت تمام‌شده را **غیرفعال** می‌کند (اتصال قطع). رکورد در **پنل → سرویس‌ها** می‌ماند — تمدید یا حذف دستی.

**ساخت دستی:** **پنل → ساخت کانفیگ** — حجم و روز را مستقیم وارد کن (بدون نیاز به پلن).

**QR وایرگارد:** بعد از خرید، QR Code در ایمیل و صفحه سفارش نمایش داده می‌شود.

### اختیاری: Marzban / Sanaei (3x-ui)

اگر از قبل **Marzban** یا **Sanaei** داری، می‌توانی به‌عنوان **روش جایگزین** وصل کنی — برای **فروش خودکار ووکامرس** (VLESS/VMess). **راهنمای اتصال:** [Marzban و Sanaei](#راهنمای-اتصال-پنل-marzban-و-sanaei-3x-ui) · نیاز به **Pro**.

### سایر امکانات

- ساخت **پلن فروش** (حجم، مدت، قیمت)
- **پلاگین ووکامرس** — فروش کانفیگ به مشتری (تحویل خودکار Marzban/Sanaei الان فعال است)
- پنل فارسی/انگلیسی، تم روشن/تاریک

---

## داده‌ها کجا ذخیره می‌شوند؟

**همه‌چیز روی سرور خودت** — هیچ داده‌ای به سرور شخص ثالث فرستاده نمی‌شود (به‌جز فعال‌سازی لایسنس Pro در صورت استفاده).

| داده | محل |
|------|-----|
| کاربران، پلن‌ها، سفارش‌ها | **MySQL** روی VPS |
| تنظیمات پنل، لایسنس | فایل‌های `admin/data/` روی VPS |
| وضعیت پروتکل‌ها | `data/protocols/` روی VPS |
| سفارشات ووکامرس | دیتابیس **وردپرس** روی هاست فروشگاه |

---

## مهاجرت VPS (بکاپ و انتقال)

از **پنل → بکاپ و مهاجرت** می‌توانی داده‌های پنل را export/import کنی (پلن‌ها، سرویس‌ها، تنظیمات پروتکل، DNS، یوزر ادمین، کلید API).

### مراحل مهاجرت

1. **سرور قدیم** → بکاپ و مهاجرت → دانلود فایل `.uskbackup`
2. **سرور جدید** → نصب unlimitsky (اسکریپت install)
3. **سرور جدید** → بکاپ و مهاجرت → Import فایل
4. **پنل → پروتکل‌ها** → نصب مجدد پروتکل‌هایی که داشتی (v1 خودکار VPN را روی سیستم‌عامل restore نمی‌کند)
5. در صورت نیاز: **کلید API** جدید برای ووکامرس

### اشتراک Pro بعد از مهاجرت

لایسنس Pro به **IP VPS** و **شناسه یکتای همان سرور** (`instance_id`) وابسته است. با عوض شدن IP یا VPS:

| مورد | بعد از import بکاپ |
|------|---------------------|
| کلید Pro | **از بین نمی‌رود** — همان کلید قبلی |
| وضعیت Pro در پنل | موقتاً **Free** (کش لایسنس import نمی‌شود) |
| Marzban/Sanaei، پلن‌های اضافه | تا فعال‌سازی مجدد Pro در دسترس نیست |

**کار تو (فروشنده VPN):**

1. بعد از import، اگر Pro داشته باشی به **لایسنس Pro** هدایت می‌شوی (کلید از بکاپ پیش‌پر می‌شود).
2. **فعال‌سازی** را بزن.
3. اگر خطای IP یا instance دیدی، با **ارائه‌دهنده لایسنس Pro** تماس بگیر تا VPS جدید را روی همان کلید تأیید کند.
4. دوباره **فعال‌سازی** را بزن.

> v1 بکاپ شامل `/var/lib/unlimitsky` (کلید WireGuard/Xray روی سیستم) **نیست** — فقط داده‌های پنل. برای VPN زنده روی سرور جدید پروتکل‌ها را دوباره نصب کن.

---

## پروتکل‌ها روی VPS شما

از **پنل → پروتکل‌ها**، اسکریپت نصب هر پروتکل را **روی همین سرور** راه می‌اندازد:

| پروتکل | پورت پیش‌فرض | مناسب برای |
|--------|--------------|------------|
| **WireGuard** | 51820 | موبایل و دسکتاپ — سریع و سبک |
| **OpenVPN** | 1194 | سازگاری بالا با همه دستگاه‌ها |
| **Xray (VLESS/VMess)** | 443 | عبور از فیلترینگ، پشت CDN |
| **L2TP/IPsec** | 1701 | روتر و ویندوز بدون نرم‌افزار اضافه |

**الان چه کار می‌کند:** **نصب کامل سرور** + **ساخت کانفیگ per-customer** (ادمین + ووکامرس از طریق API).

---

## پروتکل native در مقابل Marzban / Sanaei

| | پروتکل native (داخلی) | Marzban / Sanaei (اختیاری) |
|---|------------------------|----------------------------|
| نصب توسط | unlimitsky روی VPS شما | پنل جدا (شاید از قبل داشته باشید) |
| راه‌اندازی سرور | ✅ پنل → پروتکل‌ها | خودتان Marzban/Sanaei را مدیریت می‌کنید |
| ساخت دستی کانفیگ در پنل | ✅ پنل → ساخت کانفیگ | ✅ پنل → ساخت کانفیگ |
| تحویل خودکار ووکامرس | ✅ پنل → کلید API + پلاگین | ✅ الان فعال است |

**پیشنهاد:** **WireGuard** را از **پنل → پروتکل‌ها** نصب کن، **کلید API** بساز، در ووکامرس پنل نوع **unlimitsky (native)** اضافه کن.

---

## نسخه رایگان (Free) vs Pro

| امکان | Free | Pro |
|-------|------|-----|
| نصب پروتکل‌ها | ✅ همه | ✅ همه |
| تعداد پلن فروش | **۱ پلن** | نامحدود (یا طبق لایسنس) |
| پنل ادمین | ✅ | ✅ |
| پلاگین ووکامرس | ✅ | ✅ |
| **پنل Marzban / Sanaei** | — | ✅ **پنل → پنل‌ها / سرور** |
| فعال‌سازی Pro | — | کلید `USK-...` از فروشنده پنل |

**فعال‌سازی Pro:** بعد از نصب → **پنل → لایسنس Pro** → کلید `USK-XXXX-...` را وارد کن.

---

## پیش‌نیازها

### VPS (پنل VPN)
- Ubuntu 20.04 / 22.04 / 24.04
- حداقل 1 GB RAM (2 GB پیشنهادی)
- دسترسی root (`sudo`)
- پورت **8082** (یا دلخواه) باز در فایروال

### هاست فروشگاه (ووکامرس)
- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ با cURL
- SSL (HTTPS) پیشنهادی

---

# راهنمای نصب — از صفر تا صد

## شروع سریع — یک دستور

روی **VPS اوبونتو خام** (۲۲.۰۴ یا ۲۴.۰۴) با SSH وصل شو و اجرا کن:

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall
```

اسکریپت خودش **nginx، MySQL، PHP** را نصب می‌کند، دیتابیس و اکانت admin را می‌سازد و فایروال را باز می‌کند — **هیچ کار دستی لازم نیست**.

**بعد از اتمام نصب:**

| | |
|---|---|
| **پنل** | `http://YOUR_SERVER_IP:8082/admin/login.php` |
| **نام کاربری** | `admin` |
| **رمز** | `Pass123` (یا همان چیزی که در `--admin-pass` دادی) |
| **ذخیره روی سرور** | `sudo cat /root/unlimitsky-client.credentials` |

به‌جای `Pass123` رمز دلخواه بگذار (حداقل ۶ کاراکتر). اگر `--admin-pass` ندهی، ورود `admin` / `admin` است و **در اولین ورود باید رمز را عوض کنی**.

| فلگ | معنی |
|-----|------|
| `--port 8082` | پورت وب پنل |
| `--admin-pass 'Pass123'` | رمز مدیر |
| `--open-firewall` | باز کردن پورت در ufw (اگر فعال باشد) |

### اختیاری — لایسنس Pro (از vendor)

اگر **لایسنس Pro** از صاحب پل unlimitsky داری، این فلگ‌ها را اضافه کن:

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall \
  --license-url 'https://license.yourdomain.com/api/v1.php' \
  --license-token 'SECRET'
```

دیتابیس MySQL با **رمز تصادفی** فقط روی `localhost` ساخته می‌شود — از اینترنت در دسترس نیست.

### یا clone + نصب

```bash
sudo git clone https://github.com/rezaramezani1365/unlimitsky-client.git /opt/unlimitsky
cd /opt/unlimitsky
sudo bash install-ubuntu.sh --auto --port 8082 --admin-pass 'Pass123' --open-firewall
```

---

## مرحله ۱ — نصب پنل روی VPS

### روش A: نصب خودکار (پیشنهادی)

همان [شروع سریع](#شروع-سریع--یک-دستور) بالا.

| گزینه | توضیح |
|-------|--------|
| `--port 8082` | پورت وب |
| `--admin-pass '...'` | رمز دلخواه (بدون آن = admin/admin + تغییر اجباری) |
| `--open-firewall` | باز کردن پورت در ufw |

### روش B: نصب از مرورگر

```bash
sudo git clone https://github.com/rezaramezani1365/unlimitsky-client.git /opt/unlimitsky
cd /opt/unlimitsky
sudo bash install-ubuntu.sh --port 8082 --open-firewall
```

سپس: `http://YOUR_SERVER_IP:8082/install/index.php` → زبان → (اختیاری) رمز مدیر → نصب

**دیتابیس خودکار ساخته شده** — فیلد DB در فرم نیست.

---

## امنیت (خودکار)

| لایه | محافظت |
|------|--------|
| MySQL | فقط `localhost` + رمز تصادفی ۲۰ کاراکteri |
| nginx | مسدود: `/sql/`، `/admin/data/`، `config.php` |
| پنل ادمین | رمز در جدول `panel_admin` (دیتابیس) |
| ورود | ۵ تلاش ناموفق → قفل ۱۵ دقیقه‌ای |
| نصب | بعد از نصب، `/install/` غیرفعال می‌شود |

**توصیه:** HTTPS (Let's Encrypt) + تغییر رمز `admin` در اولین ورود.

---

## نصب phpMyAdmin روی اوبونتو

phpMyAdmin رابط وب برای مدیریت MySQL است — برای ساخت دیتابیس، بررسی جداول و عیب‌یابی مفید است.

### پیش‌نیازها

- Ubuntu 20.04 / 22.04 / 24.04 با دسترسی `sudo`
- MySQL یا MariaDB در حال اجرا (با `install-ubuntu.sh` خودکار نصب می‌شود)
- پسوندهای PHP: `php-mbstring`, `php-zip`, `php-gd`, `php-json`, `php-curl`

```bash
sudo apt update
```

### نصب وب‌سرور و PHP (در صورت نیاز)

**Apache:**

```bash
sudo apt install apache2 mysql-server php php-mbstring php-zip php-gd php-json php-curl -y
```

**Nginx (LEMP — همان پشته unlimitsky):**

```bash
sudo apt install nginx mysql-server php-fpm php-mysql php-mbstring php-zip php-gd php-json php-curl -y
```

### نصب phpMyAdmin

#### گزینه A: Apache

```bash
sudo apt install phpmyadmin -y
```

در حین نصب:
- وب‌سرور: با Space گزینه **apache2** را انتخاب کن → Ok
- dbconfig-common: **Yes** → رمز برای کاربر phpmyadmin در MySQL تعیین کن

```bash
sudo phpenmod mbstring
sudo systemctl restart apache2
```

#### گزینه B: Nginx (پیشنهادی اگر پنل unlimitsky روی Nginx است)

```bash
sudo apt install phpmyadmin -y
```

در حین نصب:
- وب‌سرور: **None** (Nginx در لیست نیست) → Ok
- dbconfig-common: **Yes** → رمز phpmyadmin را تعیین کن

```bash
sudo ln -s /etc/phpmyadmin/apache.conf /etc/nginx/conf.d/phpmyadmin.conf
sudo nano /etc/nginx/conf.d/phpmyadmin.conf
```

محتوا را با این جایگزین کن (نسخه PHP را با `ls /var/run/php/` پیدا کن):

```nginx
location /phpmyadmin {
    root /usr/share/;
    index index.php index.html index.htm;
    location ~ ^/phpmyadmin/(.+\.php)$ {
        try_files $uri =404;
        root /usr/share/;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~* ^/phpmyadmin/(.+\.(jpg|jpeg|gif|css|png|js|ico|html|xml|txt))$ {
        root /usr/share/;
    }
}

location /phpMyAdmin {
    rewrite ^/* /phpmyadmin last;
}
```

```bash
sudo systemctl restart nginx
```

### امن‌سازی

```bash
sudo mysql -u root -p
```

```sql
CREATE USER 'pma_user'@'localhost' IDENTIFIED BY 'یک_رمز_قوی';
GRANT ALL PRIVILEGES ON *.* TO 'pma_user'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EXIT;
```

- از **HTTPS** (مثلاً Let's Encrypt) استفاده کن
- phpMyAdmin را فقط از IPهای مورد اعتماد در دسترس بگذار

### دسترسی

```
http://YOUR_SERVER_IP/phpmyadmin
```

با کاربر MySQL (مثلاً `root` یا `pma_user`) وارد شو.

### عیب‌یابی phpMyAdmin

| مشکل | راه‌حل |
|------|--------|
| `mbstring extension is missing` | `sudo phpenmod mbstring` و ریستارت وب‌سرور |
| Forbidden / 404 | مسیر nginx/apache و دسترسی فایل‌ها را بررسی کن |
| صفحه باز نمی‌شود | `sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw reload` |

---

### فایروال VPS

```bash
sudo ufw allow 8082/tcp
sudo ufw reload
```

در پنل هاست ابری (Hetzner، DigitalOcean و …) هم پورت را باز کن.

---

### sudo برای اسکریپت‌های VPN (الزامی)

پنل با کاربر **`www-data`** اجرا می‌شود، ولی نصب پروتکل و ساخت/غیرفعال‌کردن کاربر VPN نیاز به **root** دارد. بعد از قرار دادن فایل‌ها روی VPS، یک‌بار اجازه اجرای فقط همین اسکریپت‌ها را بده.

**اگر از `install-ubuntu.sh` یا نصب یک‌دستوری استفاده کردی:** معمولاً خودکار در `/etc/sudoers.d/unlimitsky` تنظیم می‌شود. بررسی:

```bash
sudo cat /etc/sudoers.d/unlimitsky
```

**اگر فایل‌ها را دستی کپی کردی** (بدون اسکریپت نصب)، یا نصب پروتکل / ساخت کانفیگ با خطای permission fail می‌شود، این خطوط را یک‌بار اضافه کن:

```bash
sudo visudo -f /etc/sudoers.d/unlimitsky
```

این‌ها را paste کن (اگر مسیر پنل `/var/www/unlimitsky` نیست عوض کن — مسیر دقیق در **پنل → پروتکل‌ها** هست):

```
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/install-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/add-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/disable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/enable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/remove-user-*.sh *
```

ذخیره و خروج. سپس:

```bash
sudo chmod 440 /etc/sudoers.d/unlimitsky
```

| اسکریپت | کاربرد |
|---------|--------|
| `install-*.sh` | **پنل → پروتکل‌ها** — نصب WireGuard، OpenVPN و … |
| `add-user-*.sh` | ساخت کانفیگ (ادمین، API ووکامرس، فروش دستی) |
| `disable-user-*.sh` | cron — قطع اتصال وقتی مدت تمام شد یا حجم پر شد |
| `enable-user-*.sh` | **سرویس‌ها → تمدید** — فعال‌سازی دوباره |
| `remove-user-*.sh` | **سرویس‌ها → حذف از سرور** — حذف دستی |

بدون `add-user-*.sh` ساخت کانفیگ از پنل یا ووکامرس کار نمی‌کند. بدون `disable-user-*.sh` اکانت منقضی تا حذف دستی وصل می‌ماند.

---

## مرحله ۲ — راه‌اندازی پنل ادمین

### ۱. نصب پروتکل‌ها (از همینجا شروع کن)

**پنل → پروتکل‌ها**

- اول **WireGuard** را نصب کن (ساده‌ترین)
- بعد OpenVPN، Xray یا L2TP در صورت نیاز
- هر کدام **روی همین VPS** اجرا می‌شود — اسکریپت پکیج، کانفیگ، پورت فایروال و سرویس را خودش انجام می‌دهد

این **روش اصلی** طراحی unlimitsky است: **VPS شما = سرور VPN شما**.

### ۲. ساخت پلن فروش

**پنل → پلن‌ها**

| فیلد | توضیح |
|------|--------|
| نام | مثلاً «پلن ۳۰ روزه ۵۰ گیگ» |
| حجم (GB) | سقف ترافیک |
| مدت (روز) | اعتبار سرویس |
| قیمت | برای نمایش — پرداخت واقعی در ووکامرس |

- **Free:** فقط ۱ پلن
- **Pro:** پلن نامحدود — **پنل → لایسنس Pro** → کلید `USK-...`

### ۳. کلید API برای ووکامرس (پروتکل native)

**پنل → کلید API**

- یک کلید API بساز (فقط یک‌بار نمایش داده می‌شود — کپی کن)
- **آدرس API** را یادداشت کن (مثلاً `http://IP:8082/api/v1.php`)
- در پلاگین وردپرس: پنل نوع **unlimitsky (native)** با آدرس API + کلید

### ۴. Marzban / Sanaei (اختیاری — نیاز به Pro)

**راهنمای کامل:** [راهنمای اتصال Marzban و Sanaei](#راهنمای-اتصال-پنل-marzban-و-sanaei-3x-ui) — **حتماً قبل از اتصال بخوانید.**

خلاصه: **پنل → لایسنس Pro** → **پنل → پنل‌ها / سرور** → افزودن Marzban یا Sanaei → تست اتصال.

---

## مرحله ۳ — نصب پلاگین ووکامرس

فروشگاه روی **هاست وردپرس** است (می‌تواند جدا از VPS باشد).

### ۱. آماده‌سازی وردپرس

- WordPress + WooCommerce نصب و فعال
- یک درگاه پرداخت (زرین‌پال، کارت به کارت و …) در ووکامرس

### ۲. آپلود پلاگین

از ریپازیتوری پوشه زیر را کپی کن:

```
wordpress-plugin/unlimitsky-woocommerce/
```

به:

```
wp-content/plugins/unlimitsky-woocommerce/
```

یا ZIP کن و از **افزونه‌ها → افزودن → آپلود** نصب کن.

### ۳. فعال‌سازی

- **افزونه‌ها → unlimitsky - WooCommerce** → فعال
- **تنظیمات → پیوندهای یکتا → ذخیره** (یک بار)

---

> اگر **فقط پروتکل native** (WireGuard، OpenVPN، Xray روی VPS) می‌فروشی، اتصال Marzban/Sanaei روی VPS لازم نیست — فقط کلید API کافی است.

---

## مرحله ۴ — اتصال ووکامرس به پنل VPN

### گزینه‌های تحویل خودکار

| روش | راه‌اندازی |
|-----|-----------|
| **پروتکل native** | پنل → کلید API → وردپرس: پنل unlimitsky |
| **Marzban / Sanaei** | وردپرس: افزودن پنل Marzban/Sanaei |

### الف. پروتکل native (پیشنهادی)

1. **پنل → کلید API** — کلید بساز، آدرس API را کپی کن
2. **وردپرس → unlimitsky → پنل‌ها** — نوع: **unlimitsky (native)**
   - آدرس API: `http://IP_VPS:8082`
   - کلید API: `USK-API-...`
   - پروتکل پیش‌فرض: WireGuard / OpenVPN / Xray / L2TP
3. **محصولات → محصول VPN** — پنل unlimitsky، حجم و مدت
4. مشتری پرداخت می‌کند → کانفیگ خودکار تحویل

### ب. Marzban / Sanaei (Pro + پنل خارجی)

**راهنمای گام‌به‌گام:** [راهنمای اتصال Marzban و Sanaei](#راهنمای-اتصال-پنل-marzban-و-sanaei-3x-ui).

چک‌لیست کوتاه:

1. **VPS:** لایسنس Pro → **پنل → پنل‌ها / سرور** → اتصال Marzban یا Sanaei → تست موفق  
2. **VPS:** **پنل → کلید API** → ساخت کلید  
3. **وردپرس:** پنل نوع **unlimitsky (native)** + آدرس API + کلید  
4. **محصول:** محل ساخت = **پنل Marzban / Sanaei** → انتخاب پنل از لیست VPS  
5. مشتری پرداخت می‌کند → لینک VLESS/VMess خودکار تحویل  

روش جایگزین: افزودن Marzban/Sanaei **مستقیم در وردپرس** (روش ب در راهنمای بالا).

---

## خلاصه معماری

```
┌──────────────────── VPS (Ubuntu) ────────────────────┐
│  unlimitsky Panel :8082                              │
│  ├── MySQL (پلن‌ها، کاربران، سفارش‌ها)               │
│  ├── ★ WireGuard / OpenVPN / Xray / L2TP (داخلی)   │
│  └── Marzban یا Sanaei (اختیاری — WC خودکار امروز)  │
└──────────────────────────────────────────────────────┘
                          ↑ API (Marzban/Sanaei فعلاً)
┌──────────────── WordPress Host ──────────────────────┐
│  WooCommerce + پلاگین unlimitsky                     │
│  └── خرید مشتری → تحویل خودکار کانفیگ              │
└──────────────────────────────────────────────────────┘
```

---

## عیب‌یابی

| مشکل | راه‌حل |
|------|--------|
| صفحه باز نمی‌شود | پورت 8082 در ufw + پنل ابری |
| HTTP 500 در نصب | DB host باید `localhost` باشد — نه اسم دیتابیس |
| `License server not configured` | فقط برای Pro — Free بدون لایسنس کار می‌کند |
| نمی‌توانم پلن دوم بسازم | Pro را در **پنل → لایسنس** فعال کن |
| نصب پروتکل fail | `/etc/sudoers.d/unlimitsky` را چک کن — بخش **sudo برای اسکریپت‌های VPN** |
| ساخت کانفیگ / ووکامرس (permission) | خط `add-user-*.sh` را در sudoers اضافه کن |
| ووکامرس کانفیگ نمی‌دهد | تست اتصال پنل در وردپرس + وضعیت سفارش Completed |
| لیست پنل خارجی در محصول خالی است | Marzban/Sanaei را روی VPS (Pro) وصل کن + کلید API معتبر |
| خطای API `panels_pro_required` | لایسنس Pro روی VPS فعال نیست |
| پلاگین خطای cURL | cURL را در PHP هاست فعال کن |

**بررسی نصب:**

```bash
sudo cat /root/unlimitsky-client.credentials
ls -la /var/www/unlimitsky/install/unlimitsky.install
```

---

## ساختار پوشه‌ها

```
unlimitsky-client/
├── admin/              پنل مدیریت وب
├── install/            ویزارد نصب + create-db.sh + finish-install.sh
├── bin/                اسکریپت نصب پروتکل‌ها
├── data/               وضعیت پروتکل‌ها
├── wordpress-plugin/   پلاگین ووکامرس
├── config.php          تنظیمات (بعد از نصب)
└── install-ubuntu.sh   نصب روی اوبونتو
```

فایل‌های اجرایی پنل بعد از نصب در `/var/www/unlimitsky` قرار می‌گیرند.

---

## پشتیبانی

- مشکل **لایسنس Pro** → با فروشنده پنل تماس بگیر
- مشکل **فنی نصب** → بخش عیب‌یابی بالا

راهنمای تکمیلی: [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)

---

# راهنمای اتصال پنل Marzban و Sanaei (3x-ui)

> **قبل از اتصال پنل خارجی این بخش را بخوانید.** Marzban و Sanaei فقط **VLESS / VMess (Xray)** پشتیبانی می‌کنند — نه WireGuard، OpenVPN یا Amnezia.

## پیش‌نیازها

| مورد | توضیح |
|------|--------|
| **لایسنس Pro** | اتصال Marzban/Sanaei در **پنل → پنل‌ها / سرور** فقط با **Pro** (`پنل → لایسنس Pro`) |
| **Marzban یا Sanaei** | از قبل روی VPS نصب و در دسترس باشد |
| **ووکامرس** | هاست وردپرس باید به API پنل unlimitsky (و در صورت نیاز URL پنل Marzban/Sanaei) دسترسی داشته باشد |

## پروتکل‌های پشتیبانی‌شده روی پنل خارجی

| پنل | پروتکل‌ها | توضیح |
|-----|-----------|--------|
| **Marzban** | VLESS, VMess | در فیلد پروتکل‌ها: `vless\|vmess\|` + تگ inbound |
| **Sanaei (3x-ui)** | VLESS, VMess | از inbound موجود در 3x-ui — لینک با قالب ساخته می‌شود |

پروتکل‌های native (WireGuard، OpenVPN، Xray Reality روی VPS، Amnezia، L2TP) از **پنل → پروتکل‌ها** ساخته می‌شوند، نه Marzban/Sanaei.

---

## بخش ۱ — اتصال پنل در ادمین unlimitsky (VPS)

1. **Pro را فعال کن:** **پنل → لایسنس Pro** → کلید `USK-...`  
2. **پنل → پنل‌ها / سرور** (منوی کناری)  
3. **پنل → راهنمای اتصال** را برای توضیح فیلدها بخوان  
4. افزودن پنل → **ذخیره و تست اتصال**

### Marzban

| فیلد | مثال | توضیح |
|------|------|--------|
| نام نمایشی | `Marzban اصلی` | در ادمین و ووکامرس نمایش داده می‌شود |
| نوع | `Marzban` | |
| آدرس پنل | `https://185.x.x.x:8000` | بدون `/` آخر؛ پورت باز باشد |
| نام کاربری | `admin` | ادمین Marzban |
| رمز عبور | `***` | |
| پروتکل‌ها | `vless\|vmess\|` | با `\|` جدا — `\|` آخر اختیاری |
| Flow | `flowon` | برای VLESS + Vision روی inboundهای سازگار |
| Inbounds | هر خط یک تگ | تگ‌های Marzban (مثلاً `VLESS TCP`) |

**بعد از ذخیره:** تست لاگین انجام می‌شود. وضعیت باید **active** شود.

**تست دستی:** **پنل → ساخت کانفیگ** → حالت **Marzban / Sanaei** → انتخاب پنل → ساخت کاربر تست → کپی لینک subscription.

### Sanaei (3x-ui)

| فیلد | مثال | توضیح |
|------|------|--------|
| نام نمایشی | `Sanaei اروپا` | |
| نوع | `Sanaei (3x-ui)` | |
| آدرس پنل | `http://185.x.x.x:2053` | پورت پیش‌فرض 3x-ui معمولاً 2053 |
| یوزر / رمز | ورود 3x-ui | |
| Inbound ID | `1` | شماره از **Inbounds** در 3x-ui |
| قالب لینک | پایین | برای ساخت لینک اشتراک |

**Placeholderهای قالب لینک:**

| Placeholder | معنی |
|-------------|------|
| `%s1` | UUID کلاینت |
| `%s2` | host:port (مثلاً `185.x.x.x:443`) |
| `%s3` | remark / نام |

مثال VLESS:

```
vless://%s1@%s2?encryption=none&security=tls&type=ws&host=example.com&path=/path#%s3
```

قالب دقیق را از inbound در 3x-ui (QR یا share link) بگیر و uuid/host/remark را با `%s1`, `%s2`, `%s3` جایگزین کن.

**Inbound در 3x-ui:** باید از قبل با VLESS یا VMess ساخته شده باشد — unlimitsky فقط **کلاینت** به آن inbound اضافه می‌کند.

---

## بخش ۲ — تحویل خودکار ووکامرس

دو روش. **روش الف پیشنهادی** است اگر Marzban/Sanaei را روی VPS unlimitsky تنظیم کرده‌ای.

### روش الف — پنل خارجی از طریق API unlimitsky (پیشنهادی)

پنل‌ها یک‌بار روی VPS مدیریت می‌شوند؛ در هر محصول مشخص می‌کنی کانفیگ کجا ساخته شود.

**روی VPS:**

1. **Pro** فعال  
2. Marzban/Sanaei متصل (**بخش ۱**)  
3. **پنل → کلید API** → ساخت کلید → کپی **آدرس API** + **کلید**

**روی وردپرس:**

1. **unlimitsky → پنل‌ها → افزودن**  
   - نوع: **unlimitsky (native)**  
   - آدرس API: `http://IP_VPS:8082`  
   - کلید API: `USK-API-...`  
   - **تست اتصال**

2. **محصولات → محصول VPN**  
   - تیک **محصول VPN**  
   - اتصال: پنل **unlimitsky**  
   - **محل ساخت کانفیگ:** **پنل Marzban / Sanaei (VLESS/VMess — Xray)**  
   - **پنل Marzban/Sanaei (روی VPS):** از لیست انتخاب کن  
   - حجم (GB)، مدت (روز)، قیمت  

3. مشتری پرداخت → پلاگین API را با `panel_code` صدا می‌زند → کاربر روی Marzban/Sanaei → لینک در سفارش، ایمیل، **حساب کاربری → سرویس‌های VPN**

```
پرداخت مشتری (ووکامرس)
        ↓
پلاگین → POST /api/v1.php?action=create-service  (panel_code)
        ↓
VPS unlimitsky → API Marzban یا addClient در Sanaei
        ↓
لینک subscription / VLESS به مشتری
```

### روش ب — Marzban/Sanaei مستقیم در وردپرس

اگر پنل VPN را **روی VPS unlimitsky ثبت نکرده‌ای** (روش قدیمی).

1. **unlimitsky → پنل‌ها** → نوع **Marzban** یا **Sanaei**  
2. URL، یوزر، رمز (+ Inbound ID و قالب لینک برای Sanaei)  
3. **تست اتصال**  
4. **محصول VPN** → همان پنل Marzban/Sanaei را انتخاب کن  
5. فقط **VLESS/VMess** — WireGuard/OpenVPN روی این پنل‌ها نیست  

> هاست وردپرس باید به URL پنل Marzban/Sanaei **دسترسی شبکه** داشته باشد.

---

## بخش ۳ — ساخت دستی کانفیگ (ادمین)

**پنل → ساخت کانفیگ**

1. پلن دستی یا از لیست  
2. حالت: **Marzban / Sanaei (پنل خارجی)** — نیاز به Pro  
3. انتخاب پنل متصل  
4. ثبت → لینک subscription نمایش داده می‌شود  

---

## عیب‌یابی — Marzban / Sanaei

| مشکل | راه‌حل |
|------|--------|
| منوی **پنل‌ها / سرور** نیست یا فرم غیرفعال | **لایسنس Pro** را فعال کن |
| تست Marzban ناموفق | URL، پورت، فایروال، یوزر/رمز ادمین |
| تست Sanaei ناموفق | URL 3x-ui؛ مسیر `cookie.txt` روی VPS قابل نوشتن باشد |
| ووکامرس: لیست پنل خارجی خالی | اول Marzban/Sanaei را روی VPS وصل کن؛ Pro + کلید API |
| خطای `panels_pro_required` | Pro روی VPS منقضی یا فعال نشده |
| Sanaei: کاربر ساخته شد ولی لینک خراب | **قالب لینک** و **Inbound ID** را در تنظیمات VPS اصلاح کن |
| Marzban: کاربر بدون subscription | **Inbounds** و پروتکل‌های `vless`/`vmess` را بررسی کن |
| پروتکل اشتباه | پنل خارجی فقط VLESS/VMess — برای VLESS Reality روی VPS از **Xray** در **پنل → پروتکل‌ها** استفاده کن |

**تست API (لیست پنل‌ها):**

```bash
curl -s -H "Authorization: Bearer USK-API-کلید-شما" \
  "http://IP_VPS:8082/api/v1.php?action=panels"
```

انتظار: `"ok": true` و آرایه `"panels"`.
