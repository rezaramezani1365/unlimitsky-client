#!/bin/bash
# Install PHP ZipArchive extension (Ubuntu/Debian).
# Runs in background from admin panel to avoid nginx 502 (php-fpm restart mid-request).
set -euo pipefail

WEB_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
LIB="$WEB_ROOT/install/lib.sh"
STATUS_FILE="$WEB_ROOT/data/settings/php-zip-install.json"
LOG_FILE="$WEB_ROOT/data/settings/php-zip-install.log"

write_status() {
    local state="$1"
    local message="$2"
    mkdir -p "$(dirname "$STATUS_FILE")"
    printf '{"state":"%s","message":"%s","at":"%s"}\n' \
        "$state" "$(echo "$message" | sed 's/"/\\"/g')" "$(date -Iseconds)" > "$STATUS_FILE"
    chmod 664 "$STATUS_FILE" 2>/dev/null || true
    chown www-data:www-data "$STATUS_FILE" 2>/dev/null || true
}

if [ ! -f "$LIB" ]; then
    echo "USK_ERR: missing install/lib.sh"
    write_status failed "missing install/lib.sh"
    exit 1
fi

# shellcheck source=/dev/null
source "$LIB"

mkdir -p "$(dirname "$LOG_FILE")"
: >> "$LOG_FILE"

exec >> "$LOG_FILE" 2>&1
echo "=== php-zip install $(date -Iseconds) ==="

write_status running "apt_install"

if usk_zip_cli_ok; then
    echo "USK_OK: ZipArchive already available"
    write_status ok "already_installed"
    exit 0
fi

if ! usk_ensure_php_zip; then
    echo "USK_ERR: usk_ensure_php_zip failed"
    write_status failed "apt_install_failed"
    exit 1
fi

# Restart FPM after install — safe when this script runs in background (not inside a web request).
usk_restart_php_fpm
sleep 2

if usk_zip_cli_ok; then
    echo "USK_OK: ZipArchive installed"
    write_status ok "installed"
    exit 0
fi

echo "USK_ERR: ZipArchive still missing after apt install"
write_status failed "zip_still_missing"
exit 1
