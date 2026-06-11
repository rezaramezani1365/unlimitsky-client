#!/bin/bash
# Repair / migrate Xray to VLESS + Reality
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/xray-common.sh"

VLESS_PORT="${1:-443}"
VLESS_PORT=$(echo "$VLESS_PORT" | tr -dc '0-9')
[ -n "$VLESS_PORT" ] && [ "$VLESS_PORT" -ge 1 ] && [ "$VLESS_PORT" -le 65535 ] 2>/dev/null || VLESS_PORT=443

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
if [ -f "$XRAY_CFG" ]; then
  EXISTING_PORT=$(usk_xray_vless_port_from_config "$XRAY_CFG" 2>/dev/null || true)
  if [ -n "$EXISTING_PORT" ] && [ "$EXISTING_PORT" -ge 1 ] 2>/dev/null; then
    VLESS_PORT="$EXISTING_PORT"
  fi
fi

UUID=$(cat /proc/sys/kernel/random/uuid)
mkdir -p /usr/local/etc/xray

if [ -f "$XRAY_CFG" ]; then
  cp "$XRAY_CFG" "${XRAY_CFG}.bak.$(date +%s)" 2>/dev/null || true
  usk_xray_dedupe_config_clients "$XRAY_CFG" 2>/dev/null || true
fi

EXISTING_VLESS=$(usk_xray_collect_all_clients_json "$XRAY_CFG" "$PANEL_ROOT" 2>/dev/null || usk_xray_load_clients "$XRAY_CFG")
if [ "$EXISTING_VLESS" = "[]" ] || [ "$EXISTING_VLESS" = "null" ]; then
  EXISTING_VLESS="[{\"id\":\"$UUID\",\"email\":\"bootstrap\",\"flow\":\"xtls-rprx-vision\"}]"
fi
EXISTING_VLESS=$(usk_xray_normalize_clients "$EXISTING_VLESS")

usk_xray_ensure_reality_params || usk_fail "xray_reality_keygen_failed"
usk_xray_migrate_legacy_config "$XRAY_CFG" 2>/dev/null || true

if ! usk_xray_write_config "$XRAY_CFG" "$EXISTING_VLESS" "$VLESS_PORT"; then
  usk_fail "xray_config_json_failed"
fi

usk_xray_test_config "$XRAY_CFG" || usk_fail "xray_config_test_failed"

systemctl enable xray 2>/dev/null || true
usk_xray_open_firewall "$VLESS_PORT" "xray-vless-reality"
usk_xray_verify_or_fail "$XRAY_CFG" || exit 1

usk_xray_ensure_stats_policy "$XRAY_CFG" 2>/dev/null || true
usk_xray_rebuild_clients_in_config "$XRAY_CFG" "$PANEL_ROOT" 1 2>/dev/null || true
usk_xray_dedupe_config_clients "$XRAY_CFG" 2>/dev/null || true
source "$DIR/provision-common.sh" 2>/dev/null || true
usk_xray_refresh_stored_links "$PANEL_ROOT" 2>/dev/null || true
usk_xray_test_config "$XRAY_CFG" 2>/dev/null || usk_fail "xray_config_test_failed"
usk_xray_service_restart || usk_fail "xray_restart_failed"
usk_xray_verify_stats_api 2>/dev/null || echo "USK_WARN: stats_api_check_failed run: sudo bash bin/xray-fix-stats-api.sh" >&2

# shellcheck disable=SC1090
. "$USK_XRAY_REALITY_FILE"
echo "USK_META:vless_port=${VLESS_PORT};reality=1;sni=${REALITY_SNI:-www.microsoft.com}"
usk_ok
