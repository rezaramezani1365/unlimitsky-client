# unlimitsky — VPN Reseller Panel (Client)

[![GitHub](https://img.shields.io/badge/GitHub-unlimitsky--client-blue?logo=github)](https://github.com/rezaramezani1365/unlimitsky-client)

> **فارسی:** [README.fa.md](README.fa.md)

Admin panel for **VPN resellers**. Install on your Ubuntu VPS, create plans, sell configs via **WooCommerce**, and optionally connect **Marzban / Sanaei** (Pro).

**Repository:** [github.com/rezaramezani1365/unlimitsky-client](https://github.com/rezaramezani1365/unlimitsky-client)

---

## Table of contents

1. [What is this?](#what-is-this)
2. [Install in one command](#install-in-one-command)
3. [Features](#features)
4. [VPN protocols](#vpn-protocols)
5. [Free vs Pro](#free-vs-pro)
6. [Requirements](#requirements)
7. [After install — first steps](#after-install--first-steps)
8. [WooCommerce shop](#woocommerce-shop)
9. [Usage, limits & connection slots](#usage-limits--connection-slots)
10. [Update panel & VPS migration](#update-panel--vps-migration)
11. [Optional: Marzban / Sanaei (Pro)](#optional-marzban--sanaei-pro)
12. [Optional: Nodes — second VPS (Pro)](#optional-nodes--second-vps-pro)
13. [Troubleshooting](#troubleshooting)
14. [Security](#security)
15. [Project layout](#project-layout)

---

## What is this?

```
You (VPN reseller)
    ├── Ubuntu VPS     →  unlimitsky Panel (this project)
    └── WordPress host →  WooCommerce + unlimitsky plugin
                                ↓
                  Customer pays → config delivered automatically
```

unlimitsky is a **self-hosted reseller panel**. Your data stays on **your VPS** (MySQL + files). No third-party panel is required for WireGuard, OpenVPN, Xray, or L2TP — the installer scripts set up VPN services directly on the server.

| Data | Where |
|------|--------|
| Plans, services, admin | **MySQL** on your VPS |
| Panel settings, license | `admin/data/` on VPS |
| Protocol state | `data/protocols/` on VPS |
| WooCommerce orders | WordPress database on shop host |

---

## Install in one command

On a **fresh Ubuntu VPS** (22.04 or 24.04), SSH as root and run:

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall
```

The script installs **nginx, MySQL, PHP**, creates the database, configures the admin account, and opens the firewall.

| When done | Value |
|-----------|--------|
| **Panel URL** | `http://YOUR_SERVER_IP:8082/admin/login.php` |
| **Username** | `admin` |
| **Password** | what you passed to `--admin-pass` (min. 6 characters) |
| **Saved on server** | `sudo cat /root/unlimitsky-client.credentials` |

| Flag | Meaning |
|------|---------|
| `--port 8082` | Web port for the panel |
| `--admin-pass '...'` | Admin password (omit = `admin` / `admin` + forced change on first login) |
| `--open-firewall` | Open the port in ufw if ufw is active |

**Optional — Pro license from vendor:**

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall \
  --license-url 'https://license.yourdomain.com/api/v1.php' \
  --license-token 'SECRET'
```

**Alternative — clone then install:**

```bash
sudo git clone https://github.com/rezaramezani1365/unlimitsky-client.git /opt/unlimitsky
cd /opt/unlimitsky
sudo bash install-ubuntu.sh --auto --port 8082 --admin-pass 'Pass123' --open-firewall
```

**Browser install** (no `--auto`): run `install-ubuntu.sh` without `--auto`, then open `http://YOUR_IP:8082/install/index.php`. Database fields are auto-provisioned.

Also open port **8082** in your cloud provider firewall (Hetzner, DigitalOcean, etc.).

---

## Features

| Feature | Description |
|---------|-------------|
| **Native VPN on VPS** | WireGuard, OpenVPN, Xray, L2TP — install from **Panel → Protocols** |
| **Sales plans** | Volume (GB), duration (days), price display |
| **Manual configs** | **Panel → Create config** without a plan |
| **WooCommerce plugin** | Auto-delivery after payment (native or Marzban/Sanaei) |
| **WireGuard QR** | QR code in email and order page |
| **Usage sync** | Track GB used; disable expired / over-limit accounts |
| **Connection slots** | Limit simultaneous devices per plan (e.g. single-user / 2-user) — enforced at connect time |
| **Usage sync interval** | **Panel → Settings** — set 1–120 min (default 5) based on VPS strength |
| **Backup & migration** | Export/import `.uskbackup` between servers |
| **Persian / English UI** | Light / dark theme |
| **Pro extras** | Unlimited plans, Marzban/Sanaei, Nodes (Xray on a second VPS) |

---

## VPN protocols

Install from **Panel → Protocols**. Each protocol runs **on this VPS** — packages, config, firewall, and systemd service are handled by the script.

| Protocol | Default port | Best for |
|----------|--------------|----------|
| **WireGuard** | 51820 | Mobile & desktop — fast, lightweight |
| **OpenVPN** | 1194 | Maximum device compatibility |
| **Xray (VLESS/VMess)** | 443 | Filtering bypass, CDN-friendly |
| **L2TP/IPsec** | 1701 | Routers & Windows built-in VPN |

**Recommendation for beginners:** install **WireGuard** first, create one plan, then connect WooCommerce.

### Native vs Marzban / Sanaei

| | Native (built-in) | Marzban / Sanaei (optional, Pro) |
|---|-------------------|----------------------------------|
| Setup | **Panel → Protocols** | You run Marzban or 3x-ui separately |
| Protocols | WireGuard, OpenVPN, Xray, L2TP | VLESS / VMess only |
| WooCommerce | ✅ API key + native panel type | ✅ Via unlimitsky API or direct in WP |

---

## Free vs Pro

| Feature | Free | Pro |
|---------|------|-----|
| All native protocols | ✅ | ✅ |
| Sales plans | **1 plan** | Unlimited (per license) |
| Admin + WooCommerce plugin | ✅ | ✅ |
| Marzban / Sanaei | — | ✅ **Panel → Panels / Servers** |
| Nodes (Xray on another VPS) | — | ✅ **Panel → Nodes** |
| Activation | — | **Panel → Pro License** → `USK-...` key |

`License server not configured` during install is **normal on Free** — the panel works without a vendor license.

---

## Requirements

| Component | Minimum |
|-----------|---------|
| **Panel VPS** | Ubuntu 20.04 / 22.04 / 24.04 · 1 GB RAM (2 GB recommended) · root/sudo · port 8082 open |
| **Shop (WooCommerce)** | WordPress 5.8+ · WooCommerce 5.0+ · PHP 7.4+ with cURL · HTTPS recommended |
| **Node (Pro, optional)** | Second Ubuntu VPS · SSH password from Hub · `sshpass` on Hub |

---

## After install — first steps

Follow this order if you are new:

1. **Log in** — `http://YOUR_IP:8082/admin/login.php` · change default password.
2. **Panel → Protocols** — install **WireGuard** (simplest). Wait until status is active.
3. **Panel → Plans** — create one plan (volume GB + days). Free = 1 plan only.
4. **Panel → Create config** — test a manual config before selling.
5. **Panel → API Keys** — create a key for WooCommerce (shown once — copy it).
6. **Panel → Settings** — set usage sync interval (e.g. 5 min on a small VPS, 15–30 min if the server is weak).
7. Upload the **WooCommerce plugin** (see below).

**Sudo for VPN scripts:** if you used the install script, `/etc/sudoers.d/unlimitsky` is usually already set. Without it, protocol install and config creation fail. Verify:

```bash
sudo cat /etc/sudoers.d/unlimitsky
```

If missing, see [Security → sudo for www-data](#security).

---

## WooCommerce shop

Your shop runs on **WordPress hosting** (can be a different server from the VPS).

### 1. Install the plugin

Copy `wordpress-plugin/unlimitsky-woocommerce/` to `wp-content/plugins/unlimitsky-woocommerce/` (or upload ZIP via **Plugins → Add New**). Activate and save **Settings → Permalinks** once.

### 2. Connect the panel (native — recommended)

1. **Panel → API Keys** on VPS — copy API URL and key.
2. **WordPress → unlimitsky → Panels → Add** — type **unlimitsky (native)** · API URL `http://YOUR_VPS_IP:8082` · API key · **Test connection**.
3. **Products → VPN product** — enable VPN product · select unlimitsky panel · set volume, days, price.
4. Customer pays → config appears in order, email, and **My Account → VPN Services**.

### 3. Marzban / Sanaei via WooCommerce (Pro)

Connect panels on VPS first (**Panel → Panels / Servers**), then in the product choose **Config target: Marzban / Sanaei**. Details: [Optional: Marzban / Sanaei](#optional-marzban--sanaei-pro).

Extended guide: [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)

---

## Usage, limits & connection slots

### Traffic usage (GB)

- **Manual:** **Panel → Services → Update all usage** reads live stats from WireGuard, OpenVPN, Xray, and Amnezia.
- **Automatic:** fresh install enables cron (`usage-sync-gate.php` every minute). Heavy sync runs only when the interval in **Panel → Settings** elapses (default **5 minutes**, range 1–120).
- Expired or over-limit services are **disabled** on the server (no new connection). Records stay in **Panel → Services** — extend or delete manually.

Check cron log:

```bash
sudo tail -20 /var/log/unlimitsky-limits.log
```

Manual test:

```bash
sudo -u www-data php /var/www/unlimitsky/cron/native-limits.php
```

### Connection slots (multi-device plans)

Plans can allow **1, 2, … N simultaneous devices** (different IP addresses). Limits are enforced **when the user connects** — not by polling. The customer portal shows a label like “Single-user” or “2-user”, not live “1/2” counts.

Supported on native **OpenVPN** and **Xray** (install hooks are applied automatically on update).

---

## Update panel & VPS migration

### Update files (keep database)

```bash
sudo bash /var/www/unlimitsky/scripts/panel-self-update.sh
```

Or re-run the one-line `curl install.sh` (safe — it updates files and re-runs setup if needed).

### Migrate to a new VPS

1. **Old server** → **Panel → Backup & migration** → download `.uskbackup`
2. **New server** → install unlimitsky (one command above)
3. **New server** → import backup
4. **Panel → Protocols** → reinstall protocols (OS-level VPN keys are not in the backup)
5. Re-create **API key** for WooCommerce if needed
6. If you had **Pro:** import shows Free until you **Panel → Pro License → Activate** again (contact license provider if IP changed)

---

## Optional: Marzban / Sanaei (Pro)

Requires **Pro** and an existing **Marzban** or **Sanaei (3x-ui)** installation. Supports **VLESS / VMess only** — not WireGuard or OpenVPN.

1. **Panel → Pro License** → activate `USK-...`
2. **Panel → Panels / Servers** → add panel → **Save & test connection**
3. **Panel → Connection guide** — field-by-field help in the panel

| Panel | URL example | Extra fields |
|-------|-------------|--------------|
| **Marzban** | `https://IP:8000` | Protocols `vless\|vmess\|`, inbound tags |
| **Sanaei** | `http://IP:2053` | Inbound ID, link template (`%s1` UUID, `%s2` host:port, `%s3` remark) |

**WooCommerce:** use unlimitsky (native) panel type + API key; in the product select **Marzban / Sanaei** as config target.

**API test:**

```bash
curl -s -H "Authorization: Bearer USK-API-YOUR-KEY" \
  "http://YOUR_VPS_IP:8082/api/v1.php?action=panels"
```

---

## Optional: Nodes — second VPS (Pro)

Run **Xray on a second VPS** while keeping the panel on the Hub. Hub connects via **SSH** (one shared registration password from the panel — not per-node tokens).

| Where | Path |
|-------|------|
| Sidebar | **Nodes** (after Panels / Servers) |
| Direct URL | `http://YOUR_IP:8082/admin/index.php?page=nodes` |

**Hub prerequisites:**

```bash
sudo apt install -y sshpass
sudo bash /var/www/unlimitsky/scripts/panel-self-update.sh
```

**On the Node VPS:**

```bash
curl -fsSL http://HUB_IP:8082/bin/install-node.sh | sudo bash -s
```

Follow prompts (Hub IP, port 8082, registration password from **Panel → Nodes**, SSH user/password on the Node). Then **Test SSH** in the panel and create Xray configs with **Provisioning server → Node**.

| Issue | Fix |
|-------|-----|
| Nodes menu missing | `panel-self-update.sh` · activate Pro · hard-refresh browser |
| SSH test fails | SSH password · port 22 open Hub→Node · `sshpass` on Hub |
| Provision fails | On Node: `sudo bash /opt/unlimitsky-node/bin/repair-xray.sh` |

---

## Troubleshooting

### Install did not finish?

Errors usually appear at `[*] Running CLI install (database + admin)...`

**Step 1 — use the latest code**

The log should show a recent commit (e.g. `fba58e7` or newer):

```bash
curl -fsSL https://raw.githubusercontent.com/rezaramezani1365/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'YOUR_PASS' --open-firewall
```

Or update an existing install:

```bash
sudo bash /var/www/unlimitsky/scripts/panel-self-update.sh
```

**Step 2 — remove install lock** (only if install never completed successfully):

```bash
sudo rm -f /var/www/unlimitsky/install/unlimitsky.install
```

Then run the `curl install.sh` command again.

**Step 3 — if the database was left half-created**

```bash
sudo mysql -e "DROP DATABASE IF EXISTS usk_client;"
sudo rm -f /var/www/unlimitsky/install/unlimitsky.install
# then run curl install again
```

**Step 4 — repair schema only** (config.php already filled, tables incomplete):

```bash
sudo php /var/www/unlimitsky/install/repair-schema.php
```

| Error message | Cause | Fix |
|---------------|-------|-----|
| `Duplicate column name 'connections'` | Old version or partial install | Update to latest + steps 2–3 above |
| `Undefined constant "USK_ROOT"` | Old version before license fix | `panel-self-update.sh` or re-run curl install |
| `Database setup failed: ...` | MySQL / permissions | Check `systemctl status mysql` · credentials in `/root/unlimitsky-client.credentials` |
| HTTP 500 on install | Wrong DB host | Host must be `localhost`, not the database name |

**Verify successful install:**

```bash
sudo cat /root/unlimitsky-client.credentials
ls -la /var/www/unlimitsky/install/unlimitsky.install
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8082/admin/login.php
```

Last command should return `200`.

---

### Panel & VPN issues

| Problem | Fix |
|---------|-----|
| Page won't load | Open port 8082 in ufw + cloud panel |
| Protocol install fails | Check `/etc/sudoers.d/unlimitsky` |
| Create config / WooCommerce permission error | Add `add-user-*.sh` to sudoers — see [Security](#security) |
| Usage always 0 GB | **Update all usage** once · check cron log · on old servers: `xray-fix-stats-api.sh`, `openvpn-fix-status.sh` |
| WooCommerce no config | Test panel in WP · order status **Completed** · valid API key |
| Cannot create 2nd plan | Activate **Pro** |
| External panel list empty in product | Connect Marzban/Sanaei on VPS + Pro + API key |
| `panels_pro_required` | Pro not active on VPS |
| Plugin cURL error | Enable PHP cURL on WordPress host |

---

## Security

| Layer | Protection |
|-------|------------|
| MySQL | `localhost` only · random password |
| nginx | Blocks `/sql/`, `/admin/data/`, direct `config.php` |
| Admin login | 5 failed attempts → 15-minute lockout |
| Install wizard | Disabled after successful setup (`unlimitsky.install`) |

**Recommended:** HTTPS (Let's Encrypt) · change default `admin` password on first login.

**sudo for www-data** (if manual copy without install script):

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

## Project layout

```
unlimitsky-client/
├── admin/              Web admin panel
├── api/                REST API (WooCommerce)
├── bin/                Protocol install & user scripts
├── cron/               Usage sync, license check
├── install/            CLI + web installer
├── scripts/            install.sh, panel-self-update.sh
├── sql/                Database schema
├── wordpress-plugin/   WooCommerce plugin
└── install-ubuntu.sh   Main Ubuntu installer
```

Running panel path after install: **`/var/www/unlimitsky`**

---

## Support

- **Pro license** → contact your panel provider
- **Install / VPN issues** → [Troubleshooting](#troubleshooting) above
- **Reseller guide** → [docs/RESELLER-GUIDE.md](docs/RESELLER-GUIDE.md)
