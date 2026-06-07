#!/bin/bash
# Collect live VPN traffic counters (run as root via sudo from panel).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/openvpn-common.sh" 2>/dev/null || true

OVPN_STATUS_DIR="${OVPN_STATUS_DIR:-/var/log/openvpn}"
DATA_ROOT="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"

count_json_keys() {
  local j="$1"
  if [ -z "$j" ] || [ "$j" = "{}" ]; then
    echo 0
    return 0
  fi
  if command -v jq >/dev/null 2>&1; then
    echo "$j" | jq 'length' 2>/dev/null || echo 0
    return 0
  fi
  echo 0
}

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

xray_email_bytes() {
  local bin="$1"
  local email="$2"
  local down up
  down=$("$bin" api stats --server=127.0.0.1:10085 -name "user>>>${email}>>>traffic>>>downlink" 2>/dev/null \
    | jq -r '.stat.value // 0' 2>/dev/null || echo 0)
  up=$("$bin" api stats --server=127.0.0.1:10085 -name "user>>>${email}>>>traffic>>>uplink" 2>/dev/null \
    | jq -r '.stat.value // 0' 2>/dev/null || echo 0)
  echo $(( ${down:-0} + ${up:-0} ))
}

xray_map_json() {
  local bin
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  if [ -z "$bin" ] || [ ! -x "$bin" ]; then
    echo '{}'
    return 0
  fi

  local raw map='{}'
  raw=$("$bin" api statsquery --server=127.0.0.1:10085 2>/dev/null || true)
  if [ -n "$raw" ] && command -v jq >/dev/null 2>&1; then
    map=$(echo "$raw" | jq -c '
      [(.stat // .stats // [])[]? | select(.name? != null)] |
      map(select(.name | startswith("user>>>"))) |
      map(.name as $n | ($n | split(">>>")) as $p |
        select($p | length >= 4) |
        {user: $p[1], value: (.value // 0 | tonumber)}
      ) |
      group_by(.user) |
      map({key: .[0].user, value: (map(.value) | add)}) |
      from_entries
    ' 2>/dev/null || echo '{}')
  fi

  if ! command -v jq >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi

  local emails_file pairs_file uuid_map_file tmp_map
  emails_file=$(mktemp)
  pairs_file=$(mktemp)
  uuid_map_file=$(mktemp)
  : >"$emails_file"
  : >"$pairs_file"
  : >"$uuid_map_file"

  if [ -f "$XRAY_CFG" ]; then
    jq -r '.inbounds[]? | select(.protocol=="vless") | .settings.clients[]? | (.email // "") + "\t" + (.id // "")' \
      "$XRAY_CFG" 2>/dev/null >>"$pairs_file" || true
  fi
  if [ -f "${DATA_ROOT}/xray/clients.json" ]; then
    jq -r '.[]? | (.username // "") + "\t" + (.uuid // "")' \
      "${DATA_ROOT}/xray/clients.json" 2>/dev/null >>"$pairs_file" || true
  fi

  sort -u "$pairs_file" -o "$pairs_file" 2>/dev/null || true
  while IFS=$'\t' read -r email uuid; do
    email=$(echo "$email" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    uuid=$(echo "$uuid" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    [ -z "$email" ] && continue
    echo "$email" >>"$emails_file"
    if [ -n "$uuid" ]; then
      printf '%s\t%s\n' "$uuid" "$email" >>"$uuid_map_file"
    fi
  done <"$pairs_file"

  sort -u "$emails_file" -o "$emails_file" 2>/dev/null || true

  tmp_map="$map"
  while IFS= read -r email; do
    [ -z "$email" ] && continue
    local existing bytes
    existing=$(echo "$tmp_map" | jq -r --arg e "$email" '.[$e] // empty' 2>/dev/null || true)
    if [ -n "$existing" ] && [ "${existing:-0}" -gt 0 ] 2>/dev/null; then
      continue
    fi
    bytes=$(xray_email_bytes "$bin" "$email")
    tmp_map=$(echo "$tmp_map" | jq -c --arg e "$email" --argjson b "${bytes:-0}" '. + {($e): $b}' 2>/dev/null || echo "$tmp_map")
  done <"$emails_file"

  while IFS=$'\t' read -r uuid email; do
    [ -z "$uuid" ] || [ -z "$email" ] && continue
    local b
    b=$(echo "$tmp_map" | jq -r --arg e "$email" '.[$e] // 0' 2>/dev/null || echo 0)
    tmp_map=$(echo "$tmp_map" | jq -c --arg u "$uuid" --argjson b "${b:-0}" '. + {($u): $b}' 2>/dev/null || echo "$tmp_map")
  done <"$uuid_map_file"

  rm -f "$emails_file" "$pairs_file" "$uuid_map_file"
  echo "$tmp_map"
}

openvpn_map_json() {
  local args=() f
  while IFS= read -r f; do
    [ -n "$f" ] && args+=("$f")
  done < <(usk_openvpn_discover_status_files 2>/dev/null || true)

  if [ "${#args[@]}" -eq 0 ]; then
    for f in \
      "${OVPN_STATUS_DIR}/openvpn-udp-status.log" \
      "${OVPN_STATUS_DIR}/openvpn-tcp-status.log" \
      "${OVPN_STATUS_DIR}/openvpn-status.log" \
      "/run/openvpn-server/status.log"; do
      [ -r "$f" ] && args+=("$f")
    done
  fi

  local map='{}'
  if [ "${#args[@]}" -gt 0 ]; then
    map=$(awk -F',' '
      /^CLIENT_LIST,/ {
        user = $2
        gsub(/^ +| +$/, "", user)
        if (user == "" || user == "Common Name") next
        bytes = 0
        if (NF >= 7) bytes = $5 + $6
        else if (NF >= 6) bytes = $4 + $5
        else next
        if (user in t) t[user] += bytes
        else t[user] = bytes
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
      }' "${args[@]}" 2>/dev/null || echo '{}')
  fi

  if command -v jq >/dev/null 2>&1; then
    local panel_cfg f
    panel_cfg="$(dirname "$DIR")/data/clients/openvpn.json"
    for f in "${DATA_ROOT}/openvpn/clients.json" "$panel_cfg"; do
      [ -f "$f" ] || continue
      while IFS= read -r user; do
        [ -z "$user" ] && continue
        map=$(echo "$map" | jq -c --arg u "$user" '. + {($u): (.[$u] // 0)}' 2>/dev/null || echo "$map")
      done < <(jq -r 'if type == "object" and (keys | length) > 0 and (.[] | type) == "object" then keys[] elif type == "array" then .[]?.username else empty end' "$f" 2>/dev/null || true)
    done
  fi

  echo "$map"
}

# Active session count maps (parallel to byte maps).
wg_connections_map_json() {
  local iface="$1"
  local cmd="$2"
  local now grace=180
  now=$(date +%s)
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi
  if ! "$cmd" show "$iface" >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi
  "$cmd" show "$iface" dump 2>/dev/null | awk -F'\t' -v now="$now" -v grace="$grace" '
    NF >= 7 && $1 != "" {
      hs = $5 + 0
      if (hs > 0 && (now - hs) <= grace) data[$1] = 1
    }
    END {
      printf "{"
      first = 1
      for (k in data) {
        if (!first) printf ","
        first = 0
        gsub(/"/, "\\\"", k)
        printf "\"%s\":1", k
      }
      printf "}"
    }'
}

xray_connections_map_json() {
  local bin raw map='{}'
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  if [ -z "$bin" ] || [ ! -x "$bin" ]; then
    echo '{}'
    return 0
  fi
  if ! command -v jq >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi

  raw=$("$bin" api statsonline --server=127.0.0.1:10085 2>/dev/null || true)
  if [ -n "$raw" ]; then
    map=$(echo "$raw" | jq -c '
      (.users // {}) |
      if type == "object" then
        to_entries | map({
          key: .key,
          value: (
            if (.value | type) == "array" then (.value | length)
            elif (.value | type) == "object" then (.value | keys | length)
            elif (.value | type) == "string" then 1
            else (if .value then 1 else 0 end)
          )
        }) | from_entries
      else {} end
    ' 2>/dev/null || echo '{}')
  fi

  local pairs_file uuid_map_file email
  pairs_file=$(mktemp)
  uuid_map_file=$(mktemp)
  : >"$pairs_file"
  : >"$uuid_map_file"
  if [ -f "$XRAY_CFG" ]; then
    jq -r '.inbounds[]? | select(.protocol=="vless") | .settings.clients[]? | (.email // "") + "\t" + (.id // "")' \
      "$XRAY_CFG" 2>/dev/null >>"$pairs_file" || true
  fi
  if [ -f "${DATA_ROOT}/xray/clients.json" ]; then
    jq -r '.[]? | (.username // "") + "\t" + (.uuid // "")' \
      "${DATA_ROOT}/xray/clients.json" 2>/dev/null >>"$pairs_file" || true
  fi
  sort -u "$pairs_file" -o "$pairs_file" 2>/dev/null || true
  while IFS=$'\t' read -r email uuid; do
    email=$(echo "$email" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    uuid=$(echo "$uuid" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    [ -z "$email" ] && continue
    local cnt
    cnt=$(echo "$map" | jq -r --arg e "$email" '.[$e] // empty' 2>/dev/null || true)
    if [ -z "$cnt" ] || [ "${cnt:-0}" -eq 0 ] 2>/dev/null; then
      cnt=$(usk_xray_user_online_count "$email")
      map=$(echo "$map" | jq -c --arg e "$email" --argjson c "${cnt:-0}" '. + {($e): $c}' 2>/dev/null || echo "$map")
    fi
    if [ -n "$uuid" ]; then
      cnt=$(echo "$map" | jq -r --arg e "$email" '.[$e] // 0' 2>/dev/null || echo 0)
      map=$(echo "$map" | jq -c --arg u "$uuid" --argjson c "${cnt:-0}" '. + {($u): $c}' 2>/dev/null || echo "$map")
    fi
  done <"$pairs_file"
  rm -f "$pairs_file" "$uuid_map_file"
  echo "$map"
}

openvpn_connections_map_json() {
  local args=() f
  while IFS= read -r f; do
    [ -n "$f" ] && args+=("$f")
  done < <(usk_openvpn_discover_status_files 2>/dev/null || true)

  if [ "${#args[@]}" -eq 0 ]; then
    for f in \
      "${OVPN_STATUS_DIR}/openvpn-udp-status.log" \
      "${OVPN_STATUS_DIR}/openvpn-tcp-status.log" \
      "${OVPN_STATUS_DIR}/openvpn-status.log" \
      "/run/openvpn-server/status.log"; do
      [ -r "$f" ] && args+=("$f")
    done
  fi

  if [ "${#args[@]}" -eq 0 ]; then
    echo '{}'
    return 0
  fi

  awk -F',' '
    /^CLIENT_LIST,/ {
      user = $2
      gsub(/^ +| +$/, "", user)
      if (user == "" || user == "Common Name") next
      c[user]++
    }
    END {
      printf "{"
      first = 1
      for (u in c) {
        if (!first) printf ","
        first = 0
        gsub(/"/, "\\\"", u)
        printf "\"%s\":%d", u, c[u]
      }
      printf "}"
    }' "${args[@]}" 2>/dev/null || echo '{}'
}

OVPN_STATUS_FILES=0
while IFS= read -r _ovpn_f; do
  [ -n "$_ovpn_f" ] && OVPN_STATUS_FILES=$((OVPN_STATUS_FILES + 1))
done < <(usk_openvpn_discover_status_files 2>/dev/null || true)

WG_JSON=$(wg_map_json wg0 wg)
AWG_JSON=$(wg_map_json awg0 awg)
XRAY_JSON=$(xray_map_json)
OVPN_JSON=$(openvpn_map_json)
WG_CONN_JSON=$(wg_connections_map_json wg0 wg)
AWG_CONN_JSON=$(wg_connections_map_json awg0 awg)
XRAY_CONN_JSON=$(xray_connections_map_json)
OVPN_CONN_JSON=$(openvpn_connections_map_json)

XRAY_CFG_EMAILS=0
XRAY_API_OK=0
XRAY_BIN=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
if [ -n "$XRAY_BIN" ] && "$XRAY_BIN" api statsquery --server=127.0.0.1:10085 >/dev/null 2>&1; then
  XRAY_API_OK=1
fi
if [ -f "$XRAY_CFG" ] && command -v jq >/dev/null 2>&1; then
  XRAY_CFG_EMAILS=$(jq '[.inbounds[]? | select(.protocol=="vless") | .settings.clients[]?] | length' "$XRAY_CFG" 2>/dev/null || echo 0)
fi

if command -v jq >/dev/null 2>&1; then
  jq -nc \
    --argjson wireguard "$WG_JSON" \
    --argjson amnezia "$AWG_JSON" \
    --argjson xray "$XRAY_JSON" \
    --argjson openvpn "$OVPN_JSON" \
    --argjson wg_conn "$WG_CONN_JSON" \
    --argjson awg_conn "$AWG_CONN_JSON" \
    --argjson xray_conn "$XRAY_CONN_JSON" \
    --argjson ovpn_conn "$OVPN_CONN_JSON" \
    --argjson wg_peers "$(count_json_keys "$WG_JSON")" \
    --argjson awg_peers "$(count_json_keys "$AWG_JSON")" \
    --argjson xray_users "$(count_json_keys "$XRAY_JSON")" \
    --argjson ovpn_users "$(count_json_keys "$OVPN_JSON")" \
    --argjson ovpn_status_files "$OVPN_STATUS_FILES" \
    --argjson xray_cfg_clients "$XRAY_CFG_EMAILS" \
    --argjson xray_api_ok "$XRAY_API_OK" \
    '{
      wireguard: $wireguard,
      amnezia: $amnezia,
      xray: $xray,
      openvpn: $openvpn,
      connections: {
        wireguard: $wg_conn,
        amnezia: $awg_conn,
        xray: $xray_conn,
        openvpn: $ovpn_conn
      },
      ok: true,
      collected_at: (now | todate),
      _meta: {
        wg_peers: $wg_peers,
        awg_peers: $awg_peers,
        xray_users: $xray_users,
        ovpn_users: $ovpn_users,
        ovpn_status_files: $ovpn_status_files,
        xray_cfg_clients: $xray_cfg_clients,
        xray_api_ok: ($xray_api_ok == 1)
      }
    }'
else
  printf '{"wireguard":%s,"amnezia":%s,"xray":%s,"openvpn":%s,"ok":true}\n' \
    "$WG_JSON" "$AWG_JSON" "$XRAY_JSON" "$OVPN_JSON"
fi
