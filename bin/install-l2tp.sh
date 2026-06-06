#!/bin/bash
# Install L2TP/IPsec (xl2tpd + strongSwan) on Ubuntu — Windows/iOS compatible
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/l2tp-common.sh"
set +e

USK_ROOT="${1:-/var/www/unlimitsky}"
L2TP_SUBNET="10.10.10.0/24"

apt-get update -qq
if ! apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" strongswan xl2tpd ppp; then
  usk_fail "l2tp_packages_failed"
fi

if [ -f /etc/unlimitsky-l2tp.psk ]; then
  PSK=$(tr -d '\n\r' < /etc/unlimitsky-l2tp.psk)
else
  PSK="unlimitsky$(openssl rand -hex 8)"
fi

usk_l2tp_write_strongswan_compat
usk_l2tp_write_ipsec "$PSK"
usk_l2tp_write_xl2tpd
usk_l2tp_write_ppp_options

touch /etc/ppp/chap-secrets
chmod 600 /etc/ppp/chap-secrets

echo "$PSK" > /etc/unlimitsky-l2tp.psk
chmod 600 /etc/unlimitsky-l2tp.psk

usk_mark_installed l2tp "$USK_ROOT"

usk_l2tp_sysctl
usk_l2tp_setup_iptables "$L2TP_SUBNET"
usk_l2tp_restart_services

ensure_ufw_port 500 udp ipsec-ike
ensure_ufw_port 4500 udp ipsec-nat-t
ensure_ufw_port 1701 udp l2tp

if [ ! -f /etc/xl2tpd/xl2tpd.conf ] || [ ! -f /etc/ppp/options.xl2tpd ]; then
  usk_fail "l2tp_config_failed"
fi

echo "USK_META:ports=500,4500,1701;port=1701"
usk_ok
