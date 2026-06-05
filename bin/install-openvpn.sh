#!/bin/bash
# Install OpenVPN on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

apt-get update -qq
apt-get install -y openvpn easy-rsa

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

cat > /etc/openvpn/server.conf <<'OVPN'
port 1194
proto udp
dev tun
ca /etc/openvpn/easy-rsa/pki/ca.crt
cert /etc/openvpn/easy-rsa/pki/issued/server.crt
key /etc/openvpn/easy-rsa/pki/private/server.key
dh /etc/openvpn/easy-rsa/pki/dh.pem
server 10.9.0.0 255.255.255.0
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 1.1.1.1"
keepalive 10 120
persist-key
persist-tun
user nobody
group nogroup
verb 3
OVPN

sysctl -w net.ipv4.ip_forward=1
systemctl enable openvpn@server
systemctl restart openvpn@server || systemctl start openvpn@server

ensure_ufw_port 1194 udp openvpn
usk_ok
