#!/bin/bash
# Enable WireGuard TCP bridge (udp2raw) on an existing install
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

if usk_wg_setup_tcp_bridge "$PORT" "$TCP_PORT"; then
  echo "USK_META:port=${PORT};tcp_port=${TCP_PORT}"
  usk_ok
fi
echo "USK_META:port=${PORT};tcp_port=${TCP_PORT}"
echo "USK_WARN:wireguard_tcp_bridge"
echo "USK_ERR: wireguard_tcp_bridge_failed"
exit 1
