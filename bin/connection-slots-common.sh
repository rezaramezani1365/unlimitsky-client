#!/bin/bash
# Shared helpers: max_connections lookup + per-user IP slot state.
set -uo pipefail

usk_slots_panel_root() {
  echo "${PANEL_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
}

usk_slots_data_root() {
  echo "${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
}

usk_slots_ip_state_file() {
  echo "$(usk_slots_data_root)/xray/client-ips.json"
}

usk_slots_stale_sec() {
  echo "${USK_SLOT_STALE_SEC:-600}"
}

usk_slots_load_max() {
  local protocol="$1"
  local username="$2"
  local panel_file reg_file max=""
  panel_file="$(usk_slots_panel_root)/data/clients/${protocol}.json"
  reg_file="$(usk_slots_data_root)/${protocol}/clients.json"
  if [ -f "$panel_file" ] && command -v jq >/dev/null 2>&1; then
    max=$(jq -r --arg u "$username" '.[$u] | (.max_connections // .meta.max_connections // empty)' "$panel_file" 2>/dev/null || true)
  fi
  if [ -z "$max" ] || [ "$max" = "null" ]; then
    if [ -f "$reg_file" ] && command -v jq >/dev/null 2>&1; then
      max=$(jq -r --arg u "$username" '.[] | select(.username==$u) | (.max_connections // 1)' "$reg_file" 2>/dev/null | head -1)
    fi
  fi
  max=${max:-1}
  [ "$max" -ge 1 ] 2>/dev/null || max=1
  [ "$max" -le 99 ] 2>/dev/null || max=99
  echo "$max"
}

usk_slots_client_active() {
  local protocol="$1"
  local username="$2"
  local panel_file
  panel_file="$(usk_slots_panel_root)/data/clients/${protocol}.json"
  [ -f "$panel_file" ] || return 0
  command -v jq >/dev/null 2>&1 || return 0
  local st
  st=$(jq -r --arg u "$username" '.[$u].status // "active"' "$panel_file" 2>/dev/null || echo active)
  [ "$st" = "active" ]
}

usk_slots_read_state() {
  local f
  f=$(usk_slots_ip_state_file)
  [ -f "$f" ] || { echo '{}'; return 0; }
  cat "$f" 2>/dev/null || echo '{}'
}

usk_slots_write_state() {
  local json="$1"
  local f dir
  f=$(usk_slots_ip_state_file)
  dir=$(dirname "$f")
  mkdir -p "$dir" 2>/dev/null || true
  printf '%s' "$json" >"${f}.tmp.$$" 2>/dev/null && mv "${f}.tmp.$$" "$f" 2>/dev/null || true
}

# Returns JSON array of fresh {ip,timestamp} for email key in xray state.
usk_slots_fresh_ips_json() {
  local email="$1"
  local state now stale
  state=$(usk_slots_read_state)
  now=$(date +%s)
  stale=$(usk_slots_stale_sec)
  echo "$state" | jq -c --arg e "$email" --argjson now "$now" --argjson stale "$stale" '
    (.[$e] // []) |
    map(select((.timestamp // 0 | tonumber) > 0 and ($now - (.timestamp | tonumber)) <= $stale))
  ' 2>/dev/null || echo '[]'
}

usk_slots_register_ip() {
  local email="$1"
  local ip="$2"
  local now state merged
  now=$(date +%s)
  state=$(usk_slots_read_state)
  merged=$(echo "$state" | jq -c --arg e "$email" --arg ip "$ip" --argjson ts "$now" --argjson stale "$(usk_slots_stale_sec)" '
    (.[$e] // []) as $old |
    ($old | map(select((.timestamp // 0 | tonumber) > 0 and ($ts - (.timestamp | tonumber)) <= $stale))) as $fresh |
    ($fresh | map(select(.ip != $ip))) + [{ip:$ip, timestamp:$ts}] |
    unique_by(.ip)
  ' 2>/dev/null || echo "[{\"ip\":\"$ip\",\"timestamp\":$now}]")
  state=$(echo "$state" | jq -c --arg e "$email" --argjson ips "$merged" '. + {($e): $ips}' 2>/dev/null || echo "{\"$email\":$merged}")
  usk_slots_write_state "$state"
}

usk_slots_remove_ip() {
  local email="$1"
  local ip="$2"
  local state
  state=$(usk_slots_read_state)
  state=$(echo "$state" | jq -c --arg e "$email" --arg ip "$ip" '
    if .[$e] then .[$e] = (.[$e] | map(select(.ip != $ip))) else . end
  ' 2>/dev/null || echo "$state")
  usk_slots_write_state "$state"
}
