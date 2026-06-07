#!/bin/bash
# Emergency: stop all background sync load on a overloaded VPS.
set -uo pipefail

WEB_ROOT="${1:-/var/www/unlimitsky}"

echo "[*] Stopping live-stats systemd (if any)..."
systemctl stop unlimitsky-live-stats.service 2>/dev/null || true
systemctl disable unlimitsky-live-stats.service 2>/dev/null || true
rm -f /etc/systemd/system/unlimitsky-live-stats.service 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

echo "[*] Removing per-minute cron jobs (will be replaced on panel update)..."
rm -f /etc/cron.d/unlimitsky-connections 2>/dev/null || true

echo "[*] Killing orphan sync/collect processes..."
pkill -f 'live-stats-daemon.sh' 2>/dev/null || true
pkill -f 'live-stats-worker.php' 2>/dev/null || true
pkill -f 'cron/native-limits.php' 2>/dev/null || true
pkill -f 'cron/enforce-connections.php' 2>/dev/null || true
pkill -f 'collect-usage-stats.sh' 2>/dev/null || true

rm -f "${WEB_ROOT}/data/live/"*.lock /var/run/unlimitsky-limits.lock 2>/dev/null || true

echo "[*] Done. Restart PHP-FPM if panel still slow:"
echo "    sudo systemctl restart php8.2-fpm nginx"
echo "    sudo bash ${WEB_ROOT}/scripts/panel-self-update.sh"
