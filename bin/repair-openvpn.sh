#!/bin/bash
# Fix OpenVPN NAT/routing on existing install
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/openvpn-common.sh"

UDP_PORT="${1:-1194}"
TCP_PORT="${2:-443}"

if [ -f /etc/openvpn/server-udp.conf ]; then
  UDP_PORT=$(grep -E '^port ' /etc/openvpn/server-udp.conf | awk '{print $2}' | head -1)
  TCP_PORT=$(grep -E '^port ' /etc/openvpn/server-tcp.conf 2>/dev/null | awk '{print $2}' | head -1)
elif [ -f /etc/openvpn/server.conf ]; then
  UDP_PORT=$(grep -E '^port ' /etc/openvpn/server.conf | awk '{print $2}' | head -1)
fi
TCP_PORT=${TCP_PORT:-443}

usk_openvpn_setup_nat

if [ -f /etc/openvpn/server-udp.conf ]; then
  usk_openvpn_enable_service "server-udp" || true
  usk_openvpn_enable_service "server-tcp" || true
elif [ -f /etc/openvpn/server.conf ]; then
  systemctl restart openvpn@server 2>/dev/null || true
fi

echo "USK_META:udp_port=${UDP_PORT};tcp_port=${TCP_PORT};port=${UDP_PORT}"
usk_ok
