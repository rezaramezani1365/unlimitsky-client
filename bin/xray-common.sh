#!/bin/bash
# Shared Xray helpers for UnlimitSky
XRAY_CFG="${XRAY_CFG:-/usr/local/etc/xray/config.json}"
USK_XRAY_VLESS_PORT="${USK_XRAY_VLESS_PORT:-2053}"
USK_XRAY_VMESS_PORT="${USK_XRAY_VMESS_PORT:-2087}"

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

usk_xray_load_clients() {
  local cfg="$1"
  local proto="$2"
  if [ ! -f "$cfg" ] || ! command -v jq >/dev/null 2>&1; then
    echo '[]'
    return
  fi
  jq -c --arg p "$proto" '
    [.inbounds[]? | select(.protocol == $p) | .settings.clients[]?
     | select(.id != null and (.id | type) == "string"
       and (.id | test("^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$")))
     | {id, email: ((.email // "user") | tostring)}
    ] | unique_by(.id)
  ' "$cfg" 2>/dev/null || echo '[]'
}

usk_xray_write_config() {
  local cfg="$1"
  local vless_json="$2"
  local vmess_json="$3"
  local vless_port="$4"
  local vmess_port="$5"
  local tmp
  tmp=$(mktemp)
  if ! jq -n \
    --argjson vless "$vless_json" \
    --argjson vmess "$vmess_json" \
    --argjson vless_port "$vless_port" \
    --argjson vmess_port "$vmess_port" \
    '{
      log: { loglevel: "warning" },
      inbounds: [
        {
          listen: "0.0.0.0",
          port: $vless_port,
          protocol: "vless",
          tag: "vless-in",
          settings: { clients: $vless, decryption: "none" },
          streamSettings: {
            network: "tcp",
            security: "none",
            tcpSettings: { header: { type: "none" } }
          },
          sniffing: { enabled: true, destOverride: ["http", "tls"] }
        },
        {
          listen: "0.0.0.0",
          port: $vmess_port,
          protocol: "vmess",
          tag: "vmess-in",
          settings: { clients: $vmess },
          streamSettings: { network: "tcp", security: "none" },
          sniffing: { enabled: true, destOverride: ["http", "tls"] }
        }
      ],
      outbounds: [{ protocol: "freedom", tag: "direct" }]
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
  chmod 644 "$cfg"
  return 0
}

usk_xray_service_restart() {
  systemctl daemon-reload 2>/dev/null || true
  systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || return 1
  sleep 2
  systemctl is-active xray >/dev/null 2>&1 || systemctl is-active xray.service >/dev/null 2>&1
}

usk_xray_test_config() {
  local cfg="$1"
  local bin out
  bin=$(usk_xray_bin) || return 1
  out=$("$bin" run -test -config "$cfg" 2>&1) || {
    echo "$out" | tail -5
    return 1
  }
  return 0
}

usk_xray_ports_from_config() {
  local cfg="$1"
  USK_XRAY_VLESS_PORT=$(jq -r '.inbounds[0].port // 2053' "$cfg" 2>/dev/null || echo 2053)
  USK_XRAY_VMESS_PORT=$(jq -r '.inbounds[1].port // 2087' "$cfg" 2>/dev/null || echo 2087)
}

usk_xray_port_listening() {
  local port="$1"
  ss -tlnp 2>/dev/null | grep -q ":${port} "
}

usk_xray_open_firewall() {
  local port="$1"
  local label="$2"
  if command -v ufw >/dev/null 2>&1; then
    ufw allow "${port}/tcp" comment "UnlimitSky ${label}" >/dev/null 2>&1 || true
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
  usk_xray_port_listening "$USK_XRAY_VMESS_PORT" || return 4
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
      4) echo "USK_ERR: xray_vmess_port_not_listening port=${USK_XRAY_VMESS_PORT}" ;;
      *) echo "USK_ERR: xray_verify_failed" ;;
    esac
    return 1
  fi
  return 0
}
