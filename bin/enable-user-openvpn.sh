#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"

EASYRSA="/etc/openvpn/easy-rsa"
if [ -d "$EASYRSA/pki" ]; then
  cd "$EASYRSA"
  rm -f "pki/issued/${USERNAME}.crt" "pki/private/${USERNAME}.key" 2>/dev/null || true
fi

bash "$DIR/add-user-openvpn.sh" "$USERNAME" "0" "0"
exit $?
