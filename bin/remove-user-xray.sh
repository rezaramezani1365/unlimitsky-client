#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
UUID="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

XRAY_CFG="/usr/local/etc/xray/config.json"
if [ -f "$XRAY_CFG" ] && command -v jq >/dev/null 2>&1; then
  if [ -z "$UUID" ] || [ "$UUID" = "null" ]; then
    CLIENTS="$DATA_ROOT/xray/clients.json"
    if [ -f "$CLIENTS" ]; then
      UUID=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .uuid' "$CLIENTS" | head -1)
    fi
  fi
  if [ -n "$UUID" ] && [ "$UUID" != "null" ]; then
    tmp=$(mktemp)
    jq --arg id "$UUID" \
      '.inbounds[0].settings.clients = [.inbounds[0].settings.clients[]? | select(.id != $id)] |
       .inbounds[1].settings.clients = [.inbounds[1].settings.clients[]? | select(.id != $id)]' \
      "$XRAY_CFG" > "$tmp" && mv "$tmp" "$XRAY_CFG"
    usk_xray_fix_perms "$XRAY_CFG"
    usk_xray_service_restart || true
  fi
fi

REGISTRY="$DATA_ROOT/xray/clients.json"
if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" '[.[] | select(.username != $u)]' "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
fi

echo "USK_OK"
exit 0
