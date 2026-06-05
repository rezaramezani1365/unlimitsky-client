#!/bin/bash
# Revoke OpenVPN cert — blocks connection, files kept for audit
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
  if [ -f pki/crl.pem ] && [ -f /etc/openvpn/server.conf ]; then
    grep -q '^crl-verify' /etc/openvpn/server.conf || echo "crl-verify /etc/openvpn/easy-rsa/pki/crl.pem" >> /etc/openvpn/server.conf
    cp pki/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
    sed -i 's|crl-verify.*|crl-verify /etc/openvpn/easy-rsa/pki/crl.pem|' /etc/openvpn/server.conf 2>/dev/null || true
  fi
  systemctl restart openvpn@server 2>/dev/null || true
fi

echo "USK_OK"
exit 0
