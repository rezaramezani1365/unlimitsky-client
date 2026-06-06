#!/bin/bash
# Update an already-installed client panel (keep database + config.php)
#
# From monorepo clone (client/ subfolder):
#   sudo bash scripts/update-panel.sh /var/www/unlimitsky /opt/unlimitsky/client
#
# From unlimitsky-client repo root (admin/ at top level):
#   sudo bash scripts/update-panel.sh /var/www/unlimitsky /opt/unlimitsky
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB="${SCRIPT_DIR}/../install/lib.sh"
# shellcheck source=/dev/null
[ -f "$LIB" ] && source "$LIB"

WEB_ROOT="${1:-/var/www/unlimitsky}"
SRC="${2:-}"

if [ "$EUID" -ne 0 ]; then
    echo "Run as root."
    exit 1
fi

if [ -z "$SRC" ] || [ ! -d "$SRC/admin" ]; then
    echo "Usage: $0 WEB_ROOT SOURCE_DIR" >&2
    echo "  SOURCE_DIR must contain admin/ (e.g. /opt/unlimitsky/client)" >&2
    exit 1
fi

if [ ! -f "$WEB_ROOT/config.php" ]; then
    echo "ERROR: $WEB_ROOT/config.php not found — is the panel installed?" >&2
    exit 1
fi

echo "[*] Updating panel files..."
echo "    from: $SRC"
echo "    to:   $WEB_ROOT"

rsync -a --exclude '.git' --exclude 'config.php' --exclude 'install/unlimitsky.install' \
    --exclude 'install/.db-provision.json' \
    --exclude 'admin/data/api-keys.json' --exclude 'admin/data/license.json' \
    "$SRC/" "$WEB_ROOT/"

chmod +x "$WEB_ROOT"/bin/*.sh "$WEB_ROOT"/bin/*.py 2>/dev/null || true
mkdir -p "$WEB_ROOT/data/backups/tmp" "$WEB_ROOT/data/settings"
chown -R www-data:www-data "$WEB_ROOT"
chmod -R 775 "$WEB_ROOT/admin/data" "$WEB_ROOT/data" 2>/dev/null || true

if declare -F usk_verify_panel_deploy >/dev/null 2>&1; then
    usk_verify_panel_deploy "$WEB_ROOT"
    usk_write_deploy_stamp "$WEB_ROOT" "$SRC"
    usk_ensure_php_zip
    usk_restart_php_fpm
else
    if declare -F usk_ensure_php_zip >/dev/null 2>&1; then
        usk_ensure_php_zip || true
    elif ! php -r 'exit(class_exists("ZipArchive")?0:1);' 2>/dev/null; then
        echo "[!] ZipArchive missing — install php zip manually if backup export fails."
    fi
    usk_restart_php_fpm 2>/dev/null || true
fi

echo "[*] Done. Open: ${WEB_ROOT%/}/admin/index.php?page=updates"
echo "    Backup page: ${WEB_ROOT%/}/admin/index.php?page=backup"
echo "    Health:      ${WEB_ROOT%/}/admin/check.php"
