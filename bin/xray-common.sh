#!/bin/bash
# Shared Xray helpers — VLESS + Reality (Iran-optimized, IPv4-only clients)
XRAY_CFG="${XRAY_CFG:-/usr/local/etc/xray/config.json}"
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
       and (.id | test("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$")))
     | {id, email: ((.email // "user") | tostring), flow: ((.flow // "xtls-rprx-vision") | tostring)}
    ] | unique_by(.id)
  ' "$cfg" 2>/dev/null || echo '[]'
}

usk_xray_normalize_clients() {
  local raw="$1"
  echo "$raw" | jq -c '
    [.[]? | select(.id != null) | {
      id: .id,
      email: ((.email // "user") | tostring),
      flow: "xtls-rprx-vision"
    }] | unique_by(.id)
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
      log: { loglevel: "warning" },
      inbounds: [
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
        { protocol: "blackhole", tag: "block" }
      ],
      routing: {
        domainStrategy: "IPIfNonMatch",
        rules: [
          { type: "field", outboundTag: "block", ip: ["geoip:private"] }
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

usk_xray_build_vless_uri() {
  local uuid="$1"
  local host="$2"
  local port="$3"
  local name="$4"
  local pub="$5"
  local sni="$6"
  local sid="$7"
  local fp="${8:-chrome}"
  name=$(echo "$name" | sed 's/[#?&]/_/g')
  printf 'vless://%s@%s:%s?encryption=none&flow=xtls-rprx-vision&security=reality&sni=%s&fp=%s&pbk=%s&sid=%s&type=tcp&headerType=none#%s' \
    "$uuid" "$host" "$port" "$sni" "$fp" "$pub" "$sid" "$name"
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
      log: { loglevel: "warning" },
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
  local tmp
  tmp=$(mktemp)
  if ! jq --arg id "$uuid" --arg email "$email" '
    .inbounds |= map(
      if .protocol == "vless" then
        .settings.clients = ((.settings.clients // [])
          | map(select(.id != $id))
          | . + [{id: $id, email: $email, flow: "xtls-rprx-vision"}])
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

usk_xray_service_restart() {
  usk_xray_fix_perms "$XRAY_CFG"
  systemctl daemon-reload 2>/dev/null || true
  systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || return 1
  sleep 2
  systemctl is-active xray >/dev/null 2>&1 || systemctl is-active xray.service >/dev/null 2>&1
}

usk_xray_test_config() {
  local cfg="$1"
  local bin out
  bin=$(usk_xray_bin) || return 1
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

# Backward compat — VMess port no longer used
USK_XRAY_VMESS_PORT=""
