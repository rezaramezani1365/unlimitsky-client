#!/bin/bash
# Enforce per-service max concurrent connections (plan slot limit).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/openvpn-common.sh" 2>/dev/null || true

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
DATA_ROOT="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
CLIENTS_DIR="${PANEL_ROOT}/data/clients"

if [ "$EUID" -ne 0 ]; then
  echo '{"ok":false,"error":"run_as_root"}'
  exit 1
fi

command -v jq >/dev/null 2>&1 || { echo '{"ok":false,"error":"jq_required"}'; exit 1; }

checked=0
trimmed=0
details='[]'

merge_client_file() {
  local protocol="$1"
  local file="$2"
  local merged="$3"
  [ -f "$file" ] || return 0
  jq -s --arg p "$protocol" '
    .[0] as $base | .[1] as $incoming |
    ($incoming | if type == "array" then
      reduce .[] as $r ({}; if ($r.username // "") != "" then . + {($r.username): $r} else . end)
    elif type == "object" then . else {} end) as $add |
    reduce ($add | keys[]) as $u ($base;
      .[$u] = ((.[$u] // {}) + $add[$u] + {
        protocol: $p,
        username: $u,
        max_connections: (($add[$u].max_connections // .[$u].max_connections // 1) | tonumber? // 1)
      })
    )
  ' "$merged" "$file" > "${merged}.tmp" && mv "${merged}.tmp" "$merged"
}

build_clients_map() {
  local merged
  merged=$(mktemp)
  echo '{}' > "$merged"
  for protocol in xray openvpn wireguard amnezia l2tp cisco; do
    merge_client_file "$protocol" "${CLIENTS_DIR}/${protocol}.json" "$merged"
    merge_client_file "$protocol" "${DATA_ROOT}/${protocol}/clients.json" "$merged"
  done
  echo "$merged"
}

openvpn_enforce_user() {
  local username="$1"
  local max="$2"
  local status_file port cn addr line kicked need
  kicked=0
  need=0

  while IFS= read -r status_file; do
    [ -n "$status_file" ] || continue
    [ -r "$status_file" ] || continue
    port=$(usk_openvpn_management_port_for_status "$status_file" 2>/dev/null || echo 7505)

    while IFS= read -r line; do
      cn=$(echo "$line" | cut -f1)
      addr=$(echo "$line" | cut -f2)
      [ -n "$cn" ] && [ -n "$addr" ] || continue
      if [ "$cn" = "$username" ]; then
        need=$((need + 1))
        if [ "$need" -gt "$max" ]; then
          usk_openvpn_mgmt_kill "$port" "$cn" "$addr" && kicked=$((kicked + 1))
        fi
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

  echo "$kicked"
}

xray_enforce_user() {
  local username="$1"
  local max="$2"
  local online kicked i
  usk_xray_ensure_stats_policy "$XRAY_CFG" 2>/dev/null || true
  online=$(usk_xray_user_online_count "$username")
  online=${online:-0}
  kicked=0
  if [ "$online" -gt "$max" ] 2>/dev/null; then
    i=0
    while [ "$online" -gt "$max" ] && [ "$i" -lt 5 ]; do
      usk_xray_kick_inbound_user "$username" "$XRAY_CFG" && kicked=$((kicked + 1))
      sleep 0.4
      online=$(usk_xray_user_online_count "$username")
      online=${online:-0}
      i=$((i + 1))
    done
  fi
  echo "$kicked"
}

usk_openvpn_ensure_management 2>/dev/null || true

if [ -f "$XRAY_CFG" ]; then
  if ! jq -e '.policy.levels["0"].statsUserOnline == true' "$XRAY_CFG" >/dev/null 2>&1; then
    usk_xray_ensure_stats_policy "$XRAY_CFG" 2>/dev/null || true
    if [ ! -f "${DATA_ROOT}/xray/.stats-online-ready" ]; then
      systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || true
      mkdir -p "${DATA_ROOT}/xray"
      touch "${DATA_ROOT}/xray/.stats-online-ready"
      sleep 1
    fi
  fi
fi

map_file=$(build_clients_map)

while IFS=$'\t' read -r protocol username max status; do
  [ -z "$protocol" ] || [ -z "$username" ] || continue
  max=${max:-1}
  [ "$max" -ge 1 ] 2>/dev/null || max=1
  if [ "$status" != "active" ]; then
    continue
  fi
  checked=$((checked + 1))

  kicked=0
  online=0
  case "$protocol" in
    openvpn)
      online=$(awk -F',' -v u="$username" '
        /^CLIENT_LIST,/ {
          cn=$2; gsub(/^ +| +$/, "", cn)
          if (cn == u) c++
        } END { print c+0 }' $(usk_openvpn_discover_status_files 2>/dev/null | tr '\n' ' ') 2>/dev/null || echo 0)
      kicked=$(openvpn_enforce_user "$username" "$max")
      ;;
    xray)
      online=$(usk_xray_user_online_count "$username")
      online=${online:-0}
      kicked=$(xray_enforce_user "$username" "$max")
      ;;
    *)
      continue
      ;;
  esac

  if [ "${kicked:-0}" -gt 0 ] 2>/dev/null; then
    trimmed=$((trimmed + kicked))
    row=$(jq -nc --arg p "$protocol" --arg u "$username" --argjson on "${online:-0}" --argjson mx "$max" --argjson k "$kicked" \
      '{protocol:$p,username:$u,online:$on,max:$mx,kicked:$k}')
    details=$(echo "$details" | jq -c --argjson r "$row" '. + [$r]')
  fi
done < <(jq -r '
  to_entries[] |
  select(.value.status? // "active" == "active") |
  [
    (.value.protocol // "xray"),
    (.key),
    ((.value.max_connections // 1) | tonumber? // 1),
    (.value.status // "active")
  ] | @tsv
' "$map_file" 2>/dev/null)

rm -f "$map_file"

jq -nc \
  --argjson checked "$checked" \
  --argjson trimmed "$trimmed" \
  --argjson details "$details" \
  '{ok:true, checked:$checked, trimmed:$trimmed, connections_trimmed:$trimmed, details:$details, ran_at:(now|todate)}'

exit 0
