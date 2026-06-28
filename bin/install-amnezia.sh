#!/bin/bash
# Install AmneziaWG (userspace — no DKMS / kernel headers required)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/amnezia-common.sh"
set +e

PORT="${1:-443}"
PORT=$(echo "$PORT" | tr -dc '0-9')
if [ -z "$PORT" ] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ] 2>/dev/null; then
  PORT=443
fi
if [ "$PORT" -gt 9999 ] 2>/dev/null; then
  PORT=443
fi

if usk_amnezia_bivlked && [ -f "$AMNEZIA_CONF" ]; then
  final_port=$(usk_amnezia_server_port)
  final_port=$(echo "$final_port" | tr -dc '0-9')
  [ -n "$final_port" ] || final_port="$PORT"
  echo "USK_META:port=${final_port};mode=external"
  usk_ok
fi

if usk_amnezia_verify_installed; then
  out_port=$(usk_amnezia_server_port)
  out_port=$(echo "$out_port" | tr -dc '0-9')
  usk_amnezia_ensure_running || true
  mode="userspace"
  usk_amnezia_userspace_mode || mode="kernel"
  [ -n "$out_port" ] || out_port="$PORT"
  echo "USK_META:port=${out_port};mode=${mode}"
  usk_ok
fi

if ! usk_amnezia_install_userspace; then
  echo "USK_ERR: amnezia_userspace_install_failed" >&2
  echo "USK_ERR: amnezia_install_failed"
  exit 1
fi

out_port=$(usk_amnezia_init_server "$PORT")
if [ -z "$out_port" ] || [ ! -f "$AMNEZIA_CONF" ]; then
  echo "USK_ERR: amnezia_config_failed" >&2
  echo "USK_ERR: amnezia_install_failed"
  exit 1
fi

if ! usk_amnezia_ensure_running; then
  echo "USK_WARN:amnezia_service_start" >&2
fi

echo "USK_META:port=${out_port};mode=userspace"
usk_ok
