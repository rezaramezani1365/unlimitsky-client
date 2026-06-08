#!/bin/bash
# pppd ip-down hook: drop the stale iface->username mapping when a session ends.
# pppd args: $1=iface $2=tty $3=speed $4=local-ip $5=remote-ip $6=ipparam
IFACE="${1:-$IFNAME}"
[ -n "$IFACE" ] || exit 0

DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
MAP_DIR="${DATA_ROOT}/l2tp/iface"
rm -f "${MAP_DIR}/${IFACE}" 2>/dev/null || true
exit 0
