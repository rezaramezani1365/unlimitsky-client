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
SERVER_IP=$(usk_l2tp_detect_ip)

usk_l2tp_write_strongswan_compat
usk_l2tp_write_ipsec "$PSK" "$SERVER_IP"
usk_l2tp_write_xl2tpd
usk_l2tp_write_ppp_options

touch /etc/ppp/chap-secrets
chmod 600 /etc/ppp/chap-secrets

usk_mark_installed l2tp "$USK_ROOT"
usk_l2tp_sysctl
usk_l2tp_setup_iptables "$L2TP_SUBNET"
usk_l2tp_ensure_ufw

bash "$DIR/setup-l2tp-usage.sh" 2>/dev/null || true

usk_l2tp_restart_services
sleep 2

if ! usk_l2tp_verify_services; then
  usk_l2tp_restart_services
  sleep 2
fi

if ! usk_l2tp_verify_services; then
  echo "USK_WARN: l2tp_service_check" >&2
  usk_fail "l2tp_service_failed"
fi

echo "USK_META:ports=500,4500,1701;port=1701;server_ip=${SERVER_IP}"
usk_ok
