#!/bin/bash
# OpenVPN client-connect — reject connection when max_connections slots are full.
# Return 0 = allow, 1 = deny (OpenVPN rejects the client).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/openvpn-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/connection-slots-common.sh" 2>/dev/null || true

CN="${common_name:-}"
IP="${untrusted_ip:-}"

[ -n "$CN" ] || exit 0
[ -n "$IP" ] || exit 0

if ! usk_slots_client_active openvpn "$CN"; then
  exit 0
fi

MAX=$(usk_slots_load_max openvpn "$CN")
connected=0
same_ip=0

while IFS= read -r status_file; do
  [ -n "$status_file" ] || continue
  [ -r "$status_file" ] || continue
  while IFS=$'\t' read -r cn addr; do
    [ -n "$cn" ] || continue
    [ "$cn" = "$CN" ] || continue
    connected=$((connected + 1))
    if [ "$addr" = "$IP" ]; then
      same_ip=1
    fi
  done < <(awk -F',' '
    /^CLIENT_LIST,/ {
      cn=$2; addr=$3
      gsub(/^ +| +$/, "", cn)
      gsub(/^ +| +$/, "", addr)
      if (cn != "" && cn != "Common Name" && addr != "" && addr != "Real Address")
        print cn "\t" addr
    }' "$status_file" 2>/dev/null)
done < <(usk_openvpn_discover_status_files 2>/dev/null || true)

# Same device reconnecting — allow.
if [ "$same_ip" = 1 ]; then
  exit 0
fi

if [ "$connected" -ge "$MAX" ] 2>/dev/null; then
  logger -t unlimitsky "openvpn reject CN=$CN from $IP (slots $MAX full)"
  exit 1
fi

exit 0
