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

load_protocol_clients() {
  local protocol="$1"
  local panel_file="${CLIENTS_DIR}/${protocol}.json"
  local reg_file="${DATA_ROOT}/${protocol}/clients.json"
  local empty='{}'
  local panel_json='{}'
  local reg_json='{}'
  [ -f "$panel_file" ] && panel_json=$(cat "$panel_file")
  [ -f "$reg_file" ] && reg_json=$(cat "$reg_file")
  jq -s --arg p "$protocol" '
    def as_map:
      if type == "array" then
        reduce .[] as $r ({}; if ($r.username // "") != "" then . + {($r.username): $r} else . end)
      elif type == "object" then . else {} end;
    (.[0] | as_map) + (.[1] | as_map) | to_entries | map(.value + {username: .key, protocol: $p})
  ' <(echo "$panel_json") <(echo "$reg_json") 2>/dev/null || echo '[]'
}

openvpn_enforce_user() {
  local username="$1"
  local max="$2"
  local status_file port cn addr kicked need
  kicked=0
  need=0
  usk_openvpn_ensure_management 2>/dev/null || true

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

if [ -f "$XRAY_CFG" ] && command -v iptables >/dev/null 2>&1; then
  port=$(usk_xray_vless_port_from_config "$XRAY_CFG" 2>/dev/null || echo 443)
  usk_xray_slot_chain_reset "${port:-443}" 2>/dev/null || true
fi

for protocol in xray openvpn; do
  while IFS= read -r rec; do
    [ -n "$rec" ] || continue
    username=$(echo "$rec" | jq -r '.username // empty')
    status=$(echo "$rec" | jq -r '.status // "active"')
    max=$(echo "$rec" | jq -r '(.max_connections // 1) | tonumber? // 1')
    [ -n "$username" ] || continue
    [ "$status" = "active" ] || continue
    max=${max:-1}
    [ "$max" -ge 1 ] 2>/dev/null || max=1
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
        mapfile -t _ips < <(usk_xray_online_ips_for_email "$username" 2>/dev/null || true)
        if [ "${#_ips[@]}" -gt 0 ]; then
          online=${#_ips[@]}
        fi
        kicked=$(usk_xray_enforce_slot_limit "$username" "$max" "$XRAY_CFG")
        ;;
    esac

    if [ "${kicked:-0}" -gt 0 ] 2>/dev/null; then
      trimmed=$((trimmed + kicked))
      row=$(jq -nc --arg p "$protocol" --arg u "$username" --argjson on "${online:-0}" --argjson mx "$max" --argjson k "$kicked" \
        '{protocol:$p,username:$u,online:$on,max:$mx,kicked:$k,method:"slot_limit"}')
      details=$(echo "$details" | jq -c --argjson r "$row" '. + [$r]')
    fi
  done < <(load_protocol_clients "$protocol")
done

jq -nc \
  --argjson checked "$checked" \
  --argjson trimmed "$trimmed" \
  --argjson details "$details" \
  '{ok:true, checked:$checked, trimmed:$trimmed, connections_trimmed:$trimmed, details:$details, ran_at:(now|todate)}'

exit 0
