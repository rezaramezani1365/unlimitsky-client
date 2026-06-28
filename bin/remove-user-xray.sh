#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
UUID="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

if [ -f "$XRAY_CFG" ] && command -v jq >/dev/null 2>&1; then
  if [ -z "$UUID" ] || [ "$UUID" = "null" ]; then
    CLIENTS="$DATA_ROOT/xray/clients.json"
    if [ -f "$CLIENTS" ]; then
      UUID=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .uuid' "$CLIENTS" | head -1)
    fi
  fi
  if [ -n "$UUID" ] && [ "$UUID" != "null" ]; then
    usk_xray_remove_client "$XRAY_CFG" "$UUID" || true
    usk_xray_service_restart || true
  fi
fi

REGISTRY="$DATA_ROOT/xray/clients.json"
if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" '[.[] | select(.username != $u)]' "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
fi

safe_user=$(echo "$USERNAME" | tr -c 'a-zA-Z0-9_-' '_')
rm -f "$DATA_ROOT/xray/profiles/${safe_user}.json" 2>/dev/null || true

echo "USK_OK"
exit 0
