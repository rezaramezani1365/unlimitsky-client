#!/bin/bash
# Repair L2TP/IPsec (IPsec ciphers, NAT, services) without changing PSK/users
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/l2tp-common.sh"
set +e

USK_ROOT="${1:-/var/www/unlimitsky}"
L2TP_SUBNET="10.10.10.0/24"

if [ ! -f /etc/unlimitsky-l2tp.psk ]; then
  usk_fail "l2tp_not_installed"
fi

PSK=$(tr -d '\n\r' < /etc/unlimitsky-l2tp.psk)

usk_l2tp_write_strongswan_compat
usk_l2tp_write_ipsec "$PSK"
usk_l2tp_write_ppp_options

if [ -f /etc/xl2tpd/xl2tpd.conf ]; then
  sed -i 's/require authentication = no/require authentication = yes/' /etc/xl2tpd/xl2tpd.conf 2>/dev/null || true
fi

usk_mark_installed l2tp "$USK_ROOT"
usk_l2tp_sysctl
usk_l2tp_setup_iptables "$L2TP_SUBNET"
usk_l2tp_restart_services

ensure_ufw_port 500 udp ipsec-ike
ensure_ufw_port 4500 udp ipsec-nat-t
ensure_ufw_port 1701 udp l2tp

echo "USK_META:ports=500,4500,1701;port=1701"
usk_ok
