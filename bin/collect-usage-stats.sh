#!/bin/bash
# Collect live VPN traffic counters (run as root via sudo from panel).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true

OVPN_STATUS_DIR="${OVPN_STATUS_DIR:-/var/log/openvpn}"

wg_map_json() {
  local iface="$1"
  local cmd="$2"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi
  if ! "$cmd" show "$iface" >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi
  "$cmd" show "$iface" dump 2>/dev/null | awk -F'\t' '
    NF >= 7 && $1 != "" {
      key = $1
      bytes = $6 + $7
      if (key in data) data[key] += bytes
      else data[key] = bytes
    }
    END {
      printf "{"
      first = 1
      for (k in data) {
        if (!first) printf ","
        first = 0
        gsub(/"/, "\\\"", k)
        printf "\"%s\":%d", k, data[k]
      }
      printf "}"
    }'
}

xray_map_json() {
  local bin
  if [ -f "$XRAY_CFG" ]; then
    usk_xray_ensure_stats_policy "$XRAY_CFG" 2>/dev/null || true
  fi
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  if [ -z "$bin" ] || [ ! -x "$bin" ]; then
    echo '{}'
    return 0
  fi
  local raw
  raw=$("$bin" api statsquery --server=127.0.0.1:10085 --pattern 'user>>>' 2>/dev/null || true)
  if [ -z "$raw" ]; then
    echo '{}'
    return 0
  fi
  echo "$raw" | jq -c '
    [(.stat // .stats // [])[]? | select(.name? != null)] |
    map(select(.name | startswith("user>>>"))) |
    map(.name as $n | ($n | split(">>>")) as $p |
      select($p | length >= 4) |
      {user: $p[1], value: (.value // 0 | tonumber)}
    ) |
    group_by(.user) |
    map({key: .[0].user, value: (map(.value) | add)}) |
    from_entries
  ' 2>/dev/null || echo '{}'
}

openvpn_map_json() {
  local files=(
    "${OVPN_STATUS_DIR}/openvpn-udp-status.log"
    "${OVPN_STATUS_DIR}/openvpn-tcp-status.log"
    "${OVPN_STATUS_DIR}/openvpn-status.log"
    "/run/openvpn-server/status.log"
  )
  local args=()
  local f
  for f in "${files[@]}"; do
    [ -r "$f" ] && args+=("$f")
  done
  if [ "${#args[@]}" -eq 0 ]; then
    echo '{}'
    return 0
  fi
  awk -F',' '
    /^CLIENT_LIST,/ {
      user = $2
      gsub(/^ +| +$/, "", user)
      if (user == "" || user == "Common Name") next
      if (NF >= 7) {
        bytes = $6 + $7
        if (user in t) t[user] += bytes
        else t[user] = bytes
      }
    }
    END {
      printf "{"
      first = 1
      for (u in t) {
        if (!first) printf ","
        first = 0
        gsub(/"/, "\\\"", u)
        printf "\"%s\":%d", u, t[u]
      }
      printf "}"
    }' "${args[@]}" 2>/dev/null || echo '{}'
}

WG_JSON=$(wg_map_json wg0 wg)
AWG_JSON=$(wg_map_json awg0 awg)
XRAY_JSON=$(xray_map_json)
OVPN_JSON=$(openvpn_map_json)

if command -v jq >/dev/null 2>&1; then
  jq -nc \
    --argjson wireguard "$WG_JSON" \
    --argjson amnezia "$AWG_JSON" \
    --argjson xray "$XRAY_JSON" \
    --argjson openvpn "$OVPN_JSON" \
    '{wireguard:$wireguard,amnezia:$amnezia,xray:$xray,openvpn:$openvpn,ok:true,collected_at:(now|todate)}'
else
  printf '{"wireguard":%s,"amnezia":%s,"xray":%s,"openvpn":%s,"ok":true}\n' \
    "$WG_JSON" "$AWG_JSON" "$XRAY_JSON" "$OVPN_JSON"
fi
