#!/bin/bash
# UnlimitSky shared helpers
set -e
echo "[USK] Running as $(whoami)"
if [ "$EUID" -ne 0 ]; then echo "USK_ERR: run as root"; exit 1; fi
export DEBIAN_FRONTEND=noninteractive

usk_ok() { echo "USK_OK"; exit 0; }
usk_fail() { echo "USK_ERR: $1"; exit 1; }

ensure_ufw_port() {
  if command -v ufw >/dev/null 2>&1; then
    ufw allow "$1"/"$2" comment "UnlimitSky $3" || true
  fi
}
