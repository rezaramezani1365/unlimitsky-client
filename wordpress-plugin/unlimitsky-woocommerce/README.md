# UnlimitSky VPN — WooCommerce Plugin

> **فارسی:** بخش پایین همین فایل | **Full panel guide:** [../../README.md](../../README.md) (EN) · [../../README.fa.md](../../README.fa.md) (FA)

WooCommerce plugin for automatic VPN config delivery via **UnlimitSky VPS API** (native protocols) and **Marzban / Sanaei (3x-ui)** (VLESS/VMess only).

---

## Install

1. Copy `unlimitsky-woocommerce` to `wp-content/plugins/`
2. **Plugins → UnlimitSky VPN - WooCommerce** → Activate
3. **WooCommerce** must be installed
4. **Settings → Permalinks → Save** (once)

---

## Marzban / Sanaei — quick setup (read full guide on client README)

### On UnlimitSky VPS (Pro required)

1. **Panel → Pro License** → activate `USK-...`
2. **Panel → Panels / Servers** → add Marzban or Sanaei → **Save & test**
3. **Panel → API Keys** → create key

See [Marzban & Sanaei setup guide](../../README.md#marzban--sanaei-3x-ui--setup-guide) for every field (inbounds, link template, etc.).

### On WordPress (recommended — Method A)

1. **UnlimitSky → Panels** → type **UnlimitSky (native)** → API URL + API key → Test
2. **Products → VPN product:**
   - Check **VPN product**
   - Connection: UnlimitSky panel
   - **Config target:** **Marzban / Sanaei panel (VLESS/VMess — Xray)**
   - Select panel from VPS list
   - Volume (GB), duration (days), price
3. Customer pays → VLESS/VMess link in order, email, **My Account → VPN Services**

### Alternative — direct Marzban/Sanaei in WordPress (Method B)

Add panel type **Marzban** or **Sanaei** in **UnlimitSky → Panels** with panel URL + credentials. Assign that panel to the product directly.

---

## Native protocols (WireGuard, OpenVPN, Xray Reality, Amnezia, L2TP)

1. Install protocol on VPS: **Panel → Protocols**
2. **Panel → API Keys** on VPS
3. WordPress: UnlimitSky panel type + API key
4. Product: **Config target = Native protocol** → choose protocol

---

## Flow

```
Customer pays → WooCommerce hook → UnlimitSky API or Marzban/Sanaei API → config link delivered
```

---

## Requirements

- PHP 7.4+, WordPress 5.8+, WooCommerce 5.0+, cURL
- WordPress host can reach UnlimitSky API URL
- For Method B: WordPress host can reach Marzban/Sanaei panel URL

---

# پلاگین ووکامرس UnlimitSky — فارسی

## نصب

1. پوشه `unlimitsky-woocommerce` را در `wp-content/plugins/` کپی کن
2. افزونه را فعال کن؛ ووکامرس باید نصب باشد
3. **تنظیمات → پیوندهای یکتا → ذخیره** (یک بار)

---

## Marzban / Sanaei — راه‌اندازی سریع

**راهنمای کامل:** [README.fa.md](../../README.fa.md#راهنمای-اتصال-پنل-marzban-و-sanaei-3x-ui)

### روی VPS UnlimitSky (نیاز به Pro)

1. **پنل → لایسنس Pro**
2. **پنل → پنل‌ها / سرور** → Marzban یا Sanaei → ذخیره و تست
3. **پنل → کلید API**

### روی وردپرس (روش الف — پیشنهادی)

1. **UnlimitSky → پنل‌ها** → نوع **UnlimitSky (native)** + API + تست
2. **محصول VPN:**
   - محصول VPN ✓
   - محل ساخت: **پنل Marzban / Sanaei**
   - انتخاب پنل از لیست VPS
   - حجم، مدت، قیمت
3. بعد از پرداخت → لینک VLESS/VMess در سفارش و ایمیل

### روش ب — Marzban/Sanaei مستقیم در وردپرس

در **UnlimitSky → پنل‌ها** نوع Marzban یا Sanaei را با URL و یوزر/رمز اضافه کن و در محصول همان پنل را انتخاب کن.

---

## پروتکل native

WireGuard، OpenVPN، Xray Reality، Amnezia، L2TP — از **پنل → پروتکل‌ها** روی VPS + **کلید API** + محصول با حالت **پروتکل native**.

---

## نکته مهم

پنل‌های **Marzban** و **Sanaei** فقط **VLESS و VMess (Xray)** هستند. برای WireGuard/OpenVPN/Amnezia از پروتکل native استفاده کن.
