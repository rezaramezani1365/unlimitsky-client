#!/bin/bash
# Repair broken Xray config (permissions, invalid JSON, Xray 26.x)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/xray-common.sh"

VLESS_PORT="${1:-2053}"
VMESS_PORT="${2:-8443}"
VLESS_PORT=$(echo "$VLESS_PORT" | tr -dc '0-9')
VMESS_PORT=$(echo "$VMESS_PORT" | tr -dc '0-9')
[ -n "$VLESS_PORT" ] && [ "$VLESS_PORT" -ge 1 ] && [ "$VLESS_PORT" -le 65535 ] 2>/dev/null || VLESS_PORT=2053
[ -n "$VMESS_PORT" ] && [ "$VMESS_PORT" -ge 1 ] && [ "$VMESS_PORT" -le 65535 ] 2>/dev/null || VMESS_PORT=8443

if [ "$VLESS_PORT" = "$VMESS_PORT" ]; then
  VMESS_PORT=$((VLESS_PORT + 1))
fi

UUID=$(cat /proc/sys/kernel/random/uuid)
mkdir -p /usr/local/etc/xray

if [ -f "$XRAY_CFG" ]; then
  cp "$XRAY_CFG" "${XRAY_CFG}.bak.$(date +%s)" 2>/dev/null || true
fi

EXISTING_VLESS=$(usk_xray_load_clients "$XRAY_CFG" "vless")
EXISTING_VMESS=$(usk_xray_load_clients "$XRAY_CFG" "vmess")

if [ "$EXISTING_VLESS" = "[]" ] || [ "$EXISTING_VLESS" = "null" ]; then
  EXISTING_VLESS="[{\"id\":\"$UUID\",\"email\":\"bootstrap\"}]"
fi
if [ "$EXISTING_VMESS" = "[]" ] || [ "$EXISTING_VMESS" = "null" ]; then
  EXISTING_VMESS="[{\"id\":\"$UUID\",\"email\":\"bootstrap\"}]"
fi

usk_xray_write_config "$XRAY_CFG" "$EXISTING_VLESS" "$EXISTING_VMESS" "$VLESS_PORT" "$VMESS_PORT" \
  || usk_fail "xray_config_json_failed"

usk_xray_test_config "$XRAY_CFG" || usk_fail "xray_config_test_failed"

systemctl enable xray 2>/dev/null || true
usk_xray_open_firewall "$VLESS_PORT" "xray-vless"
usk_xray_open_firewall "$VMESS_PORT" "xray-vmess"
usk_xray_verify_or_fail "$XRAY_CFG" || exit 1

echo "USK_META:vless_port=${VLESS_PORT};vmess_port=${VMESS_PORT}"
usk_ok
