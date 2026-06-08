#!/bin/bash
# 3x-ui-style Xray stats: delta traffic + grace-period online + access-log IP counts.
# Sourced by collect-usage-stats.sh (not run directly).
# Do not change shell options here — collect uses many echo|jq pipelines.

USK_XRAY_ONLINE_GRACE_MS="${USK_XRAY_ONLINE_GRACE_MS:-90000}"
USK_XRAY_ACCESS_TAIL_LINES="${USK_XRAY_ACCESS_TAIL_LINES:-8000}"

usk_xray_stats_state_path() {
  local root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  echo "${root}/xray/stats-state.json"
}

usk_xray_access_log_path() {
  local cfg="${1:-${XRAY_CFG:-}}"
  if [ -n "$cfg" ] && [ -f "$cfg" ] && command -v jq >/dev/null 2>&1; then
    local p
    p=$(jq -r '.log.access // empty' "$cfg" 2>/dev/null | head -1)
    if [ -n "$p" ] && [ "$p" != "none" ] && [ "$p" != "null" ]; then
      if [[ "$p" != /* ]]; then
        local dir
        dir=$(dirname "$cfg")
        echo "${dir}/${p#./}"
      else
        echo "$p"
      fi
      return 0
    fi
  fi
  local root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  echo "${root}/xray/access.log"
}

usk_xray_stats_api_endpoint() {
  echo "127.0.0.1:10085"
}

usk_xray_statsquery_raw() {
  local bin="$1"
  local ep
  ep=$(usk_xray_stats_api_endpoint)
  "$bin" api statsquery --server="$ep" -reset=false 2>/dev/null || true
}

# Cumulative uplink+downlink per client email from Xray StatsService (same data as xray-exporter).
usk_xray_cumulative_traffic_map() {
  local bin="$1"
  local raw
  if [ -z "$bin" ] || [ ! -x "$bin" ] || ! command -v jq >/dev/null 2>&1; then
    echo '{}'
    return 0
  fi
  raw=$(usk_xray_statsquery_raw "$bin")
  if [ -z "$raw" ]; then
    echo '{}'
    return 0
  fi
  echo "$raw" | jq -c '
    reduce ((.stat // .stats // [])[]? | select(.name? != null)) as $s ({};
      ($s.name | tostring) as $name |
      if ($name | test("^user>>>[^>]+>>>traffic>>>")) then
        ($name | split(">>>")) as $p |
        if ($p | length) >= 4 and ($p[1] | length) > 0 then
          . + {($p[1]): ((.[$p[1]] // 0) + (($s.value // 0) | tonumber))}
        else . end
      else . end
    )
  ' 2>/dev/null || echo '{}'
}

usk_xray_ensure_stats_api_if_needed() {
  local bin cfg fix
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  [ -n "$bin" ] && [ -x "$bin" ] || return 0
  if "$bin" api statsquery --server="$(usk_xray_stats_api_endpoint)" >/dev/null 2>&1; then
    return 0
  fi
  cfg="${XRAY_CFG:-/usr/local/etc/xray/config.json}"
  [ -f "$cfg" ] || return 0
  fix="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/xray-fix-stats-api.sh"
  if [ -f "$fix" ] && [ "$(id -u)" -eq 0 ]; then
    bash "$fix" >/dev/null 2>&1 || true
  fi
}

# Build panel/xray email+uuid pairs list (tab-separated) into $1.
usk_xray_build_pairs_file() {
  local pairs_file="$1"
  local panel_root="${PANEL_ROOT:-$(dirname "$(dirname "${BASH_SOURCE[0]}")")}"
  local data_root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  : >"$pairs_file"
  if [ -f "${XRAY_CFG:-}" ]; then
    jq -r '.inbounds[]? | select(.protocol=="vless") | .settings.clients[]? | (.email // "") + "\t" + (.id // "")' \
      "$XRAY_CFG" 2>/dev/null >>"$pairs_file" || true
  fi
  if [ -f "${data_root}/xray/clients.json" ]; then
    jq -r '.[]? | ((.xray_email // .usage_id // .email // .username // "") | tostring) + "\t" + ((.uuid // .id // "") | tostring)' \
      "${data_root}/xray/clients.json" 2>/dev/null >>"$pairs_file" || true
  fi
  if [ -f "${panel_root}/data/clients/xray.json" ]; then
    jq -r '
      if type == "object" then
        to_entries[]? | select(.value | type == "object") |
        ((.value.xray_email // .value.usage_id // .value.email // .value.username // .key // "") | tostring) + "\t" + ((.value.uuid // .value.id // "") | tostring)
      else empty end
    ' "${panel_root}/data/clients/xray.json" 2>/dev/null >>"$pairs_file" || true
  fi
  sort -u "$pairs_file" -o "$pairs_file" 2>/dev/null || true
}

usk_xray_expand_map_from_pairs() {
  local map_json="$1"
  local pairs_file="$2"
  if ! command -v jq >/dev/null 2>&1 || [ ! -s "$pairs_file" ]; then
    echo "${map_json:-{}}"
    return 0
  fi
  jq -nc --argjson m "${map_json:-{}}" --rawfile pairs "$pairs_file" '
    ($pairs | split("\n") | map(select(length > 0) | split("\t")) |
     map({email: (.[0] // ""), uuid: (.[1] // "")}) |
     map(select(.email != ""))) as $rows |
    reduce $rows[] as $r ($m;
      (($m[$r.email] // 0) | tonumber) as $v |
      ((.[$r.email] // 0) | tonumber) as $curr_e |
      (if $v > $curr_e then $v else $curr_e end) as $max_e |
      . + {($r.email): $max_e}
      | if ($r.uuid | length) > 0 then
          ((.[$r.uuid] // 0) | tonumber) as $curr_u |
          (if $v > $curr_u then $v else $curr_u end) as $max_u |
          . + {($r.uuid): $max_u}
        else . end
    )
  ' 2>/dev/null || echo "${map_json:-{}}"
}

usk_xray_grace_conn_from_state() {
  local pairs_file="$1"
  local state_file now_ms grace
  state_file=$(usk_xray_stats_state_path)
  now_ms=$(($(date +%s) * 1000))
  grace="${USK_XRAY_ONLINE_GRACE_MS:-90000}"
  [ -f "$state_file" ] || { echo '{}'; return 0; }
  if ! command -v jq >/dev/null 2>&1 || [ ! -s "$pairs_file" ]; then
    echo '{}'
    return 0
  fi
  jq -nc \
    --argjson now "$now_ms" \
    --argjson grace "$grace" \
    --rawfile pairs "$pairs_file" \
    --slurpfile st "$state_file" '
    ($st[0].last_traffic_ms // {}) as $lt |
    ($pairs | split("\n") | map(select(length > 0) | split("\t")) |
     map({email: (.[0] // ""), uuid: (.[1] // "")}) | map(select(.email != ""))) as $rows |
    reduce $rows[] as $r ({};
      ($lt[$r.email] // 0 | tonumber) as $ts |
      (if $ts > 0 and ($now - $ts) <= $grace then 1 else 0 end) as $c |
      . + {($r.email): $c} + (if ($r.uuid | length) > 0 then {($r.uuid): $c} else {} end)
    )
  ' 2>/dev/null || echo '{}'
}

usk_xray_merge_connection_sources() {
  local access_json="$1"
  local grace_json="$2"
  local state_grace_json="$3"
  local stat_json="$4"
  local pairs_file="$5"
  jq -nc \
    --argjson access "${access_json:-{}}" \
    --argjson grace "${grace_json:-{}}" \
    --argjson state_grace "${state_grace_json:-{}}" \
    --argjson stat "${stat_json:-{}}" \
    --rawfile pairs "$pairs_file" '
    ($pairs | split("\n") | map(select(length > 0) | split("\t")) |
     map({email: (.[0] // ""), uuid: (.[1] // "")}) | map(select(.email != ""))) as $rows |
    reduce $rows[] as $r ({};
      ($r.email) as $e |
      ($r.uuid) as $u |
      (
        if (($access[$e] // 0) | tonumber) > 0 then ($access[$e] | tonumber)
        elif (($stat[$e] // 0) | tonumber) > 0 then ($stat[$e] | tonumber)
        elif (($state_grace[$e] // 0) | tonumber) > 0 then 1
        elif (($grace[$e] // 0) | tonumber) > 0 then 1
        else 0 end
      ) as $cnt |
      ((.[$e] // 0) | tonumber) as $curr_e |
      (if $cnt > $curr_e then $cnt else $curr_e end) as $max_e |
      . + {($e): $max_e}
      | if ($u | length) > 0 then
          ((.[$u] // 0) | tonumber) as $curr_u |
          (if $cnt > $curr_u then $cnt else $curr_u end) as $max_u |
          . + {($u): $max_u}
        else . end
    )
  ' 2>/dev/null || echo '{}'
}

usk_xray_apply_traffic_deltas() {
  local raw="$1"
  USK_XRAY_DELTA_JSON='{}'
  USK_XRAY_GRACE_CONN_JSON='{}'

  if [ -z "$raw" ] || ! command -v jq >/dev/null 2>&1; then
    return 0
  fi

  local state_file now_ms
  state_file=$(usk_xray_stats_state_path)
  now_ms=$(($(date +%s) * 1000))
  mkdir -p "$(dirname "$state_file")" 2>/dev/null || true

  local state='{"last_counters":{},"last_traffic_ms":{}}'
  if [ -f "$state_file" ]; then
    state=$(jq -c 'if type == "object" then . else {} end' "$state_file" 2>/dev/null || echo "$state")
  fi

  local tmp_out
  tmp_out=$(mktemp)

  echo "$raw" | jq -c --argjson now "$now_ms" --argjson grace "$USK_XRAY_ONLINE_GRACE_MS" --argjson state "$state" '
    ($state.last_counters // {}) as $init_counters |
    ($state.last_traffic_ms // {}) as $init_traffic |
    reduce ((.stat // .stats // [])[]? | select(.name? != null)) as $s (
      {deltas: {}, counters: $init_counters, traffic_ms: $init_traffic};
      . as $acc |
      ($s.name | tostring) as $name |
      (($s.value // 0) | tonumber) as $cur |
      ($acc.counters[$name] // null) as $prev |
      ($acc.counters + {($name): $cur}) as $next_counters |
      if ($name | test("^user>>>[^>]+>>>traffic>>>")) then
        ($name | capture("^user>>>(?<email>[^>]+)>>>traffic>>>")) as $cap |
        if ($prev != null and $cur >= $prev and ($cur - $prev) > 0) then
          ($acc.deltas[$cap.email] // 0) as $old |
          $acc
          | .deltas += {($cap.email): ($old + ($cur - $prev))}
          | .counters = $next_counters
          | .traffic_ms += {($cap.email): $now}
        elif ($cur > 0) then
          $acc | .counters = $next_counters | .traffic_ms += {($cap.email): $now}
        else
          $acc | .counters = $next_counters
        end
      else
        $acc | .counters = $next_counters
      end
    ) as $out |
    ($out.traffic_ms | to_entries | map(
      select(($now - (.value | tonumber)) <= grace) |
      {key: .key, value: 1}
    ) | from_entries) as $grace |
    {
      deltas: ($out.deltas // {}),
      grace: $grace,
      state: {
        last_counters: $out.counters,
        last_traffic_ms: $out.traffic_ms,
        updated_at: ($now | tostring)
      }
    }
  ' 2>/dev/null >"$tmp_out" || echo '{"deltas":{},"grace":{},"state":{"last_counters":{},"last_traffic_ms":{}}}' >"$tmp_out"

  local new_state
  new_state=$(jq -c '.state // {}' "$tmp_out" 2>/dev/null || echo '{}')
  if [ -n "$new_state" ] && [ "$new_state" != "{}" ]; then
    echo "$new_state" >"$state_file" 2>/dev/null || true
  fi

  USK_XRAY_DELTA_JSON=$(jq -c '.deltas // {}' "$tmp_out" 2>/dev/null || echo '{}')
  USK_XRAY_GRACE_CONN_JSON=$(jq -c '.grace // {}' "$tmp_out" 2>/dev/null || echo '{}')
  rm -f "$tmp_out"
}

usk_xray_stats_prime_once() {
  if [ -n "${USK_XRAY_STATS_PRIMED:-}" ]; then
    return 0
  fi
  local bin
  bin=$(usk_xray_bin 2>/dev/null || command -v xray 2>/dev/null || true)
  if [ -z "$bin" ] || [ ! -x "$bin" ]; then
    USK_XRAY_STATS_PRIMED=1
    USK_XRAY_DELTA_JSON='{}'
    USK_XRAY_GRACE_CONN_JSON='{}'
    return 0
  fi
  usk_xray_apply_traffic_deltas "$(usk_xray_statsquery_raw "$bin")"
  USK_XRAY_STATS_PRIMED=1
}

usk_xray_access_log_ip_counts() {
  local log
  log=$(usk_xray_access_log_path "$XRAY_CFG")
  if [ ! -r "$log" ]; then
    echo '{}'
    return 0
  fi
  if [ ! -s "$log" ]; then
    echo '{}'
    return 0
  fi

  tail -n "$USK_XRAY_ACCESS_TAIL_LINES" "$log" 2>/dev/null | awk '
    /email:/ && / from / && /accepted/ {
      email = ""
      ip = ""
      for (i = 1; i <= NF; i++) {
        if ($i == "email:" && (i + 1) <= NF) email = $(i + 1)
        if ($i == "from" && (i + 1) <= NF) {
          split($(i + 1), parts, ":")
          ip = parts[1]
          gsub(/^\[/, "", ip)
        }
      }
      if (email != "" && ip ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) {
        key = email SUBSEP ip
        if (!(key in seen)) {
          seen[key] = 1
          counts[email]++
        }
      }
    }
    END {
      printf "{"
      first = 1
      for (em in counts) {
        if (!first) printf ","
        first = 0
        gsub(/"/, "\\\"", em)
        printf "\"%s\":%d", em, counts[em]
      }
      printf "}"
    }'
}

usk_xray_access_log_ip_counts_for_email() {
  local email="$1"
  local all count
  [ -n "$email" ] || { echo 0; return 0; }
  all=$(usk_xray_access_log_ip_counts)
  count=$(echo "$all" | jq -r --arg e "$email" '.[$e] // 0' 2>/dev/null || echo 0)
  echo "${count:-0}"
}
