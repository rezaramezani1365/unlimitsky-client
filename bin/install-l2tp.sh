#!/bin/bash
# Install L2TP/IPsec (xl2tpd + strongSwan) on Ubuntu
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

apt-get update -qq
apt-get install -y strongswan xl2tpd

PSK="UnlimitSky$(openssl rand -hex 8)"

cat > /etc/ipsec.conf <<'IPSEC'
config setup
  charondebug="ike 1, knl 1, cfg 0"

conn L2TP-PSK
  keyexchange=ikev1
  authby=secret
  type=transport
  left=%defaultroute
  leftprotoport=17/1701
  right=%any
  rightprotoport=17/%any
  auto=add
IPSEC

echo ": PSK \"$PSK\"" > /etc/ipsec.secrets

cat > /etc/xl2tpd/xl2tpd.conf <<'L2TP'
[global]
listen-addr = 0.0.0.0
[lns default]
ip range = 10.10.10.100-10.10.10.200
local ip = 10.10.10.1
require authentication = no
ppp debug = no
pppoptfile = /etc/ppp/options.xl2tpd
L2TP

echo "$PSK" > /etc/unlimitsky-l2tp.psk
systemctl enable strongswan-starter xl2tpd
systemctl restart strongswan-starter xl2tpd

ensure_ufw_port 500 udp ipsec
ensure_ufw_port 4500 udp ipsec-nat
ensure_ufw_port 1701 udp l2tp
usk_ok
