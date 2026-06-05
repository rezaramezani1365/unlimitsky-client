#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
UUID="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"
[ -n "$UUID" ] || usk_json_fail "uuid_required"

XRAY_CFG="/usr/local/etc/xray/config.json"
ensure_jq
command -v jq >/dev/null 2>&1 || usk_json_fail "jq_required"

tmp=$(mktemp)
jq --arg id "$UUID" --arg email "$USERNAME" \
  '.inbounds[0].settings.clients += [{"id":$id,"email":$email,"flow":"xtls-rprx-vision"}] |
   .inbounds[1].settings.clients += [{"id":$id,"email":$email,"alterId":0}]' \
  "$XRAY_CFG" > "$tmp" && mv "$tmp" "$XRAY_CFG"
systemctl restart xray 2>/dev/null || true

echo "USK_OK"
exit 0
