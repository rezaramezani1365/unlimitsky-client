#!/bin/bash
# pppd ip-up hook: map the ppp interface to its L2TP username so the usage
# collector can attribute /sys/class/net/<iface> byte counters to a client.
# pppd args: $1=iface $2=tty $3=speed $4=local-ip $5=remote-ip $6=ipparam
# pppd env:  PEERNAME = authenticated username (from /etc/ppp/chap-secrets)
IFACE="${1:-$IFNAME}"
USER_NAME="${PEERNAME:-}"
[ -n "$IFACE" ] || exit 0
[ -n "$USER_NAME" ] || exit 0

DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
MAP_DIR="${DATA_ROOT}/l2tp/iface"
mkdir -p "$MAP_DIR" 2>/dev/null || exit 0
printf '%s' "$USER_NAME" > "${MAP_DIR}/${IFACE}" 2>/dev/null || true
exit 0
