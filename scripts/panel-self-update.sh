#!/bin/bash
# Pull latest unlimitsky-client from GitHub and rsync to the live web root.
# Called from admin panel (www-data via sudo) or manually as root.
#
#   sudo bash scripts/panel-self-update.sh /var/www/unlimitsky
#
set -euo pipefail

WEB_ROOT="${1:-/var/www/unlimitsky}"
INSTALL_DIR="${USK_INSTALL_DIR:-/opt/unlimitsky}"
BRANCH="${USK_BRANCH:-main}"
REPO_URL="${USK_REPO_URL:-https://github.com/rezaramezani1365/unlimitsky-client.git}"

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: run as root" >&2
    exit 1
fi

if [ ! -f "$WEB_ROOT/config.php" ]; then
    echo "ERROR: $WEB_ROOT/config.php not found" >&2
    exit 1
fi

if ! command -v git >/dev/null 2>&1; then
    apt-get update -qq
    apt-get install -y git
fi

echo "[*] Fetching latest from GitHub ($REPO_URL)..."
if [ ! -d "$INSTALL_DIR/.git" ]; then
    mkdir -p "$(dirname "$INSTALL_DIR")"
    git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR"
else
    git -C "$INSTALL_DIR" fetch --depth 1 origin "$BRANCH"
    git -C "$INSTALL_DIR" checkout "$BRANCH"
    git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH"
fi

COMMIT="$(git -C "$INSTALL_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "[*] Source commit: $COMMIT"

UPDATE_SH="${INSTALL_DIR}/scripts/update-panel.sh"
if [ ! -f "$UPDATE_SH" ]; then
    echo "ERROR: $UPDATE_SH missing after git update" >&2
    exit 1
fi

bash "$UPDATE_SH" "$WEB_ROOT" "$INSTALL_DIR"

if [ -f "/usr/local/etc/xray/config.json" ] && [ -f "$WEB_ROOT/bin/xray-fix-stats-api.sh" ]; then
    echo "[*] Ensuring Xray Stats API (usage metering)..."
    bash "$WEB_ROOT/bin/xray-fix-stats-api.sh" >/dev/null 2>&1 || true
fi

if [ -f "/etc/unlimitsky-l2tp.psk" ] && [ -f "$WEB_ROOT/bin/repair-l2tp.sh" ]; then
    echo "[*] Ensuring L2TP/IPsec NAT + IPsec ciphers + services..."
    bash "$WEB_ROOT/bin/repair-l2tp.sh" "$WEB_ROOT" >/dev/null 2>&1 || true
elif [ -f "/etc/xl2tpd/xl2tpd.conf" ] && [ -f "$WEB_ROOT/bin/setup-l2tp-usage.sh" ]; then
    echo "[*] Ensuring L2TP usage metering hooks..."
    bash "$WEB_ROOT/bin/setup-l2tp-usage.sh" >/dev/null 2>&1 || true
fi

if [ -f "/etc/wireguard/wg0.conf" ] && [ -f "$WEB_ROOT/bin/repair-wireguard.sh" ]; then
    echo "[*] Ensuring WireGuard NAT + TCP bridge..."
    bash "$WEB_ROOT/bin/repair-wireguard.sh" 51822 >/dev/null 2>&1 || true
fi

if { [ -f /etc/openvpn/server-udp.conf ] || [ -f /etc/openvpn/server.conf ]; } \
    && [ -f "$WEB_ROOT/bin/repair-openvpn.sh" ]; then
    echo "[*] Ensuring OpenVPN NAT + tun routing..."
    bash "$WEB_ROOT/bin/repair-openvpn.sh" >/dev/null 2>&1 || true
fi

LIB="${INSTALL_DIR}/install/lib.sh"
if [ -f "$LIB" ]; then
    # shellcheck source=/dev/null
    source "$LIB"
    usk_ensure_web_update_sudoers "$WEB_ROOT"
    usk_ensure_usage_cron "$WEB_ROOT"
    usk_remove_connections_cron
    usk_ensure_fail2ban_iplimit "$WEB_ROOT"
    usk_disable_live_stats_daemon "$WEB_ROOT"
    usk_ensure_connection_slot_hooks "$WEB_ROOT"
fi

echo "[*] Panel update complete ($COMMIT)"
