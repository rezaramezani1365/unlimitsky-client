#!/bin/bash
# UnlimitSky Client (reseller panel) — Ubuntu one-command installer
#
# Full auto (default admin/admin — change on first login):
#   sudo bash install-ubuntu.sh --auto --port 8082 --open-firewall
#
# Custom admin password:
#   sudo bash install-ubuntu.sh --auto --port 8082 --admin-pass 'YourPass123' --open-firewall
#
set -euo pipefail

if [ "$EUID" -ne 0 ]; then
    echo "Run as root: sudo bash install-ubuntu.sh [options]"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB="$SCRIPT_DIR/install/lib.sh"
if [ ! -f "$LIB" ]; then
    echo "ERROR: missing install/lib.sh (run from repo root)"
    exit 1
fi
# shellcheck source=/dev/null
source "$LIB"

PORT=8082
AUTO=0
ADMIN_USER="admin"
ADMIN_PASS=""
MUST_CHANGE=0
LANG=fa
LICENSE_URL=""
LICENSE_TOKEN=""
OPEN_FW=0
WEB_ROOT="/var/www/unlimitsky"
SITE_NAME="unlimitsky-client"
CREDS_FILE="/root/unlimitsky-client.credentials"

while [ $# -gt 0 ]; do
    case "$1" in
        --port) PORT="$2"; shift 2 ;;
        --auto) AUTO=1; shift ;;
        --admin-user) ADMIN_USER="$2"; shift 2 ;;
        --admin-pass) ADMIN_PASS="$2"; shift 2 ;;
        --must-change) MUST_CHANGE=1; shift ;;
        --lang) LANG="$2"; shift 2 ;;
        --license-url) LICENSE_URL="$2"; shift 2 ;;
        --license-token) LICENSE_TOKEN="$2"; shift 2 ;;
        --web-root) WEB_ROOT="$2"; shift 2 ;;
        --open-firewall) OPEN_FW=1; shift ;;
        -h|--help)
            cat <<'HELP'
UnlimitSky Client (reseller panel) installer

Options:
  --auto                 Create MySQL DB + admin via CLI (no browser)
  --port PORT            HTTP port (default: 8082)
  --admin-user USER      Admin username (default: admin)
  --admin-pass PASS      Admin password (default: admin — must change on first login)
  --must-change          Force password change on first login
  --lang fa|en           Panel language (default: fa)
  --license-url URL      Vendor API URL (optional)
  --license-token TOKEN  Vendor api_secret (optional)
  --web-root PATH        Install path (default: /var/www/unlimitsky)
  --open-firewall        Allow port in ufw if active

Example:
  sudo bash install-ubuntu.sh --auto --port 8082 --open-firewall
HELP
            exit 0
            ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [ "$AUTO" -eq 1 ] && [ -z "$ADMIN_PASS" ]; then
    ADMIN_PASS="admin"
    MUST_CHANGE=1
fi

export DEBIAN_FRONTEND=noninteractive
echo "[*] Installing packages (nginx, mysql, php)..."
apt-get update -qq
apt-get install -y nginx mysql-server php-cli php-fpm php-mysql php-curl php-json php-mbstring php-xml unzip curl sudo rsync openssl git

echo "[*] Starting MySQL..."
usk_mysql_ensure

echo "[*] Hardening MySQL (localhost only)..."
usk_mysql_harden

PHP_SOCK="$(usk_detect_php_sock)"
SERVER_IP="$(usk_detect_ip)"
PUBLIC_URL="http://${SERVER_IP}:${PORT}"

echo "[*] Deploying files to ${WEB_ROOT}..."
mkdir -p "$WEB_ROOT"
rsync -a --exclude '.git' --exclude 'install-ubuntu.sh' --exclude 'REPOSITORY.md' "$SCRIPT_DIR/" "$WEB_ROOT/" 2>/dev/null \
    || cp -r "$SCRIPT_DIR"/* "$WEB_ROOT/"

usk_reset_incomplete_install "$WEB_ROOT"

if [ ! -f "$WEB_ROOT/config.php" ] && [ -f "$WEB_ROOT/config.sample.php" ]; then
    cp "$WEB_ROOT/config.sample.php" "$WEB_ROOT/config.php"
fi

chmod +x "$WEB_ROOT"/bin/*.sh 2>/dev/null || true
chown -R www-data:www-data "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"
mkdir -p "$WEB_ROOT/data/protocols" "$WEB_ROOT/admin/data"
chmod -R 775 "$WEB_ROOT/admin/data" "$WEB_ROOT/data" "$WEB_ROOT/install" 2>/dev/null || true
chown -R www-data:www-data "$WEB_ROOT/data" "$WEB_ROOT/admin/data"

SUDOERS="/etc/sudoers.d/unlimitsky"
cat > "$SUDOERS" <<SUDO
www-data ALL=(root) NOPASSWD: /bin/bash ${WEB_ROOT}/bin/install-*.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${WEB_ROOT}/bin/add-user-*.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${WEB_ROOT}/bin/disable-user-*.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${WEB_ROOT}/bin/enable-user-*.sh
www-data ALL=(root) NOPASSWD: /bin/bash ${WEB_ROOT}/bin/remove-user-*.sh
SUDO
chmod 440 "$SUDOERS"

echo "[*] Configuring nginx on port ${PORT}..."
rm -f /etc/nginx/sites-enabled/default

cat > "/etc/nginx/sites-available/${SITE_NAME}" <<NGX
server {
    listen ${PORT};
    listen [::]:${PORT};
    server_name _;
    root ${WEB_ROOT};
    index home.php install/index.php;

    client_max_body_size 32m;
    server_tokens off;

    location ^~ /admin/data/ { deny all; return 404; }
    location ^~ /sql/ { deny all; return 404; }
    location = /config.php { deny all; return 404; }
    location ~ /install/\.db-provision\.json$ { deny all; return 404; }

    location / {
        try_files \$uri \$uri/ /home.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\. { deny all; }
}
NGX
ln -sf "/etc/nginx/sites-available/${SITE_NAME}" "/etc/nginx/sites-enabled/${SITE_NAME}"

nginx -t
systemctl enable nginx mysql 2>/dev/null || true
for _fpm in /lib/systemd/system/php*-fpm.service; do
    [ -f "$_fpm" ] && systemctl enable "$(basename "$_fpm" .service)" 2>/dev/null || true
done
systemctl restart mysql nginx
for _fpm in /lib/systemd/system/php*-fpm.service; do
    [ -f "$_fpm" ] && systemctl restart "$(basename "$_fpm" .service)" 2>/dev/null || true
done

[ "$OPEN_FW" -eq 1 ] && usk_firewall_allow_port "$PORT"

# Already installed and healthy — update files only
if [ "$AUTO" -eq 1 ] && [ -f "$WEB_ROOT/install/unlimitsky.install" ] && ! usk_config_incomplete "$WEB_ROOT"; then
    usk_secure_app_files "$WEB_ROOT"
    usk_print_box \
        "UnlimitSky Client updated" \
        "" \
        "URL:        ${PUBLIC_URL}" \
        "Admin:      ${PUBLIC_URL}/admin/login.php" \
        "" \
        "Install marker present — database left unchanged."
    exit 0
fi

echo "[*] Creating MySQL database..."
usk_mysql_create_app_db "usk_client" || exit 1
DB_NAME="$USK_DB_NAME"
DB_USER="$USK_DB_USER"
DB_PASS="$USK_DB_PASS"
usk_save_db_provision "$WEB_ROOT/install/.db-provision.json" "$DB_NAME" "$DB_USER" "$DB_PASS"

if [ "$AUTO" -eq 1 ]; then
    echo "[*] Running CLI install (database + admin)..."
    MC_FLAG=""
    [ "$MUST_CHANGE" -eq 1 ] && MC_FLAG="--must-change=1"
    php "$WEB_ROOT/install/cli-install.php" \
        --domain="$PUBLIC_URL" \
        --db-name="$DB_NAME" \
        --db-user="$DB_USER" \
        --db-pass="$DB_PASS" \
        --admin-user="$ADMIN_USER" \
        --admin-pass="$ADMIN_PASS" \
        --lang="$LANG" \
        --license-server="$LICENSE_URL" \
        --license-token="$LICENSE_TOKEN" \
        $MC_FLAG

    if [ ! -f "$WEB_ROOT/install/unlimitsky.install" ]; then
        echo "ERROR: Install did not finish. DB credentials: $WEB_ROOT/install/.db-provision.json" >&2
        exit 1
    fi

    usk_secure_app_files "$WEB_ROOT"

    usk_save_credentials "$CREDS_FILE" \
        echo "TYPE=client" \
        echo "URL=$PUBLIC_URL" \
        echo "ADMIN_URL=${PUBLIC_URL}/admin/login.php" \
        echo "ADMIN_USER=$ADMIN_USER" \
        echo "ADMIN_PASS=$ADMIN_PASS" \
        echo "MUST_CHANGE_PASSWORD=$([ "$MUST_CHANGE" -eq 1 ] && echo yes || echo no)" \
        echo "DB_NAME=$DB_NAME" \
        echo "DB_USER=$DB_USER" \
        echo "DB_PASS=$DB_PASS" \
        echo "LICENSE_URL=$LICENSE_URL" \
        echo "LICENSE_TOKEN=$LICENSE_TOKEN"
else
    echo "[*] Database provisioned for web installer (credentials stored on server)."
fi

usk_print_box \
    "UnlimitSky Client ready" \
    "" \
    "URL:        ${PUBLIC_URL}" \
    "Admin:      ${PUBLIC_URL}/admin/login.php" \
    "" \
    "$([ "$AUTO" -eq 1 ] && echo "Admin user: ${ADMIN_USER}")" \
    "$([ "$AUTO" -eq 1 ] && echo "Admin pass: ${ADMIN_PASS}$([ "$MUST_CHANGE" -eq 1 ] && echo ' (change on first login)'))" \
    "$([ "$AUTO" -eq 1 ] && echo "" && echo "Saved: ${CREDS_FILE}")" \
    "$([ "$AUTO" -eq 0 ] && echo "Next: open ${PUBLIC_URL}/install/index.php")" \
    "" \
    "Protocols: Admin → Protocols | WooCommerce plugin in wordpress-plugin/"
