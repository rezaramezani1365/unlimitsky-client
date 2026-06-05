#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/amnezia-common.sh"
set +e

if [ ! -f "$AMNEZIA_CONF" ] && ! usk_amnezia_bivlked; then
  echo "USK_ERR: amnezia_not_installed"
  exit 1
fi

MAIN_IFACE=$(usk_amnezia_main_iface)
MAIN_IFACE="${MAIN_IFACE:-eth0}"
sysctl -w net.ipv4.ip_forward=1

if [ -f "$AMNEZIA_CONF" ]; then
  PORT=$(usk_amnezia_server_port)
  PORT=$(echo "$PORT" | tr -dc '0-9')
  if command -v awg-quick >/dev/null 2>&1; then
    systemctl restart awg-quick@awg0 2>/dev/null || awg-quick up awg0 2>/dev/null || true
  fi
  [ -n "$PORT" ] && ensure_ufw_port "$PORT" udp amnezia-awg
  iptables -C FORWARD -i awg0 -j ACCEPT 2>/dev/null || iptables -A FORWARD -i awg0 -j ACCEPT 2>/dev/null || true
  iptables -t nat -C POSTROUTING -o "$MAIN_IFACE" -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -o "$MAIN_IFACE" -j MASQUERADE 2>/dev/null || true
fi

PORT=$(usk_amnezia_server_port)
PORT=$(echo "$PORT" | tr -dc '0-9')
[ -n "$PORT" ] && echo "USK_META:port=${PORT}"
usk_ok
