#!/bin/bash
# UnlimitSky shared helpers
set -e
echo "[USK] Running as $(whoami)"
if [ "$EUID" -ne 0 ]; then echo "USK_ERR: run as root"; exit 1; fi
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

usk_ok() { echo "USK_OK"; exit 0; }
usk_fail() { echo "USK_ERR: $1"; exit 1; }

usk_mark_installed() {
  local proto="$1"
  local root="${2:-}"
  if [ -z "$root" ]; then
    root="/var/www/unlimitsky"
  fi
  local dir="${root}/data/protocol-installed"
  mkdir -p "$dir"
  date -Iseconds > "${dir}/${proto}"
  chmod 644 "${dir}/${proto}" 2>/dev/null || true
  if id www-data >/dev/null 2>&1; then
    chown www-data:www-data "${dir}/${proto}" 2>/dev/null || true
  fi
}

ensure_ufw_port() {
  if command -v ufw >/dev/null 2>&1; then
    ufw allow "$1"/"$2" comment "UnlimitSky $3" || true
  fi
}
