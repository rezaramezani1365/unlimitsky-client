#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/amnezia-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PUBKEY="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

if [ -z "$PUBKEY" ] || [ "$PUBKEY" = "null" ]; then
  REGISTRY="$DATA_ROOT/amnezia/clients.json"
  if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
    PUBKEY=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .public_key' "$REGISTRY" | head -1)
  fi
fi

if usk_amnezia_bivlked; then
  safe_name=$(echo "$USERNAME" | tr -c 'a-zA-Z0-9_-' '_')
  bash "$BIVLKED_MGMT" remove "$safe_name" 2>/dev/null || true
elif [ -n "$PUBKEY" ] && [ "$PUBKEY" != "null" ]; then
  awg=$(usk_amnezia_awg_bin) || true
  [ -n "$awg" ] && $awg set awg0 peer "$PUBKEY" remove 2>/dev/null || true
  if [ -f "$AMNEZIA_CONF" ]; then
    sed -i "/# $USERNAME$/,/^$/d" "$AMNEZIA_CONF" 2>/dev/null || true
  fi
  usk_amnezia_apply_conf
fi

echo "USK_OK"
exit 0
