#!/bin/bash
# Event-driven Xray slot check — call when access log shows a new accepted connection.
# Rejects NEW source IP when max_connections slots are full (existing IPs keep working).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/connection-slots-common.sh" 2>/dev/null || true

EMAIL="${1:-}"
IP="${2:-}"

if [ "$EUID" -ne 0 ]; then
  echo '{"ok":false,"error":"run_as_root"}'
  exit 1
fi

if [ -z "$EMAIL" ] || [ -z "$IP" ]; then
  exit 0
fi

if ! echo "$IP" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
  exit 0
fi
[ "$IP" = "127.0.0.1" ] && exit 0

if ! usk_slots_client_active xray "$EMAIL"; then
  exit 0
fi

command -v jq >/dev/null 2>&1 || exit 0

MAX=$(usk_slots_load_max xray "$EMAIL")
FRESH=$(usk_slots_fresh_ips_json "$EMAIL")
COUNT=$(echo "$FRESH" | jq 'length' 2>/dev/null || echo 0)

ALREADY=$(echo "$FRESH" | jq -r --arg ip "$IP" 'map(.ip) | index($ip) // empty' 2>/dev/null || true)
if [ -n "$ALREADY" ] && [ "$ALREADY" != "null" ]; then
  usk_slots_register_ip "$EMAIL" "$IP"
  exit 0
fi

if [ "${COUNT:-0}" -lt "$MAX" ] 2>/dev/null; then
  usk_slots_register_ip "$EMAIL" "$IP"
  exit 0
fi

# Slots full — reject this NEW IP (no internet / timeout for them).
PORT=$(usk_xray_vless_port_from_config "${XRAY_CFG:-}" 2>/dev/null || echo 443)
PORT=${PORT:-443}
usk_xray_slot_chain_reset "$PORT" 2>/dev/null || true
usk_xray_reject_ip_on_port "$IP" "$PORT" 2>/dev/null || true

LOG="${DATA_ROOT:-/var/lib/unlimitsky}/xray/iplimit.log"
mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
printf '[SLOT_REJECT] Email = %s || Rejected NEW IP = %s || Max = %s\n' \
  "$EMAIL" "$IP" "$MAX" >>"$LOG" 2>/dev/null || true

exit 0
