#!/bin/bash
# Emergency: stop live-stats daemon/workers that overload CPU/RAM.
set -uo pipefail

echo "[*] Stopping unlimitsky-live-stats service..."
systemctl stop unlimitsky-live-stats.service 2>/dev/null || true
systemctl disable unlimitsky-live-stats.service 2>/dev/null || true

echo "[*] Killing orphan live-stats / worker PHP processes..."
pkill -f 'live-stats-daemon.sh' 2>/dev/null || true
pkill -f 'live-stats-worker.php' 2>/dev/null || true

WEB_ROOT="${1:-/var/www/unlimitsky}"
if [ -d "$WEB_ROOT/data/live" ]; then
    rm -f "$WEB_ROOT/data/live/worker.lock" "$WEB_ROOT/data/live/sync.lock" 2>/dev/null || true
fi

echo "[*] Done. Panel should recover within a minute."
echo "    Then run: sudo bash $WEB_ROOT/scripts/panel-self-update.sh"
