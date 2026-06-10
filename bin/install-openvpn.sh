#!/bin/bash
# Install OpenVPN (UDP + TCP) on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/openvpn-common.sh"

UDP_PORT="${1:-1194}"
TCP_PORT="${2:-443}"
UDP_PORT=$(echo "$UDP_PORT" | tr -dc '0-9')
TCP_PORT=$(echo "$TCP_PORT" | tr -dc '0-9')
[ -n "$UDP_PORT" ] && [ "$UDP_PORT" -ge 1 ] && [ "$UDP_PORT" -le 65535 ] 2>/dev/null || UDP_PORT=1194
[ -n "$TCP_PORT" ] && [ "$TCP_PORT" -ge 1 ] && [ "$TCP_PORT" -le 65535 ] 2>/dev/null || TCP_PORT=443

apt-get update -qq
apt-get install -y openvpn easy-rsa iptables-persistent 2>/dev/null || apt-get install -y openvpn easy-rsa

if [ ! -d /etc/openvpn/easy-rsa ]; then
  make-cadir /etc/openvpn/easy-rsa
fi

cd /etc/openvpn/easy-rsa
if [ ! -f pki/ca.crt ]; then
  ./easyrsa init-pki
  ./easyrsa --batch build-ca nopass
  ./easyrsa --batch build-server-full server nopass
  ./easyrsa gen-dh
fi

if [ -f /etc/openvpn/server.conf ] && [ ! -f /etc/openvpn/server-udp.conf ]; then
  mv /etc/openvpn/server.conf /etc/openvpn/server.conf.bak.legacy 2>/dev/null || true
  systemctl disable --now openvpn@server 2>/dev/null || true
fi

usk_openvpn_write_server "server-udp" "$UDP_PORT" "udp"
usk_openvpn_write_server "server-tcp" "$TCP_PORT" "tcp"
usk_openvpn_ensure_dev_names

usk_openvpn_setup_nat

usk_openvpn_enable_service "server-udp" || usk_fail "openvpn_udp_failed"
usk_openvpn_enable_service "server-tcp" || usk_fail "openvpn_tcp_failed"

ensure_ufw_port "$UDP_PORT" udp openvpn-udp
ensure_ufw_port "$TCP_PORT" tcp openvpn-tcp

echo "USK_META:udp_port=${UDP_PORT};tcp_port=${TCP_PORT};port=${UDP_PORT}"
usk_ok
