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
      DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
      # shellcheck source=l2tp-common.sh
      source "$DIR/l2tp-common.sh" 2>/dev/null || true
      if declare -F usk_l2tp_verify_services >/dev/null 2>&1; then
        usk_l2tp_verify_services || usk_fail "l2tp_service_failed"
      fi
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
    if [ -f /etc/openvpn/server-udp.conf ] || [ -f /etc/openvpn/server-tcp.conf ] || [ -f /etc/openvpn/server.conf ]; then
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
    if command -v xray >/dev/null 2>&1 || [ -x /usr/local/bin/xray ]; then
      if systemctl is-active xray >/dev/null 2>&1 || systemctl is-active xray.service >/dev/null 2>&1; then
        usk_mark_installed xray "$USK_ROOT"
        usk_ok
      fi
    fi
    usk_fail "xray_not_installed"
    ;;
  cisco)
    [ -f /etc/ocserv/ocserv.conf ] && usk_mark_installed cisco "$USK_ROOT" && usk_ok
    usk_fail "cisco_not_installed"
    ;;
  *)
    usk_fail "invalid_protocol"
    ;;
esac
