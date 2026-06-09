#!/bin/bash
# Repair WireGuard: NAT/forwarding, wg0 restart, optional TCP bridge (udp2raw)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/wireguard-common.sh"
set +e

TCP_PORT="${1:-51822}"
TCP_PORT=$(echo "$TCP_PORT" | tr -dc '0-9')
if [ -z "$TCP_PORT" ] || [ "$TCP_PORT" -lt 1 ] || [ "$TCP_PORT" -gt 65535 ] 2>/dev/null; then
  TCP_PORT=51822
fi

if [ ! -f /etc/wireguard/wg0.conf ]; then
  echo "USK_ERR: wireguard_not_installed"
  exit 1
fi

PORT=$(grep -E '^ListenPort' /etc/wireguard/wg0.conf 2>/dev/null | awk '{print $3}')
PORT=$(echo "$PORT" | tr -dc '0-9')
[ -n "$PORT" ] || PORT=51820

if ! usk_wg_ensure_running; then
  echo "USK_ERR: wireguard_interface_down"
  exit 1
fi

ensure_ufw_port "$PORT" udp wireguard-udp

BRIDGE_OK=0
if usk_wg_setup_tcp_bridge "$PORT" "$TCP_PORT"; then
  BRIDGE_OK=1
  TCP_PORT=$(usk_wg_tcp_port)
  TCP_PORT=$(echo "$TCP_PORT" | tr -dc '0-9')
  [ -n "$TCP_PORT" ] || TCP_PORT=51822
fi

echo "USK_META:port=${PORT};tcp_port=${TCP_PORT}"
if [ "$BRIDGE_OK" -ne 1 ]; then
  echo "USK_WARN:wireguard_tcp_bridge"
  usk_ok
  exit 0
fi
usk_ok
