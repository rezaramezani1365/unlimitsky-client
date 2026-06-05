# UnlimitSky — Reseller Guide

For **VPN business owners** who bought / use UnlimitSky panel.

## Your role

You are a **reseller**: you run VPN on your VPS and sell to **your customers** through your WooCommerce store.

You are **not** the platform owner. Pro license comes from **UnlimitSky vendor**.

## Setup checklist

### 1. VPS (Ubuntu 22.04+)

```bash
git clone https://github.com/VENDOR/unlimitsky.git
cd unlimitsky/client
sudo bash install-ubuntu.sh
```

### 2. Web install

| Step | URL |
|------|-----|
| Language | `/install/index.php` |
| Database + license API | `/install/setup.php` |

From vendor you receive:
- License API URL: `https://license.vendor.com/api/v1.php`
- API token (secret)

### 3. Panel login

`/admin/login.php`

### 4. Install protocols

**Admin → Protocols** — install on your VPS:
- WireGuard (recommended first)
- OpenVPN
- Xray (VLESS / VMess)
- L2TP

Requires `sudo` for `www-data` (configured by `install-ubuntu.sh`).

### 5. Create plans

**Admin → Plans**

- **Free:** max 1 plan  
- **Pro:** enter license in **Admin → License**

### 6. WooCommerce

1. WordPress + WooCommerce on your domain (can be same or different server)
2. Install plugin: `wordpress-plugin/unlimitsky-woocommerce/`
3. Connect panel URL + credentials in plugin settings
4. Create WooCommerce product → link to VPN plan
5. Customer pays → config delivered automatically

## Who is who

| Person | Uses |
|--------|------|
| **Platform vendor (UnlimitSky)** | `vendor/` panel — licenses only |
| **You (reseller)** | `client/` panel — this VPS |
| **Your customer** | Your WooCommerce shop only |

## Troubleshooting

| Problem | Solution |
|---------|----------|
| License invalid | Check API URL + token in `config.php` |
| Can't add 2nd plan | Activate Pro license |
| Protocol install fails | Check `/etc/sudoers.d/unlimitsky` |
| WooCommerce no config | Plugin settings + panel URL reachable |

## config.php (reseller)

```php
'license_server' => 'https://license.vendor.com/api/v1.php',
'license_api_token' => 'secret-from-vendor',
'free_max_plans' => 1,
```
