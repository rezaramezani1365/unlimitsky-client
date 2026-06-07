# unlimitsky — VPN Reseller Panel (Client)



[![GitHub](https://img.shields.io/badge/GitHub-unlimitsky--client-blue?logo=github)](https://github.com/rezaramezani1365/unlimitsky-client)



> **فارسی:** [README.fa.md](README.fa.md)

Admin panel for **VPN resellers** — install on your Ubuntu VPS, create plans, and sell to your customers via **WooCommerce**.



**Repository:** [github.com/rezaramezani1365/unlimitsky-client](https://github.com/rezaramezani1365/unlimitsky-client)

### Install on Ubuntu (one command)

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall
```

Then open `http://YOUR_SERVER_IP:8082/admin/login.php` — login: **admin** / **Pass123**.  
Full guide: [Installation](#installation-guide--zero-to-production) below.

```
You (VPN reseller)
    ├── Ubuntu VPS     →  unlimitsky Panel (this project)
    └── WordPress host →  WooCommerce + unlimitsky plugin
                                ↓
                  Customer pays → config delivered automatically
```

---



## What this panel does

### Built-in protocols on your VPS (main feature)

unlimitsky **installs and runs VPN protocols directly on your Ubuntu VPS** — no third-party panel required:

| Protocol | Role |
|----------|------|
| **WireGuard** | Fast VPN for mobile & desktop |
| **OpenVPN** | Wide device support |
| **Xray (VLESS/VMess)** | Advanced routing, port 443 |
| **L2TP/IPsec** | Routers & Windows built-in VPN |

Install from **Panel → Protocols** with one click. Server config, firewall, and systemd service are handled by the script.

**Limits & usage:** By default, click **Panel → Services → Update all usage** to refresh traffic meters. Optional: [enable cron](#automatic-usage-sync-optional-cron) so usage syncs automatically. Cron also **disables** expired/over-limit accounts (no connection). Records stay in **Panel → Services** — extend to renew or delete manually.

**Manual create:** **Panel → Create config** — enter volume/days directly (no plan required).

**WireGuard QR:** after purchase, customers receive a scannable QR code in email and order page.

### Optional: Marzban / Sanaei (3x-ui)

If you already use **Marzban** or **Sanaei**, you can connect them as an **alternative** — useful for **WooCommerce auto-delivery** (VLESS/VMess). **Setup guide:** [Marzban & Sanaei](#marzban--sanaei-3x-ui--setup-guide) · Requires **Pro**.

### Also included

- Create **sales plans** (volume, duration, price)
- **WooCommerce plugin** — sell configs to your customers (Marzban/Sanaei auto-delivery available now)
- Persian/English admin UI, light/dark theme

---

## Where is data stored?

**Everything on your own servers** — no data is sent to third parties (except Pro license activation if you use it).

| Data | Location |
|------|----------|
| Users, plans, internal orders | **MySQL** on your VPS |
| Panel settings, license | `admin/data/` on VPS |
| Protocol status | `data/protocols/` on VPS |
| WooCommerce orders | **WordPress** database on shop host |

---

## VPS migration (backup & restore)

Use **Panel → Backup & migration** to export/import panel data (plans, services, protocol settings, DNS, admin user, API keys).

### Migration steps

1. **Old server** → Backup & migration → download `.uskbackup`
2. **New server** → install unlimitsky (install script)
3. **New server** → Backup & migration → import file
4. **Panel → Protocols** → reinstall protocols you had (v1 does not restore live VPN on the OS)
5. If needed: new **API key** for WooCommerce

### Pro subscription after migration

Pro is bound to **VPS IP** and **server instance ID**. When IP or VPS changes:

| Item | After backup import |
|------|---------------------|
| Pro key | **Not lost** — same key as before |
| Pro status in panel | Temporarily **Free** (license cache is not imported) |
| Marzban/Sanaei, extra plans | Unavailable until Pro is re-activated |

**Your steps (VPN reseller):**

1. After import, if you had Pro you are redirected to **Pro License** (key pre-filled from backup).
2. Click **Activate**.
3. If you see an IP or instance error, contact your **Pro license provider** so they can approve the new VPS on the same key.
4. Click **Activate** again.

> v1 backup does **not** include `/var/lib/unlimitsky` (WireGuard/Xray keys on the OS) — panel data only. Reinstall protocols on the new server for live VPN.

---

## Protocols on your VPS

From **Panel → Protocols**, the installer script sets up each protocol **on this server**:

| Protocol | Default port | Best for |
|----------|--------------|----------|
| **WireGuard** | 51820 | Mobile & desktop — fast and lightweight |
| **OpenVPN** | 1194 | Maximum device compatibility |
| **Xray (VLESS/VMess)** | 443 | Filtering bypass, behind CDN |
| **L2TP/IPsec** | 1701 | Routers and Windows without extra apps |

**What works now:** full **server installation** + **per-customer config creation** (admin + WooCommerce via API).

Volume metering (WireGuard, OpenVPN, Xray, Amnezia) is available from **Panel → Services**. See [Automatic usage sync (optional cron)](#automatic-usage-sync-optional-cron) if you do not want to click **Update all usage** manually.

---

## Native protocols vs Marzban / Sanaei

| | Native protocols (built-in) | Marzban / Sanaei (optional) |
|---|---------------------------|----------------------------|
| Installed by | unlimitsky on your VPS | Separate panel (you may already have it) |
| Server setup | ✅ Panel → Protocols | You manage Marzban/Sanaei yourself |
| Manual config in admin | ✅ Panel → Create config | ✅ Panel → Create config |
| WooCommerce auto-delivery | ✅ Panel → API Keys + plugin | ✅ Available now |

**Recommendation:** install **WireGuard** from **Panel → Protocols**, create an **API key**, connect WooCommerce with panel type **unlimitsky (native)**.

---

## Free vs Pro

| Feature | Free | Pro |
|---------|------|-----|
| Install protocols | ✅ All | ✅ All |
| Sales plans | **1 plan** | Unlimited (per license) |
| Admin panel | ✅ | ✅ |
| WooCommerce plugin | ✅ | ✅ |
| **Marzban / Sanaei panels** | — | ✅ **Panel → Panels / Servers** |
| Pro activation | — | `USK-...` key from your panel provider |

**Activate Pro:** after install → **Panel → Pro License** → enter your `USK-XXXX-...` key.

---

## Requirements

### VPS (VPN panel)
- Ubuntu 20.04 / 22.04 / 24.04
- Minimum 1 GB RAM (2 GB recommended)
- Root access (`sudo`)
- Port **8082** (or custom) open in firewall

### Shop host (WooCommerce)
- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+ with cURL
- SSL (HTTPS) recommended

---

# Installation guide — zero to production

## Quick start — one command

Works on a **fresh Ubuntu VPS** (22.04 or 24.04). SSH in as root and run:

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall
```

The script installs **nginx, MySQL, PHP**, creates the database, sets up the admin account, and opens the firewall — no manual steps.

**When it finishes**, open:

| | |
|---|---|
| **Panel** | `http://YOUR_SERVER_IP:8082/admin/login.php` |
| **Username** | `admin` |
| **Password** | `Pass123` (or whatever you passed to `--admin-pass`) |
| **Saved on server** | `sudo cat /root/unlimitsky-client.credentials` |

Replace `Pass123` with your own password (at least 6 characters). Omit `--admin-pass` to use `admin` / `admin` and **change password on first login**.

| Flag | Meaning |
|------|---------|
| `--port 8082` | Panel web port |
| `--admin-pass 'Pass123'` | Admin password |
| `--open-firewall` | Allow the port in ufw if ufw is active |

### Optional — Pro license (from vendor)

If you have a **Pro license** from the unlimitsky platform owner, add:

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall \
  --license-url 'https://license.yourdomain.com/api/v1.php' \
  --license-token 'SECRET'
```

MySQL is created with a **random password** on `localhost` only — not reachable from the internet.

### Or clone + install

```bash
sudo git clone https://github.com/rezaramezani1365/unlimitsky-client.git /opt/unlimitsky
cd /opt/unlimitsky
sudo bash install-ubuntu.sh --auto --port 8082 --admin-pass 'Pass123' --open-firewall
```

---

## Step 1 — Install panel on VPS

### Method A: Automatic install (recommended)

Same as [Quick start](#quick-start--one-command) above.

| Option | Description |
|--------|-------------|
| `--port 8082` | Web port |
| `--admin-pass '...'` | Custom password (omit for admin/admin + forced change) |
| `--open-firewall` | Open port in ufw |

### Method B: Browser install

```bash
sudo git clone https://github.com/rezaramezani1365/unlimitsky-client.git /opt/unlimitsky
cd /opt/unlimitsky
sudo bash install-ubuntu.sh --port 8082 --open-firewall
```

Then: `http://YOUR_SERVER_IP:8082/install/index.php` → language → (optional) admin password → install

**Database is auto-provisioned** — no DB fields in the form.

---

## Security (built-in)

| Layer | Protection |
|-------|------------|
| MySQL | `localhost` only + random 20-char password |
| nginx | Blocks `/sql/`, `/admin/data/`, `config.php` |
| Admin auth | Stored in `panel_admin` table (database) |
| Login | 5 failed attempts → 15-minute lockout |
| Install | `/install/` disabled after successful setup |

**Recommended:** HTTPS (Let's Encrypt) + change default `admin` password on first login.

---

## Install phpMyAdmin on Ubuntu

phpMyAdmin is a web interface for managing MySQL — useful for creating databases, inspecting tables, and troubleshooting.

### Prerequisites

- Ubuntu 20.04 / 22.04 / 24.04 with `sudo` access
- MySQL or MariaDB running (installed automatically by `install-ubuntu.sh`)
- PHP extensions: `php-mbstring`, `php-zip`, `php-gd`, `php-json`, `php-curl`

```bash
sudo apt update
```

### Install web server and PHP (if needed)

**Apache:**

```bash
sudo apt install apache2 mysql-server php php-mbstring php-zip php-gd php-json php-curl -y
```

**Nginx (LEMP — same stack as unlimitsky):**

```bash
sudo apt install nginx mysql-server php-fpm php-mysql php-mbstring php-zip php-gd php-json php-curl -y
```

### Install phpMyAdmin

#### Option A: Apache

```bash
sudo apt install phpmyadmin -y
```

During setup:
- Web server: select **apache2** with Space → Ok
- dbconfig-common: **Yes** → set password for phpmyadmin MySQL user

```bash
sudo phpenmod mbstring
sudo systemctl restart apache2
```

#### Option B: Nginx (recommended if unlimitsky panel runs on Nginx)

```bash
sudo apt install phpmyadmin -y
```

During setup:
- Web server: **None** (Nginx is not in the list) → Ok
- dbconfig-common: **Yes** → set phpmyadmin password

```bash
sudo ln -s /etc/phpmyadmin/apache.conf /etc/nginx/conf.d/phpmyadmin.conf
sudo nano /etc/nginx/conf.d/phpmyadmin.conf
```

Replace contents with this (find your PHP version with `ls /var/run/php/`):

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

### Security

```bash
sudo mysql -u root -p
```

```sql
CREATE USER 'pma_user'@'localhost' IDENTIFIED BY 'a_strong_password';
GRANT ALL PRIVILEGES ON *.* TO 'pma_user'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EXIT;
```

- Use **HTTPS** (e.g. Let's Encrypt)
- Restrict phpMyAdmin access to trusted IPs when possible

### Access

```
http://YOUR_SERVER_IP/phpmyadmin
```

Log in with a MySQL user (e.g. `root` or `pma_user`).

### phpMyAdmin troubleshooting

| Problem | Fix |
|---------|-----|
| `mbstring extension is missing` | `sudo phpenmod mbstring` and restart web server |
| Forbidden / 404 | Check nginx/apache paths and file permissions |
| Page won't load | `sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw reload` |

---

### VPS firewall

```bash
sudo ufw allow 8082/tcp
sudo ufw reload
```

Also open the port in your cloud panel (Hetzner, DigitalOcean, etc.).

---

### Sudo for VPN scripts (required)

The panel runs as **`www-data`**, but installing protocols and creating/disabling VPN users needs **root**. After files are on the VPS, allow the panel to run only its own scripts via sudo.

**If you used `install-ubuntu.sh` or the one-line installer:** this is usually done automatically in `/etc/sudoers.d/unlimitsky`. Verify:

```bash
sudo cat /etc/sudoers.d/unlimitsky
```

**If you copied files manually** (no install script), or protocol install / create config fails with permission errors, add these lines once:

```bash
sudo visudo -f /etc/sudoers.d/unlimitsky
```

Paste (replace the path if your panel is not under `/var/www/unlimitsky` — check **Panel → Protocols** for the exact path):

```
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/install-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/add-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/disable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/enable-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/remove-user-*.sh *
www-data ALL=(root) NOPASSWD: /bin/bash /var/www/unlimitsky/bin/collect-usage-stats.sh
```

Save and exit. Then:

```bash
sudo chmod 440 /etc/sudoers.d/unlimitsky
```

| Script | Used for |
|--------|----------|
| `install-*.sh` | **Panel → Protocols** — install WireGuard, OpenVPN, etc. |
| `add-user-*.sh` | Create config (admin, WooCommerce API, manual sales) |
| `disable-user-*.sh` | Cron — block connection when plan expires or volume is used |
| `enable-user-*.sh` | **Services → Extend** — re-enable after renewal |
| `remove-user-*.sh` | **Services → Remove from server** — manual delete |
| `collect-usage-stats.sh` | Read live traffic (WireGuard, OpenVPN, Xray, Amnezia) for usage sync |

Without `add-user-*.sh`, creating configs from the panel or WooCommerce will fail. Without `disable-user-*.sh`, expired accounts stay connected until you remove them manually. Without `collect-usage-stats.sh` in sudoers, usage sync from the panel or cron may show **0 GB** for all services.

---

## Automatic usage sync (optional cron)

By default, **volume usage is not live**. Open **Panel → Services** and click **Update all usage** to:

- read live traffic from **WireGuard**, **OpenVPN**, **Xray**, and **Amnezia**
- save **GB used** on each service
- **disable** accounts that exceeded volume or expired

If you prefer not to click that button regularly, run the same job on a schedule with **cron** (recommended for production).

### 1. Test manually first

Replace `/var/www/unlimitsky` if your panel lives elsewhere (see **Panel → Protocols**):

```bash
sudo -u www-data php /var/www/unlimitsky/cron/native-limits.php
```

You should see JSON with `usage_updated`, `checked`, and `disabled`. Then refresh **Panel → Services** — the **Traffic used** column should show numbers instead of “Click Update all usage”.

### 2. Enable cron (every 15 minutes)

```bash
sudo tee /etc/cron.d/unlimitsky-limits <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

*/15 * * * * www-data timeout 240 /usr/bin/php /var/www/unlimitsky/cron/native-limits.php >> /var/log/unlimitsky-limits.log 2>&1
EOF
sudo chmod 644 /etc/cron.d/unlimitsky-limits
```

| Interval | Meaning |
|----------|---------|
| `*/15 * * * *` | Every **15 minutes** (good default) |
| `*/5 * * * *` | Every **5 minutes** (busier shops) |
| `*/30 * * * *` | Every **30 minutes** (small servers) |

> Do not set faster than every **5 minutes** unless you have a clear reason — each run reads live VPN stats on the server.

### 3. Check that cron runs

```bash
# Wait until the next quarter-hour, then:
sudo tail -20 /var/log/unlimitsky-limits.log
```

In the panel, **Panel → Services** shows the last sync time after a successful run.

### 4. Disable automatic sync

```bash
sudo rm -f /etc/cron.d/unlimitsky-limits
```

You can still use **Update all usage** in the panel anytime.

### Notes

- **Fresh install** (`install-ubuntu.sh` / one-line installer): metering paths and sudoers are set up automatically.
- **Older servers** upgraded from an earlier version: if usage stays at 0, run once:
  ```bash
  sudo bash /var/www/unlimitsky/bin/xray-fix-stats-api.sh
  sudo bash /var/www/unlimitsky/bin/openvpn-fix-status.sh
  ```
- **OpenVPN:** bytes are read from the server **status log** while a client is connected; after disconnect, the last synced total is kept in the panel.

---

## Step 2 — Configure admin panel

### 1. Install protocols (start here)

**Panel → Protocols**

- Install **WireGuard** first (simplest)
- Then OpenVPN, Xray, or L2TP as needed
- Each runs **on this VPS** — the script installs packages, writes config, opens firewall ports, and starts the service

This is the **primary** way unlimitsky is designed to work: **your VPS = your VPN server**.

### 2. Create sales plans

**Panel → Plans**

| Field | Description |
|-------|-------------|
| Name | e.g. "30-day 50GB plan" |
| Volume (GB) | Traffic limit |
| Duration (days) | Service validity |
| Price | Display only — actual payment in WooCommerce |

- **Free:** 1 plan only
- **Pro:** unlimited plans — **Panel → Pro License** → enter `USK-...` key

### 3. API key for WooCommerce (native protocols)

**Panel → API Keys**

- Create an API key (shown once — copy it)
- Note the **API URL** (e.g. `http://YOUR_IP:8082/api/v1.php`)
- In WordPress plugin: add panel type **unlimitsky (native)** with API URL + key

Extended guide: [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)

---

# Marzban & Sanaei (3x-ui) — setup guide

> **Read this section before connecting external panels.** Marzban and Sanaei only support **VLESS / VMess (Xray)** — not WireGuard, OpenVPN, or Amnezia.

## Requirements

| Requirement | Details |
|-------------|---------|
| **Pro license** | Connecting Marzban/Sanaei in **Panel → Panels / Servers** requires **Pro** (`Panel → Pro License`) |
| **Marzban or Sanaei** | Already installed and reachable on a VPS (same server or another) |
| **WooCommerce** | WordPress host must reach the unlimitsky API URL and (if used) the Marzban/Sanaei panel URL |

## Supported protocols on external panels

| Panel | Protocols | Notes |
|-------|-----------|-------|
| **Marzban** | VLESS, VMess | Configure in panel field `vless\|vmess\|` + inbound tags |
| **Sanaei (3x-ui)** | VLESS, VMess | Uses an existing inbound on 3x-ui — link built from template |

Native protocols (WireGuard, OpenVPN, Xray Reality on VPS, Amnezia, L2TP) use **Panel → Protocols**, not Marzban/Sanaei.

---

## Part 1 — Connect panel in unlimitsky admin (VPS)

1. Activate **Pro:** **Panel → Pro License** → enter your `USK-...` key  
2. Open **Panel → Panels / Servers** (sidebar)  
3. Read **Panel → Connection guide** for field-by-field help  
4. Add panel → **Save & test connection**

### Marzban

| Field | Example | Notes |
|-------|---------|-------|
| Display name | `Main Marzban` | Shown in admin and WooCommerce |
| Type | `Marzban` | |
| Panel URL | `https://185.x.x.x:8000` | No trailing slash; port must be open |
| Username | `admin` | Marzban admin user |
| Password | `***` | Marzban admin password |
| Protocols | `vless\|vmess\|` | Pipe-separated; trailing `\|` optional |
| Flow | `flowon` | Use `flowon` for VLESS + Vision on supported inbounds |
| Inbounds | One tag per line | Tags from Marzban (e.g. `VLESS TCP`) |

**After save:** panel runs a login test. Status should become **active** and a token is stored.

**Manual test:** **Panel → Create config** → mode **Marzban / Sanaei (external panel)** → select your Marzban server → create a test user → copy subscription link.

### Sanaei (3x-ui)

| Field | Example | Notes |
|-------|---------|-------|
| Display name | `Sanaei EU` | |
| Type | `Sanaei (3x-ui)` | |
| Panel URL | `http://185.x.x.x:2053` | Default 3x-ui port is often 2053 |
| Username / Password | 3x-ui login | |
| Inbound ID | `1` | Number from **Panel → Inbounds** in 3x-ui |
| Link template | see below | Required for subscription link |

**Link template placeholders:**

| Placeholder | Meaning |
|-------------|---------|
| `%s1` | Client UUID |
| `%s2` | Host:port (e.g. `185.x.x.x:443`) |
| `%s3` | Remark / email |

Example VLESS template:

```
vless://%s1@%s2?encryption=none&security=tls&type=ws&host=example.com&path=/path#%s3
```

Get the exact format from your inbound in 3x-ui (QR export or share link), then replace uuid/host/remark with `%s1`, `%s2`, `%s3`.

**Inbound on 3x-ui:** must already exist with VLESS or VMess — unlimitsky adds a **client** to that inbound, not a new inbound.

---

## Part 2 — WooCommerce auto-delivery

Two supported setups. **Method A is recommended** if you already configured Marzban/Sanaei on the unlimitsky VPS.

### Method A — External panel via unlimitsky API (recommended)

Panels are managed once on the VPS; WooCommerce picks which panel to use per product.

**On VPS (unlimitsky panel):**

1. **Pro** active  
2. Marzban and/or Sanaei connected (**Part 1**)  
3. **Panel → API Keys** → create key → copy **API URL** + **API key**

**On WordPress:**

1. **unlimitsky → Panels → Add panel**  
   - Type: **unlimitsky (native)**  
   - API URL: `http://YOUR_VPS_IP:8082` (or full `.../api/v1.php`)  
   - API key: `USK-API-...`  
   - **Test connection**

2. **Products → VPN product**  
   - Check **VPN product**  
   - Connection: your **unlimitsky** panel  
   - **Config target:** **Marzban / Sanaei panel (VLESS/VMess — Xray)**  
   - **Marzban / Sanaei panel (on VPS):** choose from list (loaded from VPS)  
   - Set volume (GB) and duration (days) + price  

3. Customer pays → plugin calls VPS API with `panel_code` → user created on Marzban/Sanaei → link in order, email, **My Account → VPN Services**

```
Customer pays (WooCommerce)
        ↓
WordPress plugin → POST /api/v1.php?action=create-service  (panel_code)
        ↓
unlimitsky VPS → Marzban API or Sanaei addClient
        ↓
Subscription / VLESS link returned to customer
```

### Method B — Direct Marzban/Sanaei in WordPress

Use if the VPN panel is **not** registered on unlimitsky VPS (legacy setup).

1. **unlimitsky → Panels → Add panel** → type **Marzban** or **Sanaei**  
2. Enter panel URL, username, password (+ inbound ID / link template for Sanaei)  
3. **Test connection**  
4. **Products → VPN product** → select that Marzban/Sanaei panel directly  
5. Only **VLESS/VMess** configs are created — no WireGuard/OpenVPN on these panels  

> WordPress server must have **network access** to the Marzban/Sanaei URL (curl from host).

---

## Part 3 — Create config manually (admin)

**Panel → Create config**

1. Plan: manual volume/days or select a plan  
2. Mode: **Marzban / Sanaei (external panel)** (Pro required)  
3. Select connected panel  
4. Submit → subscription link shown on result page  

---

## Troubleshooting — Marzban / Sanaei

| Problem | Fix |
|---------|-----|
| **Panels / Servers** menu missing or form disabled | Activate **Pro license** |
| Marzban test failed | Check URL, port, firewall, admin username/password |
| Sanaei test failed | Check 3x-ui URL; ensure `cookie.txt` path is writable on VPS |
| WooCommerce: external panel list empty | Connect Marzban/Sanaei on VPS first; Pro active; API key valid |
| WooCommerce: `panels_pro_required` | Pro license on VPS expired or not activated |
| Sanaei: config created but link broken | Fix **link template** and **Inbound ID** on VPS panel settings |
| Marzban: user created, no subscription | Check **inbounds** tags match Marzban; protocols include `vless` or `vmess` |
| Wrong protocol (expected Xray) | External panels only support VLESS/VMess — use native **Xray** in **Panel → Protocols** for VLESS Reality on VPS |

**API check (external panels list):**

```bash
curl -s -H "Authorization: Bearer USK-API-YOUR-KEY" \
  "http://YOUR_VPS_IP:8082/api/v1.php?action=panels"
```

Expected: `"ok": true` and `"panels": [ ... ]`.

## Step 3 — Install WooCommerce plugin

Your shop runs on **WordPress hosting** (can be separate from VPS).

### 1. Prepare WordPress

- WordPress + WooCommerce installed and active
- A payment gateway configured in WooCommerce

### 2. Upload plugin

Copy from the repository:

```
wordpress-plugin/unlimitsky-woocommerce/
```

To:

```
wp-content/plugins/unlimitsky-woocommerce/
```

Or ZIP and upload via **Plugins → Add New → Upload**.

### 3. Activate

- **Plugins → unlimitsky - WooCommerce** → Activate
- **Settings → Permalinks → Save** (once)

---

> For **native protocols only** (WireGuard, OpenVPN, Xray on VPS): use API key — Marzban/Sanaei on VPS is not required.

---

## Step 4 — Connect WooCommerce to VPN panel

### WooCommerce auto-delivery options

| Method | Setup |
|--------|--------|
| **Native protocols** | Panel → API Keys → WordPress: unlimitsky panel type |
| **Marzban / Sanaei** | WordPress: add Marzban/Sanaei panel |

### A. Native protocols (recommended)

1. **Panel → API Keys** — create key, copy API URL
2. **WordPress → unlimitsky → Panels** — type: **unlimitsky (native)**
   - API URL: `http://YOUR_VPS_IP:8082`
   - API key: `USK-API-...`
   - Default protocol: WireGuard / OpenVPN / Xray / L2TP
3. **Products → VPN product** — select unlimitsky panel, set volume/duration
4. Customer pays → config delivered automatically

### B. Marzban / Sanaei (Pro + external panels)

**Full step-by-step guide:** [Marzban & Sanaei setup guide](#marzban--sanaei-3x-ui--setup-guide) (start here).

Short checklist:

1. **VPS:** Pro license → **Panel → Panels / Servers** → connect Marzban or Sanaei → test OK  
2. **VPS:** **Panel → API Keys** → create API key  
3. **WordPress:** unlimitsky panel type **unlimitsky (native)** + API URL + key  
4. **Product:** Config target = **Marzban / Sanaei** → select panel from VPS list  
5. Customer pays → VLESS/VMess link delivered automatically  

Alternative: add Marzban/Sanaei **directly in WordPress** (Method B in the guide above).

---

## Architecture overview

```
┌──────────────────── VPS (Ubuntu) ────────────────────┐
│  unlimitsky Panel :8082                              │
│  ├── MySQL (plans, users, orders)                    │
│  ├── ★ WireGuard / OpenVPN / Xray / L2TP (built-in)│
│  └── Marzban or Sanaei (optional — WC auto today)    │
└──────────────────────────────────────────────────────┘
                          ↑ API (Marzban/Sanaei today)
┌──────────────── WordPress Host ──────────────────────┐
│  WooCommerce + unlimitsky plugin                     │
│  └── customer purchase → auto config delivery        │
└──────────────────────────────────────────────────────┘
```

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Page won't load | Open port 8082 in ufw + cloud panel |
| HTTP 500 on install | DB host must be `localhost` — not the database name |
| `License server not configured` | Pro only — Free works without license |
| Can't create 2nd plan | Activate Pro in **Panel → License** |
| Protocol install fails | Check `/etc/sudoers.d/unlimitsky` — see **Sudo for VPN scripts** above |
| Create config / WooCommerce fails (permission) | Add `add-user-*.sh` line to sudoers — see **Sudo for VPN scripts** |
| WooCommerce no config | Test panel connection in WP + order status Completed |
| External panel list empty in product | Connect Marzban/Sanaei on VPS (Pro) + valid API key |
| `panels_pro_required` API error | Activate Pro on unlimitsky VPS |
| Plugin cURL error | Enable cURL in PHP on WordPress host |

**Verify install:**

```bash
sudo cat /root/unlimitsky-client.credentials
ls -la /var/www/unlimitsky/install/unlimitsky.install
```

---

## Folder structure

```
unlimitsky-client/
├── admin/              Web admin panel
├── install/            Install wizard + create-db.sh + finish-install.sh
├── bin/                Protocol install scripts
├── data/               Protocol status files
├── wordpress-plugin/   WooCommerce plugin
├── config.php          Settings (after install)
├── cron/               License + protocol cron jobs
├── scripts/            install.sh (GitHub one-liner)
├── config.sample.php   Template → config.php on install
└── install-ubuntu.sh   Ubuntu installer
```

After installation, the running panel lives at `/var/www/unlimitsky`.

---

## Support

- **Pro license** issues → contact your panel provider
- **Install** issues → see troubleshooting above

Extended guide: [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)
