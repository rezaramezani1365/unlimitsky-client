#!/bin/bash
# Disable WireGuard peer — user cannot connect; record kept in panel DB
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/wireguard-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PUBKEY="${2:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

if [ -z "$PUBKEY" ] || [ "$PUBKEY" = "null" ]; then
  REGISTRY="$DATA_ROOT/wireguard/clients.json"
  if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
    PUBKEY=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .public_key' "$REGISTRY" | head -1)
  fi
fi

if [ -n "$PUBKEY" ] && [ "$PUBKEY" != "null" ]; then
  wg set wg0 peer "$PUBKEY" remove 2>/dev/null || true
fi
usk_wg_remove_peer_from_conf "$USERNAME" "$PUBKEY"

echo "USK_OK"
exit 0
