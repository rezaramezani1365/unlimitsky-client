#!/bin/bash
# Enable OpenVPN status-version 2 logs for usage metering (existing installs).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/openvpn-common.sh"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

usk_openvpn_ensure_status_policy || { echo "USK_ERR: status_policy_failed" >&2; exit 1; }

if [ -f /etc/openvpn/server-udp.conf ]; then
  usk_openvpn_enable_service "server-udp" || true
fi
if [ -f /etc/openvpn/server-tcp.conf ]; then
  usk_openvpn_enable_service "server-tcp" || true
fi
if [ -f /etc/openvpn/server.conf ]; then
  systemctl restart openvpn@server 2>/dev/null || true
fi

sleep 2

if usk_openvpn_verify_status_logs; then
  echo "USK_OK: openvpn_status_logs=$(usk_openvpn_discover_status_files | tr '\n' ' ')"
  exit 0
fi

echo "USK_ERR: openvpn_status_logs_missing — check: grep -E '^status' /etc/openvpn/*.conf" >&2
exit 1
