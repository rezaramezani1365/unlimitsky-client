#!/bin/bash
# Background live stats loop — keeps usage/connections fresh for portal + admin UI.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT="${WEB_ROOT:-$(dirname "$DIR")}"
INTERVAL="${USK_LIVE_STATS_INTERVAL:-5}"
PHP_BIN="${PHP_BIN:-$(command -v php 2>/dev/null || echo php)}"
WORKER="${WEB_ROOT}/cron/live-stats-worker.php"
LOG="${WEB_ROOT}/data/live/daemon.log"

mkdir -p "${WEB_ROOT}/data/live" 2>/dev/null || true
touch "$LOG" 2>/dev/null || true

echo "[$(date -Is)] unlimitsky live-stats daemon started interval=${INTERVAL}s" >>"$LOG"

while true; do
  if [ -f "$WORKER" ]; then
    "$PHP_BIN" "$WORKER" >>"$LOG" 2>&1 || true
  fi
  sleep "$INTERVAL"
done
