#!/bin/bash
# Shared Xray helpers for UnlimitSky
XRAY_CFG="${XRAY_CFG:-/usr/local/etc/xray/config.json}"
USK_XRAY_VLESS_PORT="${USK_XRAY_VLESS_PORT:-2053}"
USK_XRAY_VMESS_PORT="${USK_XRAY_VMESS_PORT:-8443}"

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

usk_xray_service_restart() {
  systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || return 1
  sleep 1
  systemctl is-active xray >/dev/null 2>&1 || systemctl is-active xray.service >/dev/null 2>&1
}

usk_xray_test_config() {
  local cfg="$1"
  local bin
  bin=$(usk_xray_bin) || return 1
  "$bin" run -test -config "$cfg" >/dev/null 2>&1
}

usk_xray_ports_from_config() {
  local cfg="$1"
  USK_XRAY_VLESS_PORT=$(jq -r '.inbounds[0].port // 2053' "$cfg" 2>/dev/null || echo 2053)
  USK_XRAY_VMESS_PORT=$(jq -r '.inbounds[1].port // 8443' "$cfg" 2>/dev/null || echo 8443)
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
  # No ufw — try direct accept (non-fatal)
  if command -v iptables >/dev/null 2>&1; then
    iptables -C INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1 \
      || iptables -I INPUT -p tcp --dport "$port" -j ACCEPT >/dev/null 2>&1 || true
  fi
}

usk_xray_verify_running() {
  local cfg="$1"
  usk_xray_ports_from_config "$cfg"
  usk_xray_service_restart || return 1
  usk_xray_test_config "$cfg" || return 2
  usk_xray_port_listening "$USK_XRAY_VLESS_PORT" || return 3
  usk_xray_port_listening "$USK_XRAY_VMESS_PORT" || return 4
  return 0
}

usk_xray_verify_or_fail() {
  local cfg="$1"
  local err
  if ! usk_xray_verify_running "$cfg"; then
    err=$?
    case "$err" in
      1) echo "USK_ERR: xray_service_failed" ;;
      2) echo "USK_ERR: xray_config_test_failed" ;;
      3) echo "USK_ERR: xray_vless_port_not_listening port=${USK_XRAY_VLESS_PORT}" ;;
      4) echo "USK_ERR: xray_vmess_port_not_listening port=${USK_XRAY_VMESS_PORT}" ;;
      *) echo "USK_ERR: xray_verify_failed" ;;
    esac
    journalctl -u xray -n 5 --no-pager 2>/dev/null || true
    return 1
  fi
  return 0
}
