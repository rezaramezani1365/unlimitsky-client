#!/bin/bash
# Enable Xray StatsService API on 127.0.0.1:10085 (existing installs).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/xray-common.sh"
# shellcheck disable=SC1091
source "$DIR/xray-stats-state.sh" 2>/dev/null || true

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

[ -f "$XRAY_CFG" ] || { echo "USK_ERR: xray_config_missing" >&2; exit 1; }

cp "$XRAY_CFG" "${XRAY_CFG}.bak.$(date +%s)" 2>/dev/null || true

usk_xray_ensure_stats_policy "$XRAY_CFG" || { echo "USK_ERR: stats_policy_failed" >&2; exit 1; }
usk_xray_fix_access_log_perms "$(usk_xray_access_log_path "$XRAY_CFG" 2>/dev/null || echo /var/lib/unlimitsky/xray/access.log)" 2>/dev/null || true
PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
usk_xray_rebuild_clients_in_config "$XRAY_CFG" "$PANEL_ROOT" 2>/dev/null || true
usk_xray_test_config "$XRAY_CFG" || { echo "USK_ERR: xray_config_test_failed" >&2; exit 1; }

if ! usk_xray_service_restart; then
  echo "USK_ERR: xray_restart_failed" >&2
  exit 1
fi

if usk_xray_verify_stats_api; then
  echo "USK_OK: xray_stats_api=127.0.0.1:10085"
  exit 0
fi

echo "USK_ERR: xray_stats_api_unreachable — check: ss -tlnp | grep 10085" >&2
exit 1
