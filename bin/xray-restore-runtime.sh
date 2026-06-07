#!/bin/bash
# Restore Xray routing + reload all active clients (fixes "connected but no internet").
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

usk_xray_ensure_stats_policy "$XRAY_CFG" || { echo "USK_ERR: stats_policy_failed" >&2; exit 1; }
usk_xray_rebuild_clients_in_config "$XRAY_CFG" "$PANEL_ROOT" || echo "USK_WARN: client_rebuild_skipped" >&2

usk_xray_test_config "$XRAY_CFG" || { echo "USK_ERR: xray_config_test_failed" >&2; exit 1; }

if ! usk_xray_service_restart; then
  echo "USK_ERR: xray_restart_failed" >&2
  exit 1
fi

if usk_xray_verify_stats_api; then
  echo "USK_OK: xray_restored clients=$(jq '[.inbounds[]?|select(.protocol=="vless")|.settings.clients[]?]|length' "$XRAY_CFG" 2>/dev/null || echo 0)"
  exit 0
fi

echo "USK_OK: xray_restarted stats_api_unverified"
exit 0
