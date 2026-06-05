#!/bin/bash
# Verify a native protocol is installed on the system (run via sudo from panel)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
set +e

PROTO="${1:-}"
USK_ROOT="${2:-/var/www/unlimitsky}"

if [ -z "$PROTO" ]; then
  usk_fail "protocol_required"
fi

case "$PROTO" in
  l2tp)
    if [ -f /etc/xl2tpd/xl2tpd.conf ] && [ -f /etc/ppp/options.xl2tpd ] && [ -f /etc/unlimitsky-l2tp.psk ]; then
      usk_mark_installed l2tp "$USK_ROOT"
      usk_ok
    fi
    usk_fail "l2tp_not_installed"
    ;;
  wireguard)
    [ -f /etc/wireguard/wg0.conf ] && usk_mark_installed wireguard "$USK_ROOT" && usk_ok
    usk_fail "wireguard_not_installed"
    ;;
  openvpn)
    if [ -f /etc/openvpn/server-udp.conf ] || [ -f /etc/openvpn/server.conf ]; then
      usk_mark_installed openvpn "$USK_ROOT"
      usk_ok
    fi
    usk_fail "openvpn_not_installed"
    ;;
  xray)
    if [ -f /usr/local/etc/xray/config.json ] || [ -f /etc/xray/config.json ]; then
      usk_mark_installed xray "$USK_ROOT"
      usk_ok
    fi
    usk_fail "xray_not_installed"
    ;;
  cisco)
    [ -f /etc/ocserv/ocserv.conf ] && usk_mark_installed cisco "$USK_ROOT" && usk_ok
    usk_fail "cisco_not_installed"
    ;;
  amnezia)
    if [ -f /etc/amnezia/amneziawg/awg0.conf ] || [ -x /root/awg/manage_amneziawg.sh ]; then
      usk_mark_installed amnezia "$USK_ROOT"
      usk_ok
    fi
    usk_fail "amnezia_not_installed"
    ;;
  *)
    usk_fail "invalid_protocol"
    ;;
esac
