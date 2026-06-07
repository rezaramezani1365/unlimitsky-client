#!/bin/bash
# Run after Xray protocol reinstall — restore all panel UUIDs + refresh links + fix routing.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh"
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
echo "[1/6] Clear slot-limit iptables..."
usk_xray_clear_slot_iptables "${port:-443}"

echo "[2/6] Preserve Reality keys (do not regenerate)..."
usk_xray_ensure_reality_params || { echo "USK_ERR: reality_keys_missing" >&2; exit 1; }

echo "[3/6] Force-sync all panel UUIDs into Xray config..."
if ! usk_xray_force_sync_panel_clients "$XRAY_CFG" "$PANEL_ROOT"; then
  echo "USK_WARN: panel_sync_failed — merging from config file" >&2
  clients=$(usk_xray_collect_all_clients_json "$XRAY_CFG" "$PANEL_ROOT" 2>/dev/null || echo '[]')
  if [ "$clients" != "[]" ] && [ -n "$clients" ]; then
    usk_xray_write_config "$XRAY_CFG" "$clients" "${port:-443}" || true
  fi
fi
usk_xray_ensure_stats_policy "$XRAY_CFG" || true

echo "[4/6] Test + restart Xray..."
usk_xray_test_config "$XRAY_CFG" || { echo "USK_ERR: xray_config_test_failed" >&2; exit 1; }
usk_xray_service_restart || { echo "USK_ERR: xray_restart_failed" >&2; exit 1; }

echo "[5/6] Refresh stored VLESS links in panel..."
links=$(usk_xray_refresh_stored_links "$PANEL_ROOT" 2>/dev/null || echo 0)

cfg_n=$(jq '[.inbounds[]?|select(.protocol=="vless")|.settings.clients[]?]|length' "$XRAY_CFG" 2>/dev/null || echo 0)
panel_n=0
[ -f "${PANEL_ROOT}/data/clients/xray.json" ] && panel_n=$(jq '[.[]?|select((.status//"active")=="active")]|length' "${PANEL_ROOT}/data/clients/xray.json" 2>/dev/null || echo 0)

echo "[6/6] Summary"
echo "      port=${port} reality_keys=kept config_clients=${cfg_n} panel_active=${panel_n} links_refreshed=${links}"
echo "USK_OK: xray_post_reinstall_sync"
echo "USK_NOTE: Users must delete OLD profile in Hiddify and import NEW link from customer portal."
exit 0
