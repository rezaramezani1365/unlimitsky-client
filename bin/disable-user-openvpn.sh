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
  if [ -f pki/crl.pem ]; then
    cp pki/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
    for cfg in /etc/openvpn/server-udp.conf /etc/openvpn/server-tcp.conf /etc/openvpn/server.conf; do
      [ -f "$cfg" ] || continue
      grep -q '^crl-verify' "$cfg" || echo "crl-verify /etc/openvpn/easy-rsa/pki/crl.pem" >> "$cfg"
      sed -i 's|crl-verify.*|crl-verify /etc/openvpn/easy-rsa/pki/crl.pem|' "$cfg" 2>/dev/null || true
      name=$(basename "$cfg" .conf)
      systemctl restart "openvpn@${name}" 2>/dev/null || true
    done
  fi
fi

echo "USK_OK"
exit 0
