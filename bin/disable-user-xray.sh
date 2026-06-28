#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
UUID="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

if [ ! -f "$XRAY_CFG" ]; then usk_json_fail "xray_not_installed"; fi

if [ -z "$UUID" ] || [ "$UUID" = "null" ]; then
  REGISTRY="$DATA_ROOT/xray/clients.json"
  if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
    UUID=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .uuid' "$REGISTRY" | head -1)
  fi
fi

ensure_jq
command -v jq >/dev/null 2>&1 || usk_json_fail "jq_required"

if [ -n "$UUID" ] && [ "$UUID" != "null" ]; then
  usk_xray_remove_client "$XRAY_CFG" "$UUID" || true
  usk_xray_service_restart || true
fi

echo "USK_OK"
exit 0
