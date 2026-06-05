#!/bin/bash
# Install AmneziaWG (compatible with Amnezia VPN app)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/amnezia-common.sh"
set +e

PORT="${1:-51821}"
PORT=$(echo "$PORT" | tr -dc '0-9')
if [ -z "$PORT" ] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ] 2>/dev/null; then
  PORT=51821
fi

if usk_amnezia_bivlked; then
  echo "USK_META:port=${PORT}"
  usk_ok
fi

if usk_amnezia_install_packages; then
  out_port=$(usk_amnezia_init_server "$PORT")
  if [ -n "$out_port" ] && [ -f "$AMNEZIA_CONF" ]; then
    echo "USK_META:port=${out_port}"
    usk_ok
  fi
fi

echo "USK_WARN:amnezia_fallback_install" >&2
if usk_amnezia_try_bivlked_install "$PORT"; then
  if usk_amnezia_bivlked || [ -f "$AMNEZIA_CONF" ]; then
    final_port=$(usk_amnezia_server_port)
    final_port=$(echo "$final_port" | tr -dc '0-9')
    [ -n "$final_port" ] || final_port="$PORT"
    echo "USK_META:port=${final_port}"
    usk_ok
  fi
fi

echo "USK_ERR: amnezia_install_failed"
exit 1
