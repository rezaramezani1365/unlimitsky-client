#!/bin/bash
# Remove duplicate VLESS client emails from config.json (one-time fix / pre-test cleanup).
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/xray-common.sh"

[ -f "$XRAY_CFG" ] || usk_fail "xray_config_missing"

before=$(jq '[.inbounds[]?|select(.protocol=="vless")|.settings.clients[]?]|length' "$XRAY_CFG" 2>/dev/null || echo 0)
usk_xray_dedupe_config_clients "$XRAY_CFG" || usk_fail "xray_dedupe_failed"
after=$(jq '[.inbounds[]?|select(.protocol=="vless")|.settings.clients[]?]|length' "$XRAY_CFG" 2>/dev/null || echo 0)
echo "USK_INFO: xray_clients_before=${before} after=${after}"

if ! usk_xray_test_config "$XRAY_CFG"; then
  usk_fail "xray_config_test_failed"
fi
usk_ok
