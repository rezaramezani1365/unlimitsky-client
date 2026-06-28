#!/bin/bash
# Fix "connected but timeout / no internet" — clears slot-limit iptables + rebuilds clean Xray routing.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

[ -f "$XRAY_CFG" ] || { echo "USK_ERR: xray_config_missing" >&2; exit 1; }

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
cp "$XRAY_CFG" "${XRAY_CFG}.bak.$(date +%s)" 2>/dev/null || true

port=$(usk_xray_vless_port_from_config "$XRAY_CFG" 2>/dev/null || echo 443)
clients=$(usk_xray_load_clients "$XRAY_CFG")

echo "[1/5] Clearing connection-limit iptables (USK_XRAY_CONN)..."
usk_xray_clear_slot_iptables "${port:-443}"
if command -v iptables >/dev/null 2>&1; then
  echo "      INPUT jump rules: $(iptables -S INPUT 2>/dev/null | grep -c USK_XRAY_CONN || echo 0)"
fi

echo "[2/5] Rebuilding Xray config (clients=${clients:-[]})..."
if [ "$clients" != "[]" ] && [ -n "$clients" ]; then
  usk_xray_rewrite_from_clients "$XRAY_CFG" || {
    echo "USK_WARN: full rewrite failed, patching in place" >&2
    usk_xray_strip_bad_routing "$XRAY_CFG" || true
    usk_xray_ensure_stats_policy "$XRAY_CFG" || { echo "USK_ERR: stats_policy_failed" >&2; exit 1; }
  }
else
  usk_xray_strip_bad_routing "$XRAY_CFG" || true
  usk_xray_ensure_stats_policy "$XRAY_CFG" || { echo "USK_ERR: stats_policy_failed" >&2; exit 1; }
fi

echo "[3/5] Merging panel clients (if any)..."
usk_xray_rebuild_clients_in_config "$XRAY_CFG" "$PANEL_ROOT" 2>/dev/null || true
usk_xray_ensure_stats_policy "$XRAY_CFG" || true

echo "[4/5] Testing config..."
usk_xray_test_config "$XRAY_CFG" || { echo "USK_ERR: xray_config_test_failed" >&2; exit 1; }

echo "[5/5] Restarting xray..."
if ! usk_xray_service_restart; then
  echo "USK_ERR: xray_restart_failed" >&2
  exit 1
fi

source "$DIR/provision-common.sh" 2>/dev/null || true
usk_xray_refresh_stored_links "$PANEL_ROOT" 2>/dev/null || true

n=$(jq '[.inbounds[]?|select(.protocol=="vless")|.settings.clients[]?]|length' "$XRAY_CFG" 2>/dev/null || echo 0)
p=$(jq -r '(.inbounds[]?|select(.protocol=="vless")|.port)//443' "$XRAY_CFG" 2>/dev/null | head -1)
echo "USK_OK: xray_connectivity_fixed port=${p} clients=${n}"
echo "       outbounds: $(jq -r '[.outbounds[]?.tag]|join(",")' "$XRAY_CFG" 2>/dev/null)"
echo "       routing:   $(jq -c '.routing.rules' "$XRAY_CFG" 2>/dev/null)"
exit 0
