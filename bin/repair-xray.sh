#!/bin/bash
# Repair / migrate Xray to VLESS + Reality
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/xray-common.sh"

VLESS_PORT="${1:-443}"
VLESS_PORT=$(echo "$VLESS_PORT" | tr -dc '0-9')
[ -n "$VLESS_PORT" ] && [ "$VLESS_PORT" -ge 1 ] && [ "$VLESS_PORT" -le 65535 ] 2>/dev/null || VLESS_PORT=443

UUID=$(cat /proc/sys/kernel/random/uuid)
mkdir -p /usr/local/etc/xray

if [ -f "$XRAY_CFG" ]; then
  cp "$XRAY_CFG" "${XRAY_CFG}.bak.$(date +%s)" 2>/dev/null || true
fi

EXISTING_VLESS=$(usk_xray_load_clients "$XRAY_CFG")
if [ "$EXISTING_VLESS" = "[]" ] || [ "$EXISTING_VLESS" = "null" ]; then
  EXISTING_VLESS="[{\"id\":\"$UUID\",\"email\":\"bootstrap\",\"flow\":\"xtls-rprx-vision\"}]"
fi

usk_xray_ensure_reality_params || usk_fail "xray_reality_keygen_failed"
usk_xray_migrate_legacy_config "$XRAY_CFG" 2>/dev/null || true

if ! usk_xray_write_config "$XRAY_CFG" "$EXISTING_VLESS" "$VLESS_PORT"; then
  usk_fail "xray_config_json_failed"
fi

usk_xray_test_config "$XRAY_CFG" || usk_fail "xray_config_test_failed"

systemctl enable xray 2>/dev/null || true
usk_xray_open_firewall "$VLESS_PORT" "xray-vless-reality"
usk_xray_verify_or_fail "$XRAY_CFG" || exit 1

# shellcheck disable=SC1090
. "$USK_XRAY_REALITY_FILE"
echo "USK_META:vless_port=${VLESS_PORT};reality=1;sni=${REALITY_SNI:-www.microsoft.com}"
usk_ok
