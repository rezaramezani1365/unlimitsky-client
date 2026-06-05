#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/amnezia-common.sh"
set +e

if ! usk_amnezia_verify_installed && ! usk_amnezia_bivlked; then
  if ! usk_amnezia_install_userspace; then
    echo "USK_ERR: amnezia_userspace_install_failed"
    exit 1
  fi
fi

if [ ! -f "$AMNEZIA_CONF" ] && ! usk_amnezia_bivlked; then
  PORT="${1:-443}"
  usk_amnezia_init_server "$PORT" >/dev/null || true
fi

usk_amnezia_fixup_obf_params 2>/dev/null || true
usk_amnezia_sync_interface_from_params 2>/dev/null || true

MAIN_IFACE=$(usk_amnezia_main_iface)
MAIN_IFACE="${MAIN_IFACE:-eth0}"
sysctl -w net.ipv4.ip_forward=1

usk_amnezia_ensure_running || {
  systemctl restart awg-quick@awg0 2>/dev/null || awg-quick up awg0 2>/dev/null || true
}

PORT=$(usk_amnezia_server_port)
PORT=$(echo "$PORT" | tr -dc '0-9')
[ -n "$PORT" ] && ensure_ufw_port "$PORT" udp amnezia-awg

iptables -C FORWARD -i awg0 -j ACCEPT 2>/dev/null || iptables -A FORWARD -i awg0 -j ACCEPT 2>/dev/null || true
iptables -t nat -C POSTROUTING -o "$MAIN_IFACE" -j MASQUERADE 2>/dev/null \
  || iptables -t nat -A POSTROUTING -o "$MAIN_IFACE" -j MASQUERADE 2>/dev/null || true

mode="userspace"
usk_amnezia_userspace_mode || mode="kernel"
[ -n "$PORT" ] && echo "USK_META:port=${PORT};mode=${mode}"
usk_ok
