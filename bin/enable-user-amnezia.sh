#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/amnezia-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PUBKEY="${2:-}"
CLIENT_IP="${3:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

REGISTRY="$DATA_ROOT/amnezia/clients.json"
if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
  if [ -z "$PUBKEY" ] || [ "$PUBKEY" = "null" ]; then
    PUBKEY=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .public_key' "$REGISTRY" | head -1)
  fi
  if [ -z "$CLIENT_IP" ] || [ "$CLIENT_IP" = "null" ]; then
    CLIENT_IP=$(jq -r --arg u "$USERNAME" '.[] | select(.username==$u) | .ip' "$REGISTRY" | head -1)
  fi
fi

if usk_amnezia_bivlked; then
  safe_name=$(echo "$USERNAME" | tr -c 'a-zA-Z0-9_-' '_')
  bash "$BIVLKED_MGMT" add "$safe_name" 2>/dev/null || true
elif [ -n "$PUBKEY" ] && [ -n "$CLIENT_IP" ]; then
  awg=$(usk_amnezia_awg_bin) || true
  if [ -n "$awg" ]; then
    $awg set awg0 peer "$PUBKEY" allowed-ips "${CLIENT_IP}/32" 2>/dev/null || true
    if [ -f "$AMNEZIA_CONF" ] && ! grep -q "$PUBKEY" "$AMNEZIA_CONF" 2>/dev/null; then
      cat >> "$AMNEZIA_CONF" <<PEER

[Peer]
# $USERNAME
PublicKey = $PUBKEY
AllowedIPs = ${CLIENT_IP}/32
PEER
    fi
    usk_amnezia_apply_conf
  fi
fi

echo "USK_OK"
exit 0
