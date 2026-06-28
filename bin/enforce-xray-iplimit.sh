#!/bin/bash
# Parse Xray access log, enforce max_connections via fail2ban log (3x-ui CheckClientIpJob pattern).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/provision-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-common.sh" 2>/dev/null || true
# shellcheck disable=SC1091
source "$DIR/xray-stats-state.sh" 2>/dev/null || true

PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
DATA_ROOT="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
CLIENTS_DIR="${PANEL_ROOT}/data/clients"
IP_STATE="${DATA_ROOT}/xray/client-ips.json"
IP_LIMIT_LOG="${DATA_ROOT}/xray/iplimit.log"
IP_STALE_SEC="${USK_IP_STALE_SEC:-1800}"
ACCESS_TAIL="${USK_XRAY_ACCESS_TAIL_LINES:-8000}"

if [ "$EUID" -ne 0 ]; then
  echo '{"ok":false,"error":"run_as_root"}'
  exit 1
fi

command -v jq >/dev/null 2>&1 || { echo '{"ok":false,"error":"jq_required"}'; exit 1; }

mkdir -p "${DATA_ROOT}/xray" 2>/dev/null || true
touch "$IP_LIMIT_LOG" 2>/dev/null || true
chmod 644 "$IP_LIMIT_LOG" 2>/dev/null || true
[ -f "$IP_STATE" ] || echo '{}' >"$IP_STATE"

access_log=$(usk_xray_access_log_path "$XRAY_CFG" 2>/dev/null || true)
if [ -z "$access_log" ] || [ ! -r "$access_log" ]; then
  jq -nc '{ok:true, skipped:"no_access_log", checked:0, banned:0, disconnected:0}'
  exit 0
fi

load_max_for_email() {
  local email="$1"
  local panel_file="${CLIENTS_DIR}/xray.json"
  local reg_file="${DATA_ROOT}/xray/clients.json"
  local max=""
  if [ -f "$panel_file" ]; then
    max=$(jq -r --arg e "$email" '.[$e] | (.max_connections // .meta.max_connections // empty)' "$panel_file" 2>/dev/null || true)
  fi
  if [ -z "$max" ] || [ "$max" = "null" ]; then
    if [ -f "$reg_file" ]; then
      max=$(jq -r --arg e "$email" '.[] | select(.username==$e) | (.max_connections // 1)' "$reg_file" 2>/dev/null | head -1)
    fi
  fi
  max=${max:-1}
  [ "$max" -ge 1 ] 2>/dev/null || max=1
  echo "$max"
}

client_active() {
  local email="$1"
  local panel_file="${CLIENTS_DIR}/xray.json"
  [ -f "$panel_file" ] || return 0
  local st
  st=$(jq -r --arg e "$email" '.[$e].status // "active"' "$panel_file" 2>/dev/null || echo active)
  [ "$st" = "active" ]
}

usk_xray_bounce_user() {
  local email="$1"
  local cfg="${2:-$XRAY_CFG}"
  local bin tag uuid tmp_adu
  [ -f "$cfg" ] || return 1
  bin=$(usk_xray_bin 2>/dev/null) || return 1
  tag=$(usk_xray_vless_inbound_tag "$cfg")
  [ -n "$tag" ] || return 1
  uuid=$(jq -r --arg e "$email" '
    .inbounds[]? | select(.protocol=="vless") | .settings.clients[]? |
    select(.email==$e) | .id // empty
  ' "$cfg" 2>/dev/null | head -1)
  [ -n "$uuid" ] || return 1

  "$bin" api rmu --server=127.0.0.1:10085 -tag="$tag" "$email" 2>/dev/null || true
  sleep 0.15
  tmp_adu=$(mktemp)
  jq -nc --arg tag "$tag" --arg id "$uuid" --arg email "$email" '
    {inbounds:[{tag:$tag, users:[{email:$email, account:{id:$id, flow:"xtls-rprx-vision", level:0}}]}]}
  ' >"$tmp_adu"
  "$bin" api adu --server=127.0.0.1:10085 "$tmp_adu" 2>/dev/null || true
  rm -f "$tmp_adu"
}

parse_tmp=$(mktemp)
tail -n "$ACCESS_TAIL" "$access_log" 2>/dev/null | awk '
  /email:/ && / from / && /accepted/ {
    email = ""
    ip = ""
    ts = systime()
    n = split($0, f, " ")
    for (i = 1; i <= n; i++) {
      if (f[i] == "email:" && (i + 1) <= n) email = f[i + 1]
      if (f[i] == "from" && (i + 1) <= n) {
        split(f[i + 1], p, ":")
        ip = p[1]
        gsub(/^\[/, "", ip)
      }
    }
    if (email != "" && ip ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/ && ip != "127.0.0.1") {
      print email "\t" ip "\t" ts
    }
  }
' >"$parse_tmp" || : >"$parse_tmp"

observed=$(jq -nc --rawfile raw "$parse_tmp" '
  ($raw | split("\n") | map(select(length > 0) | split("\t")) |
   map(select(length >= 2) | {email: .[0], ip: .[1], timestamp: ((.[2] // "0") | tonumber)})) |
  group_by(.email) |
  map({email: .[0].email, ips: (group_by(.ip) | map({ip: .[0].ip, timestamp: (map(.timestamp) | max)}))})
')

checked=0
banned=0
disconnected=0
now=$(date +%s)
state=$(cat "$IP_STATE" 2>/dev/null || echo '{}')

while IFS= read -r row; do
  [ -n "$row" ] || continue
  email=$(echo "$row" | jq -r '.email // empty')
  [ -n "$email" ] || continue
  client_active "$email" || continue

  max=$(load_max_for_email "$email")
  checked=$((checked + 1))

  live_json=$(echo "$row" | jq -c '.ips // []')
  merged=$(echo "$state" | jq -c --arg e "$email" --argjson live "$live_json" --argjson now "$now" --argjson stale "$IP_STALE_SEC" '
    (.[$e] // []) as $old |
    ($old | map(select(($now - .timestamp) <= $stale))) as $oldfresh |
    ($live | map({ip, timestamp})) as $newlive |
    ($newlive + ($oldfresh | map(select(. as $o | ($newlive | map(.ip) | index($o.ip)) | not)))) |
    unique_by(.ip) | sort_by(.timestamp)
  ' 2>/dev/null || echo "$live_json")

  live_count=$(echo "$merged" | jq 'length' 2>/dev/null || echo 0)
  if [ "${live_count:-0}" -le "$max" ] 2>/dev/null; then
    state=$(echo "$state" | jq -c --arg e "$email" --argjson ips "$merged" '. + {($e): $ips}')
    continue
  fi

  ban_count=$((live_count - max))
  to_ban=$(echo "$merged" | jq -c --argjson n "$ban_count" '.[:$n]')
  kept=$(echo "$merged" | jq -c --argjson n "$ban_count" '.[$n:]')

  while IFS= read -r entry; do
    [ -n "$entry" ] || continue
    bip=$(echo "$entry" | jq -r '.ip // empty')
    bts=$(echo "$entry" | jq -r '.timestamp // 0')
    [ -n "$bip" ] || continue
    printf '[LIMIT_IP] Email = %s || Disconnecting OLD IP = %s || Timestamp = %s\n' \
      "$email" "$bip" "${bts:-$now}" >>"$IP_LIMIT_LOG"
    banned=$((banned + 1))
  done < <(echo "$to_ban" | jq -c '.[]?' 2>/dev/null)

  if usk_xray_bounce_user "$email" "$XRAY_CFG"; then
    disconnected=$((disconnected + 1))
  fi
  state=$(echo "$state" | jq -c --arg e "$email" --argjson ips "$kept" '. + {($e): $ips}')
done < <(echo "$observed" | jq -c '.[]?' 2>/dev/null)

echo "$state" >"$IP_STATE" 2>/dev/null || true
rm -f "$parse_tmp"

if command -v fail2ban-client >/dev/null 2>&1 && [ "$banned" -gt 0 ] 2>/dev/null; then
  fail2ban-client reload usk-ipl 2>/dev/null || fail2ban-client reload 2>/dev/null || true
fi

jq -nc \
  --argjson checked "$checked" \
  --argjson banned "$banned" \
  --argjson disconnected "$disconnected" \
  '{ok:true, checked:$checked, banned:$banned, disconnected:$disconnected, method:"fail2ban_iplimit"}'

exit 0
