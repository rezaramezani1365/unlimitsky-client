# UnlimitSky — VPN Reseller Panel (Client)



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
    ├── Ubuntu VPS     →  UnlimitSky Panel (this project)
    └── WordPress host →  WooCommerce + UnlimitSky plugin
                                ↓
                  Customer pays → config delivered automatically
```

---



## What this panel does

### Built-in protocols on your VPS (main feature)

UnlimitSky **installs and runs VPN protocols directly on your Ubuntu VPS** — no third-party panel required:

| Protocol | Role |
|----------|------|
| **WireGuard** | Fast VPN for mobile & desktop |
| **OpenVPN** | Wide device support |
| **Xray (VLESS/VMess)** | Advanced routing, port 443 |
| **L2TP/IPsec** | Routers & Windows built-in VPN |

Install from **Panel → Protocols** with one click. Server config, firewall, and systemd service are handled by the script.

**Limits:** cron **disables** expired/over-limit accounts (no connection). Records stay in **Panel → Services** — extend to renew or delete manually.

**Manual create:** **Panel → Create config** — enter volume/days directly (no plan required).

**WireGuard QR:** after purchase, customers receive a scannable QR code in email and order page.

### Optional: Marzban / Sanaei (3x-ui)

If you already use **Marzban** or **Sanaei**, you can connect them as an **alternative** — useful especially for **WooCommerce auto-delivery today** (plugin creates configs via Marzban/Sanaei API after payment).

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

## Protocols on your VPS

From **Panel → Protocols**, the installer script sets up each protocol **on this server**:

| Protocol | Default port | Best for |
|----------|--------------|----------|
| **WireGuard** | 51820 | Mobile & desktop — fast and lightweight |
| **OpenVPN** | 1194 | Maximum device compatibility |
| **Xray (VLESS/VMess)** | 443 | Filtering bypass, behind CDN |
| **L2TP/IPsec** | 1701 | Routers and Windows without extra apps |

**What works now:** full **server installation** + **per-customer config creation** (admin + WooCommerce via API).

---

## Native protocols vs Marzban / Sanaei

| | Native protocols (built-in) | Marzban / Sanaei (optional) |
|---|---------------------------|----------------------------|
| Installed by | UnlimitSky on your VPS | Separate panel (you may already have it) |
| Server setup | ✅ Panel → Protocols | You manage Marzban/Sanaei yourself |
| Manual config in admin | ✅ Panel → Create config | ✅ Panel → Create config |
| WooCommerce auto-delivery | ✅ Panel → API Keys + plugin | ✅ Available now |

**Recommendation:** install **WireGuard** from **Panel → Protocols**, create an **API key**, connect WooCommerce with panel type **UnlimitSky (native)**.

---

## Free vs Pro

| Feature | Free | Pro |
|---------|------|-----|
| Install protocols | ✅ All | ✅ All |
| Sales plans | **1 plan** | Unlimited (per license) |
| Admin panel | ✅ | ✅ |
| WooCommerce plugin | ✅ | ✅ |
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

If you have a **Pro license** from the UnlimitSky platform owner, add:

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

**Nginx (LEMP — same stack as UnlimitSky):**

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

#### Option B: Nginx (recommended if UnlimitSky panel runs on Nginx)

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

Without `add-user-*.sh`, creating configs from the panel or WooCommerce will fail. Without `disable-user-*.sh`, expired accounts stay connected until you remove them manually.

---

## Step 2 — Configure admin panel

### 1. Install protocols (start here)

**Panel → Protocols**

- Install **WireGuard** first (simplest)
- Then OpenVPN, Xray, or L2TP as needed
- Each runs **on this VPS** — the script installs packages, writes config, opens firewall ports, and starts the service

This is the **primary** way UnlimitSky is designed to work: **your VPS = your VPN server**.

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
- In WordPress plugin: add panel type **UnlimitSky (native)** with API URL + key

### 4. Marzban / Sanaei (optional)

**Panel → Panels / Servers**

Only if you use Marzban or Sanaei (3x-ui) on this or another server:
- Enter panel URL, username, password
- Click **Test connection**
- Use **Panel → Create config** for manual sales, or WooCommerce plugin for automatic delivery

> Skip this step if you only want native protocols on VPS — WooCommerce will support native protocols in a future update.

---

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

- **Plugins → UnlimitedSky - WooCommerce** → Activate
- **Settings → Permalinks → Save** (once)

---

> Skip Marzban/Sanaei if you sell via native protocols + API key.

---

## Step 4 — Connect WooCommerce to VPN panel

### WooCommerce auto-delivery options

| Method | Setup |
|--------|--------|
| **Native protocols** | Panel → API Keys → WordPress: UnlimitSky panel type |
| **Marzban / Sanaei** | WordPress: add Marzban/Sanaei panel |

### A. Native protocols (recommended)

1. **Panel → API Keys** — create key, copy API URL
2. **WordPress → UnlimitSky → Panels** — type: **UnlimitSky (native)**
   - API URL: `http://YOUR_VPS_IP:8082`
   - API key: `USK-API-...`
   - Default protocol: WireGuard / OpenVPN / Xray / L2TP
3. **Products → VPN product** — select UnlimitSky panel, set volume/duration
4. Customer pays → config delivered automatically

### B. Marzban / Sanaei (optional)

### 1. Add panel in WordPress (Marzban/Sanaei)

**UnlimitSky → Panels → Add panel**

| Field | Example |
|-------|---------|
| Server name | "Germany Server 1" |
| Type | Marzban or Sanaei |
| Panel URL | `https://panel.yourdomain.com` |
| VPN server IP | Real VPS IP |
| User / password | Marzban/Sanaei login |

Click **Test connection** to verify WordPress can reach the VPN panel.

> Your WordPress server must have **network access** to the Marzban/Sanaei panel URL.

### 2. Create VPN product

**Products → Add New**

1. Type: **Simple product**
2. Check **"VPN product"**
3. Select **panel** (Marzban/Sanaei)
4. Set **volume (GB)** and **duration (days)** to match your plan
5. Set **price** in WooCommerce
6. Publish

### 3. Sales flow (automatic)

```
Customer buys in your shop
        ↓
Payment successful (Completed / Processing)
        ↓
Plugin creates config via Marzban/Sanaei API
        ↓
Subscription link appears in:
  • Thank-you page
  • Order details
  • Order email
  • My Account → "VPN Services"
```

---

## Architecture overview

```
┌──────────────────── VPS (Ubuntu) ────────────────────┐
│  UnlimitSky Panel :8082                              │
│  ├── MySQL (plans, users, orders)                    │
│  ├── ★ WireGuard / OpenVPN / Xray / L2TP (built-in)│
│  └── Marzban or Sanaei (optional — WC auto today)    │
└──────────────────────────────────────────────────────┘
                          ↑ API (Marzban/Sanaei today)
┌──────────────── WordPress Host ──────────────────────┐
│  WooCommerce + UnlimitSky plugin                     │
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
<<<<<<< HEAD
#   u n l i m i t s k y - c l i e n t 
 
 
=======
#
>>>>>>> 91c16102e4c78ef11c44ca334b50222e0600869f
