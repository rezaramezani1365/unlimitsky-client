#!/bin/bash
# Install L2TP/IPsec (xl2tpd + strongSwan) on Ubuntu — Windows/iOS compatible
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/l2tp-common.sh"
set +e

USK_ROOT="${1:-/var/www/unlimitsky}"
L2TP_SUBNET="10.10.10.0/24"

apt-get update -qq
if ! apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" \
    strongswan xl2tpd ppp iptables curl; then
  usk_fail "l2tp_packages_failed"
fi

if [ -f /etc/unlimitsky-l2tp.psk ]; then
  PSK=$(tr -d '\n\r' < /etc/unlimitsky-l2tp.psk)
else
  PSK="unlimitsky$(openssl rand -hex 8)"
fi

SERVER_IP=$(usk_l2tp_detect_ip)

usk_l2tp_write_strongswan_compat
usk_l2tp_write_ipsec "$PSK" "$SERVER_IP"
usk_l2tp_write_xl2tpd
usk_l2tp_write_ppp_options

touch /etc/ppp/chap-secrets
chmod 600 /etc/ppp/chap-secrets

echo "$PSK" > /etc/unlimitsky-l2tp.psk
chmod 600 /etc/unlimitsky-l2tp.psk

usk_mark_installed l2tp "$USK_ROOT"

usk_l2tp_sysctl
usk_l2tp_setup_iptables "$L2TP_SUBNET"
usk_l2tp_ensure_ufw

# Per-user volume metering: install the pppd ip-up/ip-down accounting hooks.
bash "$DIR/setup-l2tp-usage.sh" 2>/dev/null || true

usk_l2tp_restart_services
sleep 2

if [ ! -f /etc/xl2tpd/xl2tpd.conf ] || [ ! -f /etc/ppp/options.xl2tpd ]; then
  usk_fail "l2tp_config_failed"
fi

if ! usk_l2tp_verify_services; then
  usk_l2tp_restart_services
  sleep 2
fi

if ! usk_l2tp_verify_services; then
  echo "USK_WARN: l2tp_service_check" >&2
  journalctl -u xl2tpd -n 5 --no-pager 2>/dev/null || true
  journalctl -u strongswan-starter -n 5 --no-pager 2>/dev/null || true
  usk_fail "l2tp_service_failed"
fi

echo "USK_META:ports=500,4500,1701;port=1701;server_ip=${SERVER_IP}"
usk_ok
