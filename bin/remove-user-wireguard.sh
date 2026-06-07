#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PUB="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

REGISTRY="$DATA_ROOT/wireguard/clients.json"
if [ -z "$PUB" ] || [ "$PUB" = "null" ]; then
  if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
    PUB=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .public_key' "$REGISTRY" | head -1)
  fi
fi
if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" '[.[] | select(.username != $u)]' "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
fi

if [ -n "$PUB" ] && [ "$PUB" != "null" ]; then
  wg set wg0 peer "$PUB" remove 2>/dev/null || true
  sed -i "/# $USERNAME/,/^$/d" /etc/wireguard/wg0.conf 2>/dev/null || true
  sed -i "/PublicKey = $PUB/,+2d" /etc/wireguard/wg0.conf 2>/dev/null || true
fi

echo "USK_OK"
exit 0
