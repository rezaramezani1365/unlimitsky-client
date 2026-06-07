#!/bin/bash
# Emergency: remove slot-limit iptables + restore Xray (run after broken usage sync).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

port=""
[ -f "$XRAY_CFG" ] && port=$(usk_xray_vless_port_from_config "$XRAY_CFG" 2>/dev/null || true)
echo "[*] Clearing USK_XRAY_CONN iptables rules..."
usk_xray_clear_slot_iptables "${port:-443}"

if [ -f "$XRAY_CFG" ]; then
  echo "[*] Restoring Xray config + restart..."
  exec "$DIR/xray-restore-runtime.sh"
fi

echo "USK_OK: iptables_cleared (no xray config found)"
exit 0
