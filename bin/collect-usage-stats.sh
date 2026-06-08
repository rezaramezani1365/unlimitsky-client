#!/bin/bash
# Collect live VPN traffic counters (run as root via sudo from panel).
# stdout = single JSON object only (safe to pipe to jq).
set -o pipefail
export BASH_ENV=
export ENV=

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-stats-state.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/openvpn-common.sh" 2>/dev/null || true

OVPN_STATUS_DIR="${OVPN_STATUS_DIR:-/var/log/openvpn}"
DATA_ROOT="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"

usk_append_xray_pairs_from_panel() {
  local pairs_file="$1"
  local f="${PANEL_ROOT}/data/clients/xray.json"
  [ -f "$f" ] || return 0
  jq -r '
    if type == "object" then
      to_entries[]? | select(.value | type == "object") |
      ((.value.xray_email // .value.usage_id // .value.email // .value.username // .key // "") | tostring) + "\t" + ((.value.uuid // .value.id // "") | tostring)
    else empty end
  ' "$f" 2>/dev/null >>"$pairs_file" || true
}

usk_sanitize_json_obj() {
  local j="$1"
  if [ -z "$j" ]; then
    echo '{}'
    return 0
  fi
  if command -v jq >/dev/null 2>&1; then
    printf '%s' "$j" | jq -c 'if type == "object" then . elif type == "array" then . else {} end' 2>/dev/null || echo '{}'
    return 0
  fi
  echo '{}'
}

usk_sanitize_json_int() {
  local n="${1:-0}"
  n=$(printf '%s' "$n" | tr -cd '0-9')
  [ -n "$n" ] || n=0
  echo "$n"
}

count_json_keys() {
  local j="$1"
  if [ -z "$j" ] || [ "$j" = "{}" ]; then
    echo 0
    return 0
  fi
  if command -v jq >/dev/null 2>&1; then
    usk_sanitize_json_int "$(printf '%s' "$j" | jq 'length' 2>/dev/null || echo 0)"
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

xray_map_json() {
  local bin map='{}' pairs_file cumulative
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  if [ -z "$bin" ] || [ ! -x "$bin" ] || ! command -v jq >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi

  usk_xray_ensure_stats_api_if_needed
  # Update grace/online state (last_traffic_ms) — traffic totals use cumulative API below.
  usk_xray_stats_prime_once
  cumulative=$(usk_xray_cumulative_traffic_map "$bin")
  map="$cumulative"
  [ -n "$map" ] || map='{}'

  pairs_file=$(mktemp)
  usk_xray_build_pairs_file "$pairs_file"
  usk_append_xray_pairs_from_panel "$pairs_file"
  map=$(usk_xray_expand_map_from_pairs "$map" "$pairs_file")
  rm -f "$pairs_file"
  echo "$map"
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
  local bin grace_json access_json state_grace stat_json pairs_file map
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  if [ -z "$bin" ] || [ ! -x "$bin" ] || ! command -v jq >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi

  usk_xray_stats_prime_once
  grace_json="$USK_XRAY_GRACE_CONN_JSON"
  [ -n "$grace_json" ] || grace_json='{}'
  access_json=$(usk_xray_access_log_ip_counts)
  pairs_file=$(mktemp)
  usk_xray_build_pairs_file "$pairs_file"
  state_grace=$(usk_xray_grace_conn_from_state "$pairs_file")

  stat_json=$("$bin" api statsonline --server=127.0.0.1:10085 2>/dev/null | jq -c '
    (.users // {}) | if type == "object" then
      to_entries | map({key: .key, value: (
        if (.value | type) == "array" then (.value | length)
        elif (.value | type) == "object" then (.value | keys | length)
        else (if .value then 1 else 0 end) end
      )}) | from_entries
    else {} end
  ' 2>/dev/null || echo '{}')

  map=$(usk_xray_merge_connection_sources "$access_json" "$grace_json" "$state_grace" "$stat_json" "$pairs_file")
  rm -f "$pairs_file"
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

usk_occtl() {
  local sock=""
  if [ -f /etc/ocserv/ocserv.conf ]; then
    sock=$(grep -E '^[[:space:]]*socket-file' /etc/ocserv/ocserv.conf 2>/dev/null | head -n1 | sed -E 's/^[^=]*=[[:space:]]*//' | tr -d '"'\''\r' | sed -E 's/[[:space:]]+$//')
  fi
  if [ -n "$sock" ] && [ -S "$sock" ]; then
    occtl -s "$sock" "$@" 2>/dev/null
  else
    occtl "$@" 2>/dev/null
  fi
}

# Cisco/ocserv per-user cumulative bytes for the active session (uplink+downlink).
cisco_map_json() {
  command -v occtl >/dev/null 2>&1 || { echo '{}'; return 0; }
  command -v jq >/dev/null 2>&1 || { echo '{}'; return 0; }
  usk_occtl -j show users | jq -c '
    (if type == "array" then . else [] end)
    | reduce .[]? as $u ({};
        (($u.Username // $u.username // "") | tostring) as $name
        | if ($name | length) > 0 and $name != "(none)" then
            ((($u["_RX_"] // $u.RX // $u.rx // 0) | tonumber? // 0)
             + (($u["_TX_"] // $u.TX // $u.tx // 0) | tonumber? // 0)) as $b
            | . + {($name): ((.[$name] // 0) + $b)}
          else . end)
  ' 2>/dev/null || echo '{}'
}

cisco_connections_map_json() {
  command -v occtl >/dev/null 2>&1 || { echo '{}'; return 0; }
  command -v jq >/dev/null 2>&1 || { echo '{}'; return 0; }
  usk_occtl -j show users | jq -c '
    (if type == "array" then . else [] end)
    | reduce .[]? as $u ({};
        (($u.Username // $u.username // "") | tostring) as $name
        | if ($name | length) > 0 and $name != "(none)" then
            . + {($name): ((.[$name] // 0) + 1)}
          else . end)
  ' 2>/dev/null || echo '{}'
}

# L2TP/xl2tpd per-user bytes from active ppp interfaces.
# The iface->username map is written by the pppd ip-up hook (setup-l2tp-usage.sh).
l2tp_map_json() {
  command -v jq >/dev/null 2>&1 || { echo '{}'; return 0; }
  local mapdir="${DATA_ROOT}/l2tp/iface"
  local out='{}' d iface user rx tx b
  for d in /sys/class/net/ppp*; do
    [ -d "$d" ] || continue
    iface=$(basename "$d")
    user=""
    [ -f "${mapdir}/${iface}" ] && user=$(tr -d '\r\n' < "${mapdir}/${iface}" 2>/dev/null)
    [ -n "$user" ] || continue
    rx=$(cat "$d/statistics/rx_bytes" 2>/dev/null | tr -cd '0-9'); [ -n "$rx" ] || rx=0
    tx=$(cat "$d/statistics/tx_bytes" 2>/dev/null | tr -cd '0-9'); [ -n "$tx" ] || tx=0
    b=$((rx + tx))
    out=$(printf '%s' "$out" | jq -c --arg u "$user" --argjson b "$b" '. + {($u): ((.[$u] // 0) + $b)}' 2>/dev/null || printf '%s' "$out")
  done
  printf '%s' "$out"
}

l2tp_connections_map_json() {
  command -v jq >/dev/null 2>&1 || { echo '{}'; return 0; }
  local mapdir="${DATA_ROOT}/l2tp/iface"
  local out='{}' d iface user
  for d in /sys/class/net/ppp*; do
    [ -d "$d" ] || continue
    iface=$(basename "$d")
    user=""
    [ -f "${mapdir}/${iface}" ] && user=$(tr -d '\r\n' < "${mapdir}/${iface}" 2>/dev/null)
    [ -n "$user" ] || continue
    out=$(printf '%s' "$out" | jq -c --arg u "$user" '. + {($u): ((.[$u] // 0) + 1)}' 2>/dev/null || printf '%s' "$out")
  done
  printf '%s' "$out"
}

OVPN_STATUS_FILES=0
while IFS= read -r _ovpn_f; do
  [ -n "$_ovpn_f" ] && OVPN_STATUS_FILES=$((OVPN_STATUS_FILES + 1))
done < <(usk_openvpn_discover_status_files 2>/dev/null || true)

WG_JSON=$(wg_map_json wg0 wg)
AWG_JSON=$(wg_map_json awg0 awg)
if command -v jq >/dev/null 2>&1 && [ -f "${PANEL_ROOT}/data/clients/wireguard.json" ]; then
  while IFS= read -r pk; do
    [ -z "$pk" ] && continue
    WG_JSON=$(echo "$WG_JSON" | jq -c --arg k "$pk" '. + {($k): (.[$k] // 0)}' 2>/dev/null || echo "$WG_JSON")
  done < <(jq -r 'to_entries[]? | select(.value.public_key? != null) | .value.public_key' "${PANEL_ROOT}/data/clients/wireguard.json" 2>/dev/null || true)
fi
XRAY_JSON=$(xray_map_json)
OVPN_JSON=$(openvpn_map_json)
CISCO_JSON=$(cisco_map_json)
L2TP_JSON=$(l2tp_map_json)
WG_CONN_JSON=$(wg_connections_map_json wg0 wg)
AWG_CONN_JSON=$(wg_connections_map_json awg0 awg)
XRAY_CONN_JSON=$(xray_connections_map_json)
OVPN_CONN_JSON=$(openvpn_connections_map_json)
CISCO_CONN_JSON=$(cisco_connections_map_json)
L2TP_CONN_JSON=$(l2tp_connections_map_json)

WG_JSON=$(usk_sanitize_json_obj "$WG_JSON")
AWG_JSON=$(usk_sanitize_json_obj "$AWG_JSON")
XRAY_JSON=$(usk_sanitize_json_obj "$XRAY_JSON")
OVPN_JSON=$(usk_sanitize_json_obj "$OVPN_JSON")
CISCO_JSON=$(usk_sanitize_json_obj "$CISCO_JSON")
L2TP_JSON=$(usk_sanitize_json_obj "$L2TP_JSON")
WG_CONN_JSON=$(usk_sanitize_json_obj "$WG_CONN_JSON")
AWG_CONN_JSON=$(usk_sanitize_json_obj "$AWG_CONN_JSON")
XRAY_CONN_JSON=$(usk_sanitize_json_obj "$XRAY_CONN_JSON")
OVPN_CONN_JSON=$(usk_sanitize_json_obj "$OVPN_CONN_JSON")
CISCO_CONN_JSON=$(usk_sanitize_json_obj "$CISCO_CONN_JSON")
L2TP_CONN_JSON=$(usk_sanitize_json_obj "$L2TP_CONN_JSON")

XRAY_CFG_EMAILS=0
XRAY_API_OK=0
XRAY_BIN=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
if [ -n "$XRAY_BIN" ] && "$XRAY_BIN" api statsquery --server=127.0.0.1:10085 -reset=false >/dev/null 2>&1; then
  XRAY_API_OK=1
fi
if [ -f "$XRAY_CFG" ] && command -v jq >/dev/null 2>&1; then
  XRAY_CFG_EMAILS=$(jq '[.inbounds[]? | select(.protocol=="vless") | .settings.clients[]?] | length' "$XRAY_CFG" 2>/dev/null || echo 0)
fi
XRAY_ACCESS_LOG_OK=0
XRAY_ACCESS_LOG_BYTES=0
_xray_access_log=$(usk_xray_access_log_path "$XRAY_CFG" 2>/dev/null || true)
if [ -n "$_xray_access_log" ] && [ -r "$_xray_access_log" ]; then
  XRAY_ACCESS_LOG_OK=1
  XRAY_ACCESS_LOG_BYTES=$(usk_sanitize_json_int "$(wc -c <"$_xray_access_log" 2>/dev/null || echo 0)")
fi

COLLECTED_AT=$(date -Iseconds 2>/dev/null || date -u +%Y-%m-%dT%H:%M:%SZ)
WG_PEERS=$(usk_sanitize_json_int "$(count_json_keys "$WG_JSON")")
AWG_PEERS=$(usk_sanitize_json_int "$(count_json_keys "$AWG_JSON")")
XRAY_USERS=$(usk_sanitize_json_int "$(count_json_keys "$XRAY_JSON")")
XRAY_POSITIVE_USERS=0
if command -v jq >/dev/null 2>&1; then
  XRAY_POSITIVE_USERS=$(usk_sanitize_json_int "$(printf '%s' "$XRAY_JSON" | jq '[to_entries[]? | select((.value | tonumber) > 0)] | length' 2>/dev/null || echo 0)")
fi
OVPN_USERS=$(usk_sanitize_json_int "$(count_json_keys "$OVPN_JSON")")
CISCO_USERS=$(usk_sanitize_json_int "$(count_json_keys "$CISCO_JSON")")
L2TP_USERS=$(usk_sanitize_json_int "$(count_json_keys "$L2TP_JSON")")
XRAY_CFG_EMAILS=$(usk_sanitize_json_int "$XRAY_CFG_EMAILS")
XRAY_API_OK=$(usk_sanitize_json_int "$XRAY_API_OK")
XRAY_ACCESS_LOG_OK=$(usk_sanitize_json_int "$XRAY_ACCESS_LOG_OK")
XRAY_ACCESS_LOG_BYTES=$(usk_sanitize_json_int "$XRAY_ACCESS_LOG_BYTES")
OVPN_STATUS_FILES=$(usk_sanitize_json_int "$OVPN_STATUS_FILES")

_emit_ok=0
if command -v python3 >/dev/null 2>&1; then
  if USK_COLLECT_JSON_WG="$WG_JSON" \
     USK_COLLECT_JSON_AWG="$AWG_JSON" \
     USK_COLLECT_JSON_XRAY="$XRAY_JSON" \
     USK_COLLECT_JSON_OVPN="$OVPN_JSON" \
     USK_COLLECT_JSON_CISCO="$CISCO_JSON" \
     USK_COLLECT_JSON_L2TP="$L2TP_JSON" \
     USK_COLLECT_JSON_WG_CONN="$WG_CONN_JSON" \
     USK_COLLECT_JSON_AWG_CONN="$AWG_CONN_JSON" \
     USK_COLLECT_JSON_XRAY_CONN="$XRAY_CONN_JSON" \
     USK_COLLECT_JSON_OVPN_CONN="$OVPN_CONN_JSON" \
     USK_COLLECT_JSON_CISCO_CONN="$CISCO_CONN_JSON" \
     USK_COLLECT_JSON_L2TP_CONN="$L2TP_CONN_JSON" \
     USK_COLLECT_AT="$COLLECTED_AT" \
     USK_COLLECT_WG_PEERS="$WG_PEERS" \
     USK_COLLECT_AWG_PEERS="$AWG_PEERS" \
     USK_COLLECT_XRAY_USERS="$XRAY_USERS" \
     USK_COLLECT_XRAY_POSITIVE_USERS="$XRAY_POSITIVE_USERS" \
     USK_COLLECT_OVPN_USERS="$OVPN_USERS" \
     USK_COLLECT_CISCO_USERS="$CISCO_USERS" \
     USK_COLLECT_L2TP_USERS="$L2TP_USERS" \
     USK_COLLECT_OVPN_STATUS_FILES="$OVPN_STATUS_FILES" \
     USK_COLLECT_XRAY_CFG_CLIENTS="$XRAY_CFG_EMAILS" \
     USK_COLLECT_XRAY_API_OK="$XRAY_API_OK" \
     USK_COLLECT_XRAY_ACCESS_LOG_OK="$XRAY_ACCESS_LOG_OK" \
     USK_COLLECT_XRAY_ACCESS_LOG_BYTES="$XRAY_ACCESS_LOG_BYTES" \
     python3 - <<'PY'
import json, os

def obj(name, default="{}"):
    raw = os.environ.get(name, default) or default
    try:
        val = json.loads(raw)
        return val if isinstance(val, dict) else {}
    except Exception:
        return {}

def num(name, default=0):
    try:
        return int(os.environ.get(name, str(default)) or default)
    except Exception:
        return default

out = {
    "wireguard": obj("USK_COLLECT_JSON_WG"),
    "amnezia": obj("USK_COLLECT_JSON_AWG"),
    "xray": obj("USK_COLLECT_JSON_XRAY"),
    "openvpn": obj("USK_COLLECT_JSON_OVPN"),
    "cisco": obj("USK_COLLECT_JSON_CISCO"),
    "l2tp": obj("USK_COLLECT_JSON_L2TP"),
    "connections": {
        "wireguard": obj("USK_COLLECT_JSON_WG_CONN"),
        "amnezia": obj("USK_COLLECT_JSON_AWG_CONN"),
        "xray": obj("USK_COLLECT_JSON_XRAY_CONN"),
        "openvpn": obj("USK_COLLECT_JSON_OVPN_CONN"),
        "cisco": obj("USK_COLLECT_JSON_CISCO_CONN"),
        "l2tp": obj("USK_COLLECT_JSON_L2TP_CONN"),
    },
    "ok": True,
    "collected_at": os.environ.get("USK_COLLECT_AT", ""),
    "_meta": {
        "wg_peers": num("USK_COLLECT_WG_PEERS"),
        "awg_peers": num("USK_COLLECT_AWG_PEERS"),
        "xray_users": num("USK_COLLECT_XRAY_USERS"),
        "xray_positive_users": num("USK_COLLECT_XRAY_POSITIVE_USERS"),
        "ovpn_users": num("USK_COLLECT_OVPN_USERS"),
        "cisco_users": num("USK_COLLECT_CISCO_USERS"),
        "l2tp_users": num("USK_COLLECT_L2TP_USERS"),
        "ovpn_status_files": num("USK_COLLECT_OVPN_STATUS_FILES"),
        "xray_cfg_clients": num("USK_COLLECT_XRAY_CFG_CLIENTS"),
        "xray_api_ok": num("USK_COLLECT_XRAY_API_OK") == 1,
        "xray_traffic_mode": "cumulative",
        "xray_stats_api": "127.0.0.1:10085",
        "xray_access_log_ok": num("USK_COLLECT_XRAY_ACCESS_LOG_OK") == 1,
        "xray_access_log_bytes": num("USK_COLLECT_XRAY_ACCESS_LOG_BYTES"),
    },
}
print(json.dumps(out, ensure_ascii=False))
PY
  then
    _emit_ok=1
  fi
fi

if [ "$_emit_ok" -eq 0 ] && command -v jq >/dev/null 2>&1; then
  _out_tmp=$(mktemp)
  _jq_err=$(mktemp)
  if jq -nc \
    --argjson wireguard "$WG_JSON" \
    --argjson amnezia "$AWG_JSON" \
    --argjson xray "$XRAY_JSON" \
    --argjson openvpn "$OVPN_JSON" \
    --argjson cisco "$CISCO_JSON" \
    --argjson l2tp "$L2TP_JSON" \
    --argjson wg_conn "$WG_CONN_JSON" \
    --argjson awg_conn "$AWG_CONN_JSON" \
    --argjson xray_conn "$XRAY_CONN_JSON" \
    --argjson ovpn_conn "$OVPN_CONN_JSON" \
    --argjson cisco_conn "$CISCO_CONN_JSON" \
    --argjson l2tp_conn "$L2TP_CONN_JSON" \
    --arg collected_at "$COLLECTED_AT" \
    --argjson wg_peers "$WG_PEERS" \
    --argjson awg_peers "$AWG_PEERS" \
    --argjson xray_users "$XRAY_USERS" \
    --argjson xray_positive_users "$XRAY_POSITIVE_USERS" \
    --argjson ovpn_users "$OVPN_USERS" \
    --argjson cisco_users "$CISCO_USERS" \
    --argjson l2tp_users "$L2TP_USERS" \
    --argjson ovpn_status_files "$OVPN_STATUS_FILES" \
    --argjson xray_cfg_clients "$XRAY_CFG_EMAILS" \
    --argjson xray_api_ok "$XRAY_API_OK" \
    --argjson xray_access_log_ok "$XRAY_ACCESS_LOG_OK" \
    --argjson xray_access_log_bytes "$XRAY_ACCESS_LOG_BYTES" \
    '{
      wireguard: $wireguard,
      amnezia: $amnezia,
      xray: $xray,
      openvpn: $openvpn,
      cisco: $cisco,
      l2tp: $l2tp,
      connections: {
        wireguard: $wg_conn,
        amnezia: $awg_conn,
        xray: $xray_conn,
        openvpn: $ovpn_conn,
        cisco: $cisco_conn,
        l2tp: $l2tp_conn
      },
      ok: true,
      collected_at: $collected_at,
      _meta: {
        wg_peers: $wg_peers,
        awg_peers: $awg_peers,
        xray_users: $xray_users,
        xray_positive_users: $xray_positive_users,
        ovpn_users: $ovpn_users,
        cisco_users: $cisco_users,
        l2tp_users: $l2tp_users,
        ovpn_status_files: $ovpn_status_files,
        xray_cfg_clients: $xray_cfg_clients,
        xray_api_ok: ($xray_api_ok == 1),
        xray_traffic_mode: "cumulative",
        xray_stats_api: "127.0.0.1:10085",
        xray_access_log_ok: ($xray_access_log_ok == 1),
        xray_access_log_bytes: $xray_access_log_bytes
      }
    }' >"$_out_tmp" 2>"$_jq_err"; then
    if [ -s "$_out_tmp" ]; then
      cat "$_out_tmp"
      _emit_ok=1
    fi
  fi
  rm -f "$_out_tmp" "$_jq_err"
fi

if [ "$_emit_ok" -eq 0 ]; then
  printf '{"ok":false,"error":"json_build_failed"}\n'
fi
