#!/bin/bash
# Install the pppd ip-up/ip-down hooks that enable per-user L2TP volume metering.
# Safe to run repeatedly (idempotent). Requires root.
set -e
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ "$EUID" -ne 0 ]; then
  echo "USK_ERR: run_as_root" >&2
  exit 1
fi

DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"

install -d /etc/ppp/ip-up.d /etc/ppp/ip-down.d 2>/dev/null || true
install -m 0755 "$DIR/l2tp-ip-up.sh"   /etc/ppp/ip-up.d/unlimitsky-l2tp
install -m 0755 "$DIR/l2tp-ip-down.sh" /etc/ppp/ip-down.d/unlimitsky-l2tp

mkdir -p "${DATA_ROOT}/l2tp/iface" 2>/dev/null || true
chmod 0755 "${DATA_ROOT}/l2tp" "${DATA_ROOT}/l2tp/iface" 2>/dev/null || true

echo "USK_OK: l2tp_usage_hooks_installed"
exit 0
