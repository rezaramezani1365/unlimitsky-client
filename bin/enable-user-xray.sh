#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

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
  '.inbounds[0].settings.clients = ((.inbounds[0].settings.clients // []) | map(del(.flow)) + [{"id":$id,"email":$email}]) |
   .inbounds[1].settings.clients = ((.inbounds[1].settings.clients // []) + [{"id":$id,"email":$email}])' \
  "$XRAY_CFG" > "$tmp" && mv "$tmp" "$XRAY_CFG"
usk_xray_fix_perms "$XRAY_CFG"
usk_xray_service_restart || true

echo "USK_OK"
exit 0
