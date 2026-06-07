#!/bin/bash
# Watches Xray access log — runs slot check only when a connection is accepted (no cron polling).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT="${WEB_ROOT:-$(dirname "$DIR")}"
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true

LOG="${WEB_ROOT}/data/live/xray-guard.log"
mkdir -p "${WEB_ROOT}/data/live" 2>/dev/null || true

access_log=$(usk_xray_access_log_path "${XRAY_CFG:-}" 2>/dev/null || true)
if [ -z "$access_log" ] || [ ! -r "$access_log" ]; then
  echo "[$(date -Is)] xray-connection-guard: no access log yet ($access_log)" >>"$LOG"
  sleep 30
  exec "$0"
fi

usk_xray_fix_access_log_perms 2>/dev/null || true
port=$(usk_xray_vless_port_from_config "${XRAY_CFG:-}" 2>/dev/null || echo 443)
usk_xray_slot_chain_reset "${port:-443}" 2>/dev/null || true

echo "[$(date -Is)] xray-connection-guard watching $access_log" >>"$LOG"

tail -n 0 -F "$access_log" 2>/dev/null | while IFS= read -r line; do
  case "$line" in
    *email:*|*accepted*)
      ;;
    *)
      continue
      ;;
  esac
  email=$(echo "$line" | awk '
    {
      for (i = 1; i <= NF; i++) {
        if ($i == "email:" && (i + 1) <= NF) { print $(i + 1); exit }
      }
    }')
  ip=$(echo "$line" | awk '
    {
      for (i = 1; i <= NF; i++) {
        if ($i == "from" && (i + 1) <= NF) {
          split($(i + 1), p, ":")
          gsub(/^\[/, "", p[1])
          print p[1]
          exit
        }
      }
    }')
  [ -n "$email" ] && [ -n "$ip" ] || continue
  case "$line" in
    *accepted*) ;;
    *) continue ;;
  esac
  bash "$DIR/xray-on-connect.sh" "$email" "$ip" >>"$LOG" 2>&1 || true
done
