#!/bin/bash
# Re-enable WireGuard peer after extend/renew
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
PUBKEY="${2:-}"
CLIENT_IP="${3:-}"
[ -n "$USERNAME" ] || usk_json_fail "username_required"
[ -n "$PUBKEY" ] || usk_json_fail "public_key_required"
[ -n "$CLIENT_IP" ] || usk_json_fail "client_ip_required"

wg set wg0 peer "$PUBKEY" allowed-ips "${CLIENT_IP}/32" 2>/dev/null || true

if [ -f /etc/wireguard/wg0.conf ] && ! grep -q "$PUBKEY" /etc/wireguard/wg0.conf; then
  cat >> /etc/wireguard/wg0.conf <<PEER

[Peer]
# $USERNAME
PublicKey = $PUBKEY
AllowedIPs = ${CLIENT_IP}/32
PEER
fi

echo "USK_OK"
exit 0
