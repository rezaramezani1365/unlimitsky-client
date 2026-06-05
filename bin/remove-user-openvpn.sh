#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

EASYRSA="/etc/openvpn/easy-rsa"
if [ -d "$EASYRSA/pki" ] && [ -f "$EASYRSA/pki/issued/${USERNAME}.crt" ]; then
  cd "$EASYRSA"
  ./easyrsa --batch revoke "$USERNAME" 2>/dev/null || true
  ./easyrsa gen-crl 2>/dev/null || true
  rm -f "pki/issued/${USERNAME}.crt" "pki/private/${USERNAME}.key" 2>/dev/null || true
fi

REGISTRY="$DATA_ROOT/openvpn/clients.json"
if [ -f "$REGISTRY" ] && command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" '[.[] | select(.username != $u)]' "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
fi

echo "USK_OK"
exit 0
