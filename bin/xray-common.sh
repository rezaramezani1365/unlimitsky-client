#!/bin/bash
# Shared Xray helpers — VLESS + Reality (Iran-optimized, IPv4-only clients)
XRAY_CFG="${XRAY_CFG:-/usr/local/etc/xray/config.json}"

usk_xray_resolve_cfg() {
  if [ -n "${XRAY_CFG:-}" ] && [ -f "$XRAY_CFG" ]; then
    export XRAY_CFG
    return 0
  fi
  if [ -f /usr/local/etc/xray/config.json ]; then
    XRAY_CFG=/usr/local/etc/xray/config.json
  elif [ -f /etc/xray/config.json ]; then
    XRAY_CFG=/etc/xray/config.json
  else
    XRAY_CFG="${XRAY_CFG:-/usr/local/etc/xray/config.json}"
  fi
  export XRAY_CFG
}

usk_xray_resolve_cfg
USK_XRAY_VLESS_PORT="${USK_XRAY_VLESS_PORT:-443}"
USK_XRAY_REALITY_FILE="${USK_DATA_ROOT:-/var/lib/unlimitsky}/xray/reality.params"
USK_XRAY_DEFAULT_DEST="www.microsoft.com:443"
USK_XRAY_DEFAULT_SNI="www.microsoft.com"
USK_XRAY_DEFAULT_FP="chrome"

usk_xray_bin() {
  if command -v xray >/dev/null 2>&1; then
    command -v xray
    return 0
  fi
  if [ -x /usr/local/bin/xray ]; then
    echo /usr/local/bin/xray
    return 0
  fi
  return 1
}

usk_xray_port_in_use() {
  local port="$1"
  ss -tlnp 2>/dev/null | grep -q ":${port} "
}

usk_xray_pick_free_port() {
  local want="$1"
  local p="$want"
  local tries=0
  while usk_xray_port_in_use "$p"; do
    p=$((p + 1))
    tries=$((tries + 1))
    if [ "$tries" -gt 50 ]; then
      echo "$want"
      return
    fi
  done
  echo "$p"
}

usk_xray_fix_perms() {
  local cfg="$1"
  local dir
  dir=$(dirname "$cfg")
  mkdir -p "$dir"
  chmod 755 "$dir" 2>/dev/null || true
  if [ -f "$cfg" ]; then
    chmod 644 "$cfg" 2>/dev/null || true
    chown root:root "$cfg" 2>/dev/null || true
  fi
}

usk_xray_validate_clients_json() {
  local raw="$1"
  echo "$raw" | jq -e 'type == "array"' >/dev/null 2>&1
}

usk_xray_reality_parse_kv() {
  local line="$1"
  local key val
  key=$(echo "$line" | sed -E 's/^([^: ]+)[[:space:]:].*/\1/' | tr '[:upper:]' '[:lower:]' | tr -d ' ')
  val=$(echo "$line" | sed -E 's/^[^:]*:[[:space:]]*//; s/^[[:space:]]+//')
  case "$key" in
    privatekey|private) echo "priv=$val" ;;
    publickey|public) echo "pub=$val" ;;
    password) echo "pub=$val" ;;
  esac
}

usk_xray_reality_gen_keys() {
  local xray_bin out priv pub line kv
  xray_bin=$(usk_xray_bin) || return 1
  out=$("$xray_bin" x25519 2>&1) || return 1
  priv=""
  pub=""
  while IFS= read -r line; do
    [ -z "$line" ] && continue
    kv=$(usk_xray_reality_parse_kv "$line")
    case "$kv" in
      priv=*) priv="${kv#priv=}" ;;
      pub=*) pub="${kv#pub=}" ;;
    esac
  done <<EOF
$out
EOF
  if [ -n "$priv" ] && [ -z "$pub" ]; then
    out=$("$xray_bin" x25519 -i "$priv" 2>&1) || true
    while IFS= read -r line; do
      kv=$(usk_xray_reality_parse_kv "$line")
      case "$kv" in
        pub=*) pub="${kv#pub=}" ;;
      esac
    done <<EOF2
$out
EOF2
  fi
  if [ -z "$priv" ] || [ -z "$pub" ]; then
    echo "USK_ERR: xray_x25519_parse_failed" >&2
    echo "$out" | tail -6 >&2
    return 1
  fi
  REALITY_PRIVATE_KEY="$priv"
  REALITY_PUBLIC_KEY="$pub"
}

usk_xray_reality_gen_short_id() {
  openssl rand -hex 4 2>/dev/null || cat /proc/sys/kernel/random/uuid | tr -d '-' | cut -c1-8
}

usk_xray_ensure_reality_params() {
  mkdir -p "$(dirname "$USK_XRAY_REALITY_FILE")"
  if [ -f "$USK_XRAY_REALITY_FILE" ]; then
    # shellcheck disable=SC1090
    . "$USK_XRAY_REALITY_FILE"
    if [ -n "${REALITY_PRIVATE_KEY:-}" ] && [ -n "${REALITY_PUBLIC_KEY:-}" ]; then
      return 0
    fi
    rm -f "$USK_XRAY_REALITY_FILE" 2>/dev/null || true
  fi
  if ! usk_xray_reality_gen_keys; then
    return 1
  fi
  local sid
  sid=$(usk_xray_reality_gen_short_id)
  cat > "$USK_XRAY_REALITY_FILE" <<EOF
REALITY_DEST=${USK_XRAY_DEFAULT_DEST}
REALITY_SNI=${USK_XRAY_DEFAULT_SNI}
REALITY_PRIVATE_KEY=${REALITY_PRIVATE_KEY}
REALITY_PUBLIC_KEY=${REALITY_PUBLIC_KEY}
REALITY_SHORT_IDS=${sid}
REALITY_FINGERPRINT=${USK_XRAY_DEFAULT_FP}
EOF
  chmod 600 "$USK_XRAY_REALITY_FILE"
}

usk_xray_load_reality() {
  usk_xray_ensure_reality_params || return 1
  # shellcheck disable=SC1090
  . "$USK_XRAY_REALITY_FILE"
  REALITY_DEST="${REALITY_DEST:-$USK_XRAY_DEFAULT_DEST}"
  REALITY_SNI="${REALITY_SNI:-$USK_XRAY_DEFAULT_SNI}"
  REALITY_FINGERPRINT="${REALITY_FINGERPRINT:-$USK_XRAY_DEFAULT_FP}"
  REALITY_SHORT_IDS="${REALITY_SHORT_IDS:-,}"
  [ -n "${REALITY_PRIVATE_KEY:-}" ] && [ -n "${REALITY_PUBLIC_KEY:-}" ] || return 1
}

usk_xray_reality_short_id_for_client() {
  local ids="${REALITY_SHORT_IDS:-,}"
  local sid
  sid=$(echo "$ids" | tr ',' '\n' | grep -E '^[0-9a-fA-F]{2,16}$' | head -1)
  if [ -z "$sid" ]; then
    sid=$(usk_xray_reality_gen_short_id)
    REALITY_SHORT_IDS="${ids},${sid}"
    if [ -f "$USK_XRAY_REALITY_FILE" ]; then
      sed -i "s/^REALITY_SHORT_IDS=.*/REALITY_SHORT_IDS=${REALITY_SHORT_IDS}/" "$USK_XRAY_REALITY_FILE" 2>/dev/null || true
    fi
  fi
  echo "$sid"
}

usk_xray_reality_short_ids_json() {
  local ids="${REALITY_SHORT_IDS:-}"
  local sid
  sid=$(echo "$ids" | tr ',' '\n' | grep -E '^[0-9a-fA-F]{2,16}$' | head -1)
  if [ -n "$sid" ]; then
    jq -cn --arg s "$sid" '["", $s]'
  else
    echo '[""]'
  fi
}

usk_xray_load_clients() {
  local cfg="$1"
  if [ ! -f "$cfg" ] || ! command -v jq >/dev/null 2>&1; then
    echo '[]'
    return
  fi
  jq -c '
    [.inbounds[]? | select(.protocol == "vless") | .settings.clients[]?
     | select(.id != null and (.id | type) == "string"
       and (.id | length) == 36
       and ((.id | split("-") | map(length)) == [8,4,4,4,12]))
     | {id, email: ((.email // "user") | tostring), flow: ((.flow // "xtls-rprx-vision") | tostring)}
    ] | unique_by(.id) | group_by(.email) | map(last)
  ' "$cfg" 2>/dev/null || echo '[]'
}

usk_xray_normalize_clients() {
  local raw="$1"
  echo "$raw" | jq -c '
    [.[]? | select(.id != null) | {
      id: .id,
      email: ((.email // "user") | tostring),
      flow: "xtls-rprx-vision"
    }]
    | unique_by(.id)
    | group_by(.email) | map(last)
  ' 2>/dev/null || echo '[]'
}

usk_xray_write_config() {
  local cfg="$1"
  local vless_json="$2"
  local vless_port="$3"
  local tmp short_ids_json

  usk_xray_load_reality || return 1
  vless_json=$(usk_xray_normalize_clients "$vless_json")
  usk_xray_validate_clients_json "$vless_json" || vless_json='[]'
  short_ids_json=$(usk_xray_reality_short_ids_json)

  tmp=$(mktemp)
  if ! jq -n \
    --argjson vless "$vless_json" \
    --argjson vless_port "$vless_port" \
    --arg dest "$REALITY_DEST" \
    --arg sni "$REALITY_SNI" \
    --arg priv "$REALITY_PRIVATE_KEY" \
    --argjson shortIds "$short_ids_json" \
    '{
      log: { loglevel: "warning", access: "/var/lib/unlimitsky/xray/access.log" },
      stats: {},
      api: {
        tag: "api",
        services: ["StatsService"]
      },
      policy: {
        levels: { "0": { statsUserUplink: true, statsUserDownlink: true, statsUserOnline: true } },
        system: { statsInboundUplink: true, statsInboundDownlink: true }
      },
      inbounds: [
        {
          listen: "127.0.0.1",
          port: 10085,
          protocol: "dokodemo-door",
          settings: { address: "127.0.0.1" },
          tag: "api"
        },
        {
          listen: "0.0.0.0",
          port: $vless_port,
          protocol: "vless",
          tag: "vless-reality-in",
          settings: { clients: $vless, decryption: "none" },
          streamSettings: {
            network: "tcp",
            security: "reality",
            realitySettings: {
              show: false,
              dest: $dest,
              xver: 0,
              serverNames: [$sni],
              privateKey: $priv,
              shortIds: $shortIds
            }
          },
          sniffing: { enabled: true, destOverride: ["http", "tls"] }
        }
      ],
      outbounds: [
        { protocol: "freedom", tag: "direct", settings: { domainStrategy: "UseIPv4" } },
        { protocol: "freedom", tag: "api", settings: {} },
        { protocol: "blackhole", tag: "block" }
      ],
      routing: {
        domainStrategy: "IPIfNonMatch",
        rules: [
          { type: "field", inboundTag: ["api"], outboundTag: "api" },
          { type: "field", inboundTag: ["vless-reality-in"], outboundTag: "direct" }
        ]
      }
    }' > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  if ! jq empty "$tmp" 2>/dev/null; then
    rm -f "$tmp"
    return 1
  fi
  mkdir -p "$(dirname "$cfg")"
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
  return 0
}

usk_xray_migrate_legacy_config() {
  local cfg="$1"
  [ -f "$cfg" ] || return 1
  local security
  security=$(jq -r '(.inbounds[]? | select(.protocol=="vless") | .streamSettings.security) // "none"' "$cfg" 2>/dev/null | head -1)
  [ "$security" = "reality" ] && return 1
  local clients port
  clients=$(usk_xray_load_clients "$cfg")
  port=$(jq -r '(.inbounds[]? | select(.protocol=="vless") | .port) // 443' "$cfg" 2>/dev/null | head -1)
  port=$(echo "$port" | tr -dc '0-9')
  [ -n "$port" ] || port=443
  [ "$port" -gt 65535 ] 2>/dev/null && port=443
  usk_xray_write_config "$cfg" "$clients" "$port"
}

usk_xray_resolve_connect_host() {
  local panel_root="${1:-${PANEL_ROOT:-}}"
  local host=""
  if [ -n "$panel_root" ] && [ -f "${panel_root}/data/settings/connect-host.json" ] && command -v jq >/dev/null 2>&1; then
    host=$(jq -r 'if (.enabled // false) and ((.connect_host // "") != "") then .connect_host else empty end' \
      "${panel_root}/data/settings/connect-host.json" 2>/dev/null || true)
  fi
  if [ -n "$host" ]; then
    echo "$host"
    return 0
  fi
  if [ -n "${USK_CONNECT_HOST_ARG:-}" ]; then
    echo "$USK_CONNECT_HOST_ARG"
    return 0
  fi
  if [ -n "${USK_SERVER_IP:-}" ]; then
    echo "$USK_SERVER_IP"
    return 0
  fi
  if declare -F usk_server_ip >/dev/null 2>&1; then
    host=$(usk_server_ip 2>/dev/null || true)
    [ -n "$host" ] && echo "$host" && return 0
  fi
  host=$(hostname -I 2>/dev/null | awk '{print $1}')
  [ -n "$host" ] && echo "$host"
}

usk_xray_uri_encode() {
  local raw="$1"
  python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1], safe='-_.~'))" "$raw" 2>/dev/null \
    || jq -nr --arg s "$raw" '$s|@uri' 2>/dev/null \
    || echo "$raw" | sed 's/[#?&]/_/g'
}

usk_xray_build_vless_uri() {
  local uuid="$1"
  local host="$2"
  local port="$3"
  local name="$4"
  local pub="$5"
  local sni="$6"
  local sid="$7"
  local fp="${8:-chrome}"
  name=$(usk_xray_uri_encode "$(echo "$name" | sed 's/[#?&]/_/g')")
  sni=$(usk_xray_uri_encode "$sni")
  pub=$(usk_xray_uri_encode "$pub")
  sid=$(usk_xray_uri_encode "$sid")
  fp=$(usk_xray_uri_encode "$fp")
  printf 'vless://%s@%s:%s?encryption=none&flow=xtls-rprx-vision&security=reality&sni=%s&fp=%s&pbk=%s&sid=%s&spx=%%2F&type=tcp#%s' \
    "$uuid" "$host" "$port" "$sni" "$fp" "$pub" "$sid" "$name"
}

usk_xray_collect_all_clients_json() {
  local cfg="${1:-$XRAY_CFG}"
  local panel_root="${2:-${PANEL_ROOT:-}}"
  [ -n "$panel_root" ] || panel_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd || echo /var/www/unlimitsky)"
  command -v jq >/dev/null 2>&1 || { usk_xray_load_clients "$cfg"; return; }

  local data_root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  local panel_file="${panel_root}/data/clients/xray.json"
  local reg_file="${data_root}/xray/clients.json"
  local cfg_json panel_json reg_json
  cfg_json=$(usk_xray_load_clients "$cfg")
  panel_json='[]'
  reg_json='[]'
  [ -f "$panel_file" ] && panel_json=$(jq -c '
    to_entries | map({
      id: (.value.uuid // .value.id // .value.meta.uuid // ""),
      email: (.value.xray_email // .value.usage_id // .value.email // .value.username // .key // "user"),
      flow: "xtls-rprx-vision",
      level: 0,
      status: (.value.status // "active")
    }) | map(select(.id != ""))
  ' "$panel_file" 2>/dev/null || echo '[]')
  [ -f "$reg_file" ] && reg_json=$(jq -c '
    if type == "array" then
      map(select(.uuid? // .id? // "") != "") | map({
        id: (.uuid // .id // ""),
        email: (.xray_email // .usage_id // .email // .username // "user"),
        flow: "xtls-rprx-vision",
        level: 0,
        status: (.status // "active")
      })
    elif type == "object" then
      to_entries | map(select(.value.uuid? // .value.id? // "") != "") | map({
        id: (.value.uuid // .value.id // ""),
        email: (.value.xray_email // .value.usage_id // .value.email // .value.username // .key),
        flow: "xtls-rprx-vision",
        level: 0,
        status: (.value.status // "active")
      })
    else [] end
  ' "$reg_file" 2>/dev/null || echo '[]')

  jq -s '
    def by_id: reduce .[] as $c ({}; if ($c.id // "") != "" then . + {($c.id): $c} else . end);
    (.[0] | by_id) as $cfg | (.[1] | by_id) as $panel | (.[2] | by_id) as $reg |
    ($cfg + $panel + $reg) | to_entries | map(.value)
    | map(select(.status? // "active" == "active"))
    | map({id, email, flow: "xtls-rprx-vision", level: 0})
    | unique_by(.id)
    | group_by(.email) | map(last)
  ' <(echo "$cfg_json") <(echo "$panel_json") <(echo "$reg_json") 2>/dev/null || echo "$cfg_json"
}

usk_xray_link_for_uuid() {
  local uuid="$1"
  local label="${2:-user-vless}"
  local host="${3:-}"
  usk_xray_load_reality || return 1
  local port sid fp
  port=$(usk_xray_vless_port_from_config "$XRAY_CFG" 2>/dev/null || echo 443)
  sid=$(usk_xray_reality_short_id_for_client)
  fp="${REALITY_FINGERPRINT:-chrome}"
  if [ -z "$host" ]; then
    host=$(usk_xray_resolve_connect_host "${PANEL_ROOT:-}") || true
  fi
  [ -n "$host" ] || return 1
  usk_xray_build_vless_uri "$uuid" "$host" "$port" "$label" \
    "$REALITY_PUBLIC_KEY" "$REALITY_SNI" "$sid" "$fp"
}

usk_xray_refresh_stored_links() {
  local panel_root="${1:-${PANEL_ROOT:-}}"
  local data_root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  [ -n "$panel_root" ] || panel_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd || echo /var/www/unlimitsky)"
  command -v jq >/dev/null 2>&1 || return 1
  usk_xray_load_reality || return 1

  local host
  host=$(usk_xray_resolve_connect_host "$panel_root") || true
  [ -n "$host" ] || return 1

  local panel_file="${panel_root}/data/clients/xray.json"
  local reg_file="${data_root}/xray/clients.json"
  local updated=0

  while IFS=$'\t' read -r username uuid; do
    [ -n "$uuid" ] || continue
    [ -n "$username" ] || username="user"
    local link
    link=$(usk_xray_link_for_uuid "$uuid" "${username}-vless" "$host") || continue
    updated=$((updated + 1))

    if [ -f "$panel_file" ]; then
      local tmp2
      tmp2=$(mktemp)
      if jq --arg u "$username" --arg l "$link" '
        if .[$u] then
          .[$u].vless = $l |
          .[$u].links = $l |
          .[$u].subscription_url = $l |
          (if .[$u].meta then .[$u].meta.vless = $l else . end)
        else . end
      ' "$panel_file" > "$tmp2" 2>/dev/null; then
        mv "$tmp2" "$panel_file"
      else
        rm -f "$tmp2"
      fi
    fi

    if [ -f "$reg_file" ]; then
      local tmp3
      tmp3=$(mktemp)
      if jq --arg u "$username" --arg l "$link" '
        if type == "array" then
          map(if (.username // "") == $u then . + {vless: $l} else . end)
        elif type == "object" then
          if .[$u] then .[$u].vless = $l else . end
        else . end
      ' "$reg_file" > "$tmp3" 2>/dev/null; then
        mv "$tmp3" "$reg_file"
      else
        rm -f "$tmp3"
      fi
    fi
  done < <(
    usk_xray_collect_all_clients_json "$XRAY_CFG" "$panel_root" 2>/dev/null \
      | jq -r '.[] | "\(.email // "user")\t\(.id // "")"' 2>/dev/null
  )

  echo "$updated"
  return 0
}

usk_xray_parse_client_dns() {
  local raw="$1"
  raw=$(echo "$raw" | tr ',;' '  ' | tr -s ' ')
  local -a arr=()
  local part
  for part in $raw; do
    part=$(echo "$part" | tr -d ' ')
    [ -z "$part" ] && continue
    if echo "$part" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$'; then
      arr+=("$part")
    elif echo "$part" | grep -qE '^[a-zA-Z0-9.-]+$'; then
      arr+=("$part")
    fi
  done
  if [ "${#arr[@]}" -eq 0 ]; then
    echo '[]'
  else
    printf '%s\n' "${arr[@]}" | jq -R . | jq -s .
  fi
}

usk_xray_build_client_json() {
  local uuid="$1"
  local host="$2"
  local port="$3"
  local name="$4"
  local dns_csv="$5"
  usk_xray_load_reality || return 1
  local sid fp dns_json
  sid=$(usk_xray_reality_short_id_for_client)
  fp="${REALITY_FINGERPRINT:-chrome}"
  dns_json=$(usk_xray_parse_client_dns "$dns_csv")
  jq -n \
    --arg uuid "$uuid" \
    --arg host "$host" \
    --argjson port "$port" \
    --arg name "$name" \
    --arg sni "$REALITY_SNI" \
    --arg pub "$REALITY_PUBLIC_KEY" \
    --arg sid "$sid" \
    --arg fp "$fp" \
    --argjson dnsServers "$dns_json" \
    '{
      log: { loglevel: "warning", access: "/var/lib/unlimitsky/xray/access.log" },
      dns: (
        if ($dnsServers | length) > 0 then
          { servers: $dnsServers, queryStrategy: "UseIPv4" }
        else
          { queryStrategy: "UseIPv4" }
        end
      ),
      inbounds: [
        { tag: "socks-in", port: 10808, listen: "127.0.0.1", protocol: "socks", settings: { udp: true } },
        { tag: "http-in", port: 10809, listen: "127.0.0.1", protocol: "http" }
      ],
      outbounds: [
        {
          tag: "proxy",
          protocol: "vless",
          settings: {
            vnext: [{
              address: $host,
              port: $port,
              users: [{ id: $uuid, encryption: "none", flow: "xtls-rprx-vision" }]
            }]
          },
          streamSettings: {
            network: "tcp",
            security: "reality",
            realitySettings: {
              serverName: $sni,
              fingerprint: $fp,
              publicKey: $pub,
              shortId: $sid,
              spiderX: ""
            }
          }
        },
        { tag: "direct", protocol: "freedom", settings: { domainStrategy: "UseIPv4" } },
        { tag: "block", protocol: "blackhole" }
      ],
      routing: {
        domainStrategy: "IPIfNonMatch",
        rules: [{ type: "field", outboundTag: "proxy", network: "tcp,udp" }]
      },
      remarks: $name
    }'
}

usk_xray_add_client() {
  local cfg="$1"
  local uuid="$2"
  local email="$3"
  local tmp existing_id
  existing_id=$(jq -r --arg email "$email" '
    [.inbounds[]? | select(.protocol == "vless") | .settings.clients[]?
     | select(.email == $email) | .id] | first // empty
  ' "$cfg" 2>/dev/null || true)
  if [ -n "$existing_id" ] && [ "$existing_id" != "$uuid" ]; then
    uuid="$existing_id"
  fi
  tmp=$(mktemp)
  if ! jq --arg id "$uuid" --arg email "$email" '
    .inbounds |= map(
      if .protocol == "vless" then
        .settings.clients = ((.settings.clients // [])
          | map(select(.id != $id and .email != $email))
          | . + [{id: $id, email: $email, flow: "xtls-rprx-vision", level: 0}])
      else . end
    )' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_remove_client() {
  local cfg="$1"
  local uuid="$2"
  local tmp
  tmp=$(mktemp)
  if ! jq --arg id "$uuid" '
    .inbounds |= map(
      if .protocol == "vless" then
        .settings.clients = [.settings.clients[]? | select(.id != $id)]
      else . end
    )' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_vless_inbound_tag() {
  local cfg="$1"
  jq -r '.inbounds[]? | select(.protocol=="vless") | .tag // empty' "$cfg" 2>/dev/null | head -1
}

usk_xray_user_online_count() {
  local email="$1"
  local bin val
  bin=$(usk_xray_bin 2>/dev/null) || { echo 0; return 0; }
  val=$("$bin" api stats --server=127.0.0.1:10085 -name "user>>>${email}>>>online" 2>/dev/null \
    | jq -r '.stat.value // .value // 0' 2>/dev/null || echo 0)
  echo "${val:-0}"
}

usk_xray_online_ips_for_email() {
  local email="$1"
  local bin raw
  bin=$(usk_xray_bin 2>/dev/null) || return 0
  raw=$("$bin" api statsonline --server=127.0.0.1:10085 2>/dev/null || true)
  [ -n "$raw" ] || return 0
  echo "$raw" | jq -r --arg e "$email" '
    if ((.users // null) | type) == "object" then
      .users[$e][]? // empty
    elif ((.users // null) | type) == "array" then
      .users[] | select(.email? == $e or .name? == $e) | (.ips[]? // empty)
    else
      empty
    end
  ' 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u
}

usk_xray_vless_port_from_config() {
  local cfg="${1:-$XRAY_CFG}"
  jq -r '(.inbounds[]? | select(.protocol=="vless") | .port) // 443' "$cfg" 2>/dev/null | head -1
}

usk_xray_slot_chain_reset() {
  local port="$1"
  iptables -N USK_XRAY_CONN 2>/dev/null || true
  iptables -F USK_XRAY_CONN
  iptables -C INPUT -j USK_XRAY_CONN 2>/dev/null || iptables -I INPUT 1 -j USK_XRAY_CONN
  iptables -C USK_XRAY_CONN -p tcp --dport "$port" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null \
    || iptables -A USK_XRAY_CONN -p tcp --dport "$port" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
}

usk_xray_clear_slot_iptables() {
  local port="${1:-}"
  if ! command -v iptables >/dev/null 2>&1; then
    return 0
  fi
  while iptables -C INPUT -j USK_XRAY_CONN 2>/dev/null; do
    iptables -D INPUT -j USK_XRAY_CONN 2>/dev/null || break
  done
  if iptables -L USK_XRAY_CONN >/dev/null 2>&1; then
    iptables -F USK_XRAY_CONN 2>/dev/null || true
    iptables -X USK_XRAY_CONN 2>/dev/null || true
  fi
  while iptables -S INPUT 2>/dev/null | grep -q "USK_XRAY_CONN"; do
    iptables -D INPUT -j USK_XRAY_CONN 2>/dev/null || break
  done
  if [ -n "$port" ]; then
    while iptables -S INPUT 2>/dev/null | grep -E "REJECT.*dport ${port}" >/dev/null 2>&1; do
      line=$(iptables -S INPUT 2>/dev/null | grep -E "REJECT.*dport ${port}" | head -1)
      [ -n "$line" ] || break
      iptables -D INPUT ${line#-A INPUT } 2>/dev/null || break
    done
  fi
}

usk_xray_port_has_relay_dnat() {
  local port="$1"
  [ -n "$port" ] || return 1
  command -v iptables >/dev/null 2>&1 || return 1
  iptables -t nat -S PREROUTING 2>/dev/null | grep -qE -- "--dport ${port} .*DNAT"
}

usk_node_clear_relay_rules() {
  local bin_dir="${1:-}"
  [ -n "$bin_dir" ] || bin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  local rm_script="${bin_dir}/remove-node-relay.sh"
  if [ ! -x "$rm_script" ]; then
    return 0
  fi
  if [ "$EUID" -eq 0 ]; then
    /bin/bash "$rm_script" 2>/dev/null || true
  else
    sudo -n /bin/bash "$rm_script" 2>/dev/null || true
  fi
}

usk_xray_reject_ip_on_port() {
  local ip="$1"
  local port="$2"
  [ -n "$ip" ] && [ -n "$port" ] || return 1
  iptables -C USK_XRAY_CONN -p tcp -s "$ip" --dport "$port" -j REJECT 2>/dev/null \
    && return 0
  iptables -A USK_XRAY_CONN -p tcp -s "$ip" --dport "$port" -j REJECT --reject-with tcp-reset 2>/dev/null || true
}

usk_xray_enforce_slot_limit() {
  local email="$1"
  local max="$2"
  local cfg="${3:-$XRAY_CFG}"
  local port ip_list ip_count kicked i ip
  [ -f "$cfg" ] || return 0
  max=${max:-1}
  [ "$max" -ge 1 ] 2>/dev/null || max=1
  port=$(usk_xray_vless_port_from_config "$cfg")
  port=${port:-443}

  mapfile -t ip_list < <(usk_xray_online_ips_for_email "$email")
  ip_count=${#ip_list[@]}

  if [ "$ip_count" -eq 0 ]; then
    echo 0
    return 0
  fi

  if [ "$ip_count" -le "$max" ]; then
    echo 0
    return 0
  fi

  kicked=0
  i=0
  for ip in "${ip_list[@]}"; do
    i=$((i + 1))
    if [ "$i" -gt "$max" ]; then
      usk_xray_reject_ip_on_port "$ip" "$port" && kicked=$((kicked + 1))
    fi
  done
  echo "$kicked"
}

usk_xray_rebuild_clients_in_config() {
  local cfg="${1:-$XRAY_CFG}"
  local panel_root="${2:-${PANEL_ROOT:-}}"
  local force="${3:-0}"
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  [ -n "$panel_root" ] || panel_root="$(cd "$(dirname "$cfg")/../.." 2>/dev/null && pwd || echo /var/www/unlimitsky)"

  local data_root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  local panel_file="${panel_root}/data/clients/xray.json"
  local reg_file="${data_root}/xray/clients.json"
  local empty='[]'
  local panel_json='[]'
  local reg_json='[]'
  [ -f "$panel_file" ] && panel_json=$(cat "$panel_file")
  [ -f "$reg_file" ] && reg_json=$(cat "$reg_file")

  local clients_json
  clients_json=$(jq -s --arg empty "$empty" '
    def as_map:
      if type == "array" then
        reduce .[] as $r ({}; if ($r.username // "") != "" then . + {($r.username): $r} else . end)
      elif type == "object" then . else {} end;
    (.[0] | as_map) as $p | (.[1] | as_map) as $r |
    ($p + $r) | to_entries | map(select(.value.status? // "active" == "active")) |
    map({
      id: (.value.uuid // .value.id // .value.meta.uuid // ""),
      email: (.value.xray_email // .value.usage_id // .value.email // .value.username // .key // "user"),
      flow: "xtls-rprx-vision",
      level: 0
    }) | map(select(.id != "" and .email != ""))
  ' <(echo "$panel_json") <(echo "$reg_json") 2>/dev/null)

  if [ -z "$clients_json" ] || [ "$clients_json" = "null" ] || [ "$clients_json" = "[]" ]; then
    return 1
  fi

  local current_count new_count
  current_count=$(jq '[.inbounds[]? | select(.protocol=="vless") | .settings.clients[]?] | length' "$cfg" 2>/dev/null || echo 0)
  new_count=$(echo "$clients_json" | jq 'length' 2>/dev/null || echo 0)
  if [ "${new_count:-0}" -lt 1 ]; then
    return 1
  fi
  if [ "$force" != "1" ] && [ "${current_count:-0}" -gt 0 ] && [ "${new_count:-0}" -lt "${current_count:-0}" ]; then
    echo "USK_WARN: rebuild skipped (would remove clients: ${current_count} -> ${new_count})" >&2
    return 1
  fi

  local cfg_clients merged
  cfg_clients=$(usk_xray_load_clients "$cfg")
  merged=$(jq -s '
    def by_id: reduce .[] as $c ({}; if ($c.id // "") != "" then . + {($c.id): $c} else . end);
    (.[0] | by_id) + (.[1] | by_id) | to_entries | map(.value)
    | unique_by(.id) | group_by(.email) | map(last)
  ' <(echo "$cfg_clients") <(echo "$clients_json") 2>/dev/null || echo "$clients_json")
  [ -n "$merged" ] && [ "$merged" != "null" ] && clients_json="$merged"

  local tmp
  tmp=$(mktemp)
  if ! jq --argjson clients "$clients_json" '
    .inbounds |= map(
      if .protocol == "vless" then
        .settings.clients = $clients
      else . end
    )' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
  return 0
}

usk_xray_force_sync_panel_clients() {
  usk_xray_rebuild_clients_in_config "${1:-$XRAY_CFG}" "${2:-${PANEL_ROOT:-}}" 1
}

usk_xray_strip_bad_routing() {
  local cfg="$1"
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  local tmp
  tmp=$(mktemp)
  if ! jq '
    .routing.rules = ([.routing.rules[]? | select(
      (.outboundTag // "") != "block"
      and ((.ip // []) | index("geoip:private") | not)
      and (.protocol // "") != "blackhole"
    )]) |
    .outbounds = [.outbounds[]? | select(.tag != "block" or .protocol == "blackhole")] |
    if ([.outbounds[]? | select(.tag == "block")] | length) == 0 then
      .outbounds += [{protocol:"blackhole", tag:"block"}]
    else . end |
    .outbounds = [.outbounds[]? | select(.protocol != "freedom" or .tag == "direct" or .tag == "api")] |
    if ([.outbounds[]? | select(.tag == "direct")] | length) == 0 then
      .outbounds = [{protocol:"freedom", tag:"direct", settings:{domainStrategy:"UseIPv4"}}] + .outbounds
    else . end |
    if ([.outbounds[]? | select(.tag == "api")] | length) == 0 then
      .outbounds += [{protocol:"freedom", tag:"api", settings:{}}]
    else . end
  ' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_fix_access_log_perms() {
  local log_path="$1"
  local dir
  [ -n "$log_path" ] || return 0
  dir=$(dirname "$log_path")
  mkdir -p "$dir" 2>/dev/null || true
  touch "$log_path" 2>/dev/null || true
  chmod 777 "$dir" 2>/dev/null || true
  chmod 666 "$log_path" 2>/dev/null || true
  chown nobody:nogroup "$log_path" 2>/dev/null || chown nobody:nobody "$log_path" 2>/dev/null || true
  chown nobody:nogroup "$dir" 2>/dev/null || chown nobody:nobody "$dir" 2>/dev/null || true
}

usk_xray_ensure_access_log() {
  local cfg="$1"
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  local root="${DATA_ROOT:-${USK_DATA_ROOT:-/var/lib/unlimitsky}}"
  local log_path="${root}/xray/access.log"
  usk_xray_fix_access_log_perms "$log_path"
  local tmp
  tmp=$(mktemp)
  if ! jq --arg ap "$log_path" '
    .log = ((.log // {loglevel:"warning"}) + {loglevel: ((.log.loglevel // "warning") | tostring), access: $ap})
  ' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
  usk_xray_fix_access_log_perms "$log_path"
}

usk_xray_ensure_stats_policy() {
  local cfg="$1"
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  usk_xray_ensure_access_log "$cfg" 2>/dev/null || true
  local tmp vless_tag
  vless_tag=$(jq -r '(.inbounds[]? | select(.protocol=="vless") | .tag) // "vless-reality-in"' "$cfg" 2>/dev/null | head -1)
  [ -n "$vless_tag" ] || vless_tag="vless-reality-in"
  tmp=$(mktemp)
  if ! jq --arg vt "$vless_tag" '
    .stats = (.stats // {}) |
    .api = ((.api // {tag:"api"}) + {tag:"api", services: ((.api.services // ["StatsService"]) | map(select(. == "StatsService")) | if length == 0 then ["StatsService"] else . end)}) |
    .policy = (.policy // {}) |
    .policy.levels = (.policy.levels // {}) |
    .policy.levels["0"] = ((.policy.levels["0"] // {}) + {statsUserUplink:true, statsUserDownlink:true, statsUserOnline:true}) |
    .policy.system = (.policy.system // {statsInboundUplink:true, statsInboundDownlink:true}) |
    .outbounds = (
      if ([.outbounds[]? | select(.tag == "direct")] | length) > 0 then
        .outbounds
      else
        [{protocol:"freedom", tag:"direct", settings:{domainStrategy:"UseIPv4"}}] + (.outbounds // [])
      end
    ) |
    .outbounds = (
      if ([.outbounds[]? | select(.tag == "api")] | length) > 0 then
        .outbounds
      else
        .outbounds + [{protocol:"freedom", tag:"api", settings:{}}]
      end
    ) |
    .inbounds = (
      if ([.inbounds[]? | select(.tag == "api")] | length) > 0 then
        .inbounds
      else
        .inbounds + [{
          listen: "127.0.0.1",
          port: 10085,
          protocol: "dokodemo-door",
          settings: { address: "127.0.0.1" },
          tag: "api"
        }]
      end
    ) |
    .routing = (.routing // { domainStrategy: "IPIfNonMatch", rules: [] }) |
    .routing.rules = (
      (if ([.routing.rules[]? | select(.inboundTag? != null and (.inboundTag | index("api")))] | length) > 0 then
        .routing.rules
      else
        [{ type: "field", inboundTag: ["api"], outboundTag: "api" }] + (.routing.rules // [])
      end) as $r1 |
      if ([$r1[]? | select(.inboundTag? != null and (.inboundTag | index($vt)))] | length) > 0 then
        $r1
      else
        $r1 + [{ type: "field", inboundTag: [$vt], outboundTag: "direct" }]
      end
    ) |
    .routing.rules = ([.routing.rules[]? | select(
      (.outboundTag // "") != "block"
      and ((.ip // []) | index("geoip:private") | not)
    )]) |
    .inbounds |= map(
      if .protocol == "vless" then
        .settings.clients = [.settings.clients[]? | . + {level: (.level // 0), flow: (.flow // "xtls-rprx-vision")}]
      else . end
    )' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_rewrite_from_clients() {
  local cfg="${1:-$XRAY_CFG}"
  [ -f "$cfg" ] || return 1
  local clients port
  clients=$(usk_xray_load_clients "$cfg")
  port=$(usk_xray_vless_port_from_config "$cfg")
  port=${port:-443}
  [ "$clients" != "[]" ] && [ -n "$clients" ] || return 1
  usk_xray_write_config "$cfg" "$clients" "$port"
}

usk_xray_verify_stats_api() {
  local bin
  bin=$(usk_xray_bin 2>/dev/null) || return 1
  "$bin" api statsquery --server=127.0.0.1:10085 >/dev/null 2>&1
}

usk_xray_service_restart() {
  usk_xray_fix_perms "$XRAY_CFG"
  systemctl daemon-reload 2>/dev/null || true
  systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || return 1
  sleep 2
  systemctl is-active xray >/dev/null 2>&1 || systemctl is-active xray.service >/dev/null 2>&1
}

usk_xray_dedupe_config_clients() {
  local cfg="${1:-$XRAY_CFG}"
  [ -f "$cfg" ] || return 0
  command -v jq >/dev/null 2>&1 || return 0
  local clients tmp
  clients=$(usk_xray_load_clients "$cfg")
  clients=$(usk_xray_normalize_clients "$clients")
  [ -n "$clients" ] && [ "$clients" != "null" ] || return 0
  tmp=$(mktemp)
  if ! jq --argjson clients "$clients" '
    .inbounds |= map(
      if .protocol == "vless" then
        .settings.clients = $clients
      else . end
    )' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
  return 0
}

usk_xray_test_config() {
  local cfg="$1"
  local bin out
  bin=$(usk_xray_bin) || return 1
  usk_xray_dedupe_config_clients "$cfg" 2>/dev/null || true
  usk_xray_fix_perms "$cfg"
  if id nobody >/dev/null 2>&1; then
    out=$(sudo -u nobody "$bin" run -test -config "$cfg" 2>&1) || {
      echo "$out" | tail -8
      return 1
    }
    return 0
  fi
  out=$("$bin" run -test -config "$cfg" 2>&1) || {
    echo "$out" | tail -8
    return 1
  }
  return 0
}

usk_xray_ports_from_config() {
  local cfg="$1"
  USK_XRAY_VLESS_PORT=$(jq -r '(.inbounds[]? | select(.protocol=="vless") | .port) // empty' "$cfg" 2>/dev/null | head -1)
  USK_XRAY_VLESS_PORT=${USK_XRAY_VLESS_PORT:-443}
}

usk_xray_port_listening() {
  local port="$1"
  ss -tlnp 2>/dev/null | grep -q ":${port} "
}

usk_xray_open_firewall() {
  local port="$1"
  local label="$2"
  if command -v ufw >/dev/null 2>&1; then
    ufw allow "${port}/tcp" comment "unlimitsky ${label}" >/dev/null 2>&1 || true
  fi
  if command -v iptables >/dev/null 2>&1; then
    iptables -C INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1 \
      || iptables -I INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1 || true
  fi
}

usk_xray_verify_running() {
  local cfg="$1"
  usk_xray_ports_from_config "$cfg"
  usk_xray_test_config "$cfg" || return 2
  usk_xray_service_restart || return 1
  usk_xray_port_listening "$USK_XRAY_VLESS_PORT" || return 3
  return 0
}

usk_xray_verify_or_fail() {
  local cfg="$1"
  local err out
  if ! usk_xray_verify_running "$cfg"; then
    err=$?
    case "$err" in
      1)
        echo "USK_ERR: xray_service_failed"
        journalctl -u xray -n 15 --no-pager 2>/dev/null || true
        ;;
      2)
        echo "USK_ERR: xray_config_test_failed"
        out=$(usk_xray_test_config "$cfg" 2>&1 || true)
        echo "$out" | tail -8
        ;;
      3) echo "USK_ERR: xray_vless_port_not_listening port=${USK_XRAY_VLESS_PORT}" ;;
      *) echo "USK_ERR: xray_verify_failed" ;;
    esac
    return 1
  fi
  return 0
}

usk_xray_node_outbound_tag() {
  local node_id="$1"
  echo "node-$(echo "$node_id" | tr -cd 'a-zA-Z0-9_-')"
}

usk_xray_ensure_node_outbound() {
  local cfg="$1" node_id="$2" send_through="$3"
  local tag tmp
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  [ -n "$node_id" ] && [ -n "$send_through" ] || return 1
  tag=$(usk_xray_node_outbound_tag "$node_id")
  tmp=$(mktemp)
  if ! jq --arg tag "$tag" --arg st "$send_through" '
    .outbounds = (
      (.outbounds // []) | map(select(.tag != $tag))
    ) + [{
      protocol: "freedom",
      tag: $tag,
      settings: { domainStrategy: "UseIPv4", sendThrough: $st }
    }]' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_bind_user_to_node() {
  local cfg="$1" email="$2" node_id="$3"
  local tag tmp
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  [ -n "$email" ] && [ -n "$node_id" ] || return 1
  tag=$(usk_xray_node_outbound_tag "$node_id")
  tmp=$(mktemp)
  if ! jq --arg email "$email" --arg tag "$tag" '
    .routing = (.routing // {domainStrategy:"IPIfNonMatch", rules:[]}) |
    .routing.rules = (
      [.routing.rules[]? | select(
        (.user // []) | index($email) | not
      )] + [{type:"field", user:[$email], outboundTag:$tag}]
    )' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_unbind_user_node() {
  local cfg="$1" email="$2"
  local tmp
  [ -f "$cfg" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1
  [ -n "$email" ] || return 1
  tmp=$(mktemp)
  if ! jq --arg email "$email" '
    .routing.rules = [.routing.rules[]? | select(
      (.user // []) | index($email) | not
    )]' "$cfg" > "$tmp"; then
    rm -f "$tmp"
    return 1
  fi
  mv "$tmp" "$cfg"
  usk_xray_fix_perms "$cfg"
}

usk_xray_sync_node_egress_from_panel() {
  local cfg="${1:-$XRAY_CFG}" panel_root="${2:-${PANEL_ROOT:-}}"
  local panel_file send_through node_id email
  [ -f "$cfg" ] || return 0
  [ -n "$panel_root" ] || panel_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd || echo /var/www/unlimitsky)"
  panel_file="${panel_root}/data/clients/xray.json"
  [ -f "$panel_file" ] || return 0
  command -v jq >/dev/null 2>&1 || return 0
  local hub_script
  hub_script="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)/setup-hub-node-tunnel.sh"
  while IFS=$'\t' read -r node_id email; do
    [ -n "$node_id" ] && [ -n "$email" ] || continue
    send_through=""
    if [ -x "$hub_script" ]; then
      send_through=$(/bin/bash "$hub_script" send-through "$node_id" 2>/dev/null | sed -n 's/^USK_OK: send_through=//p' | head -1)
    fi
    [ -n "$send_through" ] || continue
    usk_xray_ensure_node_outbound "$cfg" "$node_id" "$send_through" || true
    usk_xray_bind_user_to_node "$cfg" "$email" "$node_id" || true
  done < <(jq -r '
    to_entries[] |
    select((.value.node_id // "") != "" and (.value.status // "active") == "active") |
    [(.value.node_id // ""), (.value.xray_email // .value.usage_id // .value.email // .key)] |
    @tsv
  ' "$panel_file" 2>/dev/null)
}

# Backward compat — VMess port no longer used
USK_XRAY_VMESS_PORT=""
