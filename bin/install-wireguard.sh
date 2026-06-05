#!/bin/bash
# Install WireGuard on Ubuntu (+ optional TCP bridge for filtered networks)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/wireguard-common.sh"
set +e

PORT="${1:-51820}"
TCP_PORT="${2:-51822}"
PORT=$(echo "$PORT" | tr -dc '0-9')
TCP_PORT=$(echo "$TCP_PORT" | tr -dc '0-9')
if [ -z "$PORT" ] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ] 2>/dev/null; then
  PORT=51820
fi
if [ -z "$TCP_PORT" ] || [ "$TCP_PORT" -lt 1 ] || [ "$TCP_PORT" -gt 65535 ] 2>/dev/null; then
  TCP_PORT=51822
fi

MAIN_IFACE=$(usk_wg_main_iface)
MAIN_IFACE="${MAIN_IFACE:-eth0}"

apt-get update -qq
apt-get install -y wireguard qrencode

mkdir -p /etc/wireguard
if [ ! -f /etc/wireguard/wg0.conf ]; then
  umask 077
  wg genkey | tee /etc/wireguard/server_private.key | wg pubkey > /etc/wireguard/server_public.key
  PRIV=$(cat /etc/wireguard/server_private.key)
  cat > /etc/wireguard/wg0.conf <<EOF
[Interface]
Address = 10.8.0.1/24
ListenPort = ${PORT}
PrivateKey = $PRIV
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${MAIN_IFACE} -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${MAIN_IFACE} -j MASQUERADE
EOF
elif grep -q '^ListenPort' /etc/wireguard/wg0.conf; then
  sed -i "s/^ListenPort = .*/ListenPort = ${PORT}/" /etc/wireguard/wg0.conf
fi

sysctl -w net.ipv4.ip_forward=1
grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

systemctl enable wg-quick@wg0
systemctl restart wg-quick@wg0 || systemctl start wg-quick@wg0

ensure_ufw_port "$PORT" udp wireguard-udp

BRIDGE_OK=0
if usk_wg_setup_tcp_bridge "$PORT" "$TCP_PORT"; then
  BRIDGE_OK=1
fi

echo "USK_META:port=${PORT};tcp_port=${TCP_PORT}"
if [ "$BRIDGE_OK" -ne 1 ]; then
  echo "USK_WARN:wireguard_tcp_bridge"
fi
usk_ok
