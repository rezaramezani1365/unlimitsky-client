#!/bin/bash
# Background panel update worker (avoids nginx 502 from php-fpm restart mid-request)
set -euo pipefail

WEB_ROOT="${1:-/var/www/unlimitsky}"
UPDATE_SCRIPT="${2:-}"
LOG_FILE="${3:-}"

if [ -z "$UPDATE_SCRIPT" ] || [ ! -f "$UPDATE_SCRIPT" ]; then
    echo "USK_ERR: update_script_missing" >&2
    exit 1
fi

if [ -n "$LOG_FILE" ]; then
    exec >>"$LOG_FILE" 2>&1
    echo "=== panel update started $(date -Iseconds) ==="
fi

if bash "$UPDATE_SCRIPT" "$WEB_ROOT"; then
    echo "USK_OK panel update complete $(date -Iseconds)"
    exit 0
fi

echo "USK_ERR: panel_update_failed $(date -Iseconds)"
exit 1
