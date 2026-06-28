#!/bin/bash
# Finish incomplete install — normally not needed; curl install handles everything.
# Usage: sudo bash install/finish-install.sh 'YourPass123'
set -euo pipefail

if [ "$EUID" -ne 0 ]; then
    echo "Run: sudo bash install/finish-install.sh ['YourPass123']"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT="/var/www/unlimitsky"
[ -d "$WEB_ROOT/install" ] || WEB_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=/dev/null
source "$SCRIPT_DIR/lib.sh"

ADMIN_PASS="${1:-Pass123}"
ADMIN_USER="admin"
MUST_CHANGE=0
PORT="${USK_PORT:-8082}"
IP="$(usk_detect_ip)"
PUBLIC_URL="http://${IP}:${PORT}"

if [ "$ADMIN_PASS" = "admin" ] || [ -z "${1:-}" ]; then
    ADMIN_PASS="admin"
    MUST_CHANGE=1
fi

if [ -f "$WEB_ROOT/install/unlimitsky.install" ] && ! usk_config_incomplete "$WEB_ROOT"; then
    echo "Already installed: ${PUBLIC_URL}/admin/login.php"
    exit 0
fi

usk_reset_incomplete_install "$WEB_ROOT"

if [ ! -f "$WEB_ROOT/install/.db-provision.json" ]; then
    echo "[*] Creating MySQL database..."
    usk_mysql_create_app_db "usk_client" || exit 1
    usk_save_db_provision "$WEB_ROOT/install/.db-provision.json" "$USK_DB_NAME" "$USK_DB_USER" "$USK_DB_PASS"
else
    USK_DB_NAME="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["db_name"];' "$WEB_ROOT/install/.db-provision.json")"
    USK_DB_USER="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["db_user"];' "$WEB_ROOT/install/.db-provision.json")"
    USK_DB_PASS="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["db_pass"];' "$WEB_ROOT/install/.db-provision.json")"
fi

echo "[*] Running install..."
MC_FLAG=""
[ "$MUST_CHANGE" -eq 1 ] && MC_FLAG="--must-change=1"
php "$WEB_ROOT/install/cli-install.php" \
    --domain="$PUBLIC_URL" \
    --db-name="$USK_DB_NAME" \
    --db-user="$USK_DB_USER" \
    --db-pass="$USK_DB_PASS" \
    --admin-user="$ADMIN_USER" \
    --admin-pass="$ADMIN_PASS" \
    --lang=fa \
    $MC_FLAG

usk_secure_app_files "$WEB_ROOT"
usk_ensure_usage_cron "$WEB_ROOT"
usk_remove_connections_cron
usk_ensure_fail2ban_iplimit "$WEB_ROOT"
usk_disable_live_stats_daemon "$WEB_ROOT"
usk_ensure_connection_slot_hooks "$WEB_ROOT"

usk_save_credentials "/root/unlimitsky-client.credentials" \
    "TYPE=client" \
    "URL=$PUBLIC_URL" \
    "ADMIN_URL=${PUBLIC_URL}/admin/login.php" \
    "ADMIN_USER=$ADMIN_USER" \
    "ADMIN_PASS=$ADMIN_PASS" \
    "MUST_CHANGE_PASSWORD=$([ "$MUST_CHANGE" -eq 1 ] && echo yes || echo no)" \
    "DB_NAME=$USK_DB_NAME" \
    "DB_USER=$USK_DB_USER" \
    "DB_PASS=$USK_DB_PASS"

usk_print_box \
    "Install complete" \
    "" \
    "Admin:  ${PUBLIC_URL}/admin/login.php" \
    "User:   ${ADMIN_USER}" \
    "Pass:   ${ADMIN_PASS}" \
    "" \
    "Saved: /root/unlimitsky-client.credentials"
