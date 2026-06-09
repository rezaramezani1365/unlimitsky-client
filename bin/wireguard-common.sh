#!/bin/bash

WG_TCP_KEY_FILE="/etc/wireguard/udp2raw.key"
WG_TCP_UNIT="udp2raw-wg.service"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=usk-common.sh
source "$DIR/usk-common.sh"

usk_wg_main_iface() {
  ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}'
}

# Bring up wg0 when config exists but the interface is down (common after reboot).
usk_wg_ensure_running() {
  if command -v wg >/dev/null 2>&1 && wg show wg0 >/dev/null 2>&1; then
    return 0
  fi
  [ -f /etc/wireguard/wg0.conf ] || return 1

  usk_wg_fix_postup_conf 2>/dev/null || true

  if systemctl is-active --quiet wg-quick@wg0 2>/dev/null; then
    systemctl restart wg-quick@wg0 2>/dev/null || true
  else
    systemctl enable wg-quick@wg0 2>/dev/null || true
    wg-quick down wg0 2>/dev/null || true
    if command -v wg-quick >/dev/null 2>&1; then
      wg-quick up wg0 2>/dev/null || true
    fi
    systemctl start wg-quick@wg0 2>/dev/null || systemctl restart wg-quick@wg0 2>/dev/null || true
  fi

  sleep 1
  if command -v wg >/dev/null 2>&1 && wg show wg0 >/dev/null 2>&1; then
    usk_wg_ensure_nat
    return 0
  fi
  return 1
}

# Ensure wg0 clients can reach the internet (NAT + forwarding + UFW routes).
usk_wg_ensure_nat() {
  local iface
  iface=$(usk_wg_main_iface)
  iface="${iface:-eth0}"

  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null \
    || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

  if command -v iptables >/dev/null 2>&1; then
    iptables -C FORWARD -i wg0 -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD -i wg0 -j ACCEPT 2>/dev/null || true
    iptables -C FORWARD -o wg0 -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD -o wg0 -j ACCEPT 2>/dev/null || true
    iptables -t nat -C POSTROUTING -o "$iface" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -o "$iface" -j MASQUERADE 2>/dev/null || true
  fi

  if command -v ufw >/dev/null 2>&1; then
    if grep -q 'DEFAULT_FORWARD_POLICY="DROP"' /etc/default/ufw 2>/dev/null; then
      sed -i 's/DEFAULT_FORWARD_POLICY="DROP"/DEFAULT_FORWARD_POLICY="ACCEPT"/' /etc/default/ufw 2>/dev/null || true
      ufw reload >/dev/null 2>&1 || true
    fi
    ufw route allow in on wg0 out on "$iface" >/dev/null 2>&1 || true
    ufw route allow in on "$iface" out on wg0 >/dev/null 2>&1 || true
  fi
}

# Rewrite PostUp/PostDown in wg0.conf when the VPS main NIC changed (eth0 vs ens3).
usk_wg_fix_postup_conf() {
  local iface conf
  conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 1
  iface=$(usk_wg_main_iface)
  iface="${iface:-eth0}"

  if grep -q '^PostUp' "$conf"; then
    sed -i "s|^PostUp = .*|PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${iface} -j MASQUERADE|" "$conf"
    sed -i "s|^PostDown = .*|PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${iface} -j MASQUERADE|" "$conf"
    return 0
  fi
  sed -i "/^ListenPort/a PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${iface} -j MASQUERADE" "$conf" 2>/dev/null \
    || sed -i "/^Address/a PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${iface} -j MASQUERADE" "$conf" 2>/dev/null || true
  sed -i "/^PostUp/a PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${iface} -j MASQUERADE" "$conf" 2>/dev/null || true
}

usk_wg_install_udp2raw() {
  local dest="/usr/local/bin/udp2raw"
  if [ -x "$dest" ]; then
    return 0
  fi
  local url="https://github.com/wangyu-/udp2raw/releases/download/udp2raw_amd64/udp2raw_amd64"
  if command -v wget >/dev/null 2>&1; then
    wget -q -O "$dest" "$url" 2>/dev/null || return 1
  elif command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "$dest" "$url" 2>/dev/null || return 1
  else
    return 1
  fi
  chmod +x "$dest"
}

usk_wg_port_in_use() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    ss -H -ltn "sport = :${port}" 2>/dev/null | grep -q .
    return $?
  fi
  if command -v netstat >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | grep -q ":${port} "
    return $?
  fi
  return 1
}

usk_wg_setup_tcp_bridge() {
  local wg_port="$1"
  local tcp_port="$2"

  tcp_port=$(echo "$tcp_port" | tr -dc '0-9')
  wg_port=$(echo "$wg_port" | tr -dc '0-9')
  [ -n "$tcp_port" ] && [ "$tcp_port" -ge 1 ] && [ "$tcp_port" -le 65535 ] 2>/dev/null || return 1
  [ -n "$wg_port" ] && [ "$wg_port" -ge 1 ] 2>/dev/null || wg_port=51820

  if usk_wg_port_in_use "$tcp_port"; then
    local alt picked=0
    for alt in 51823 51824 51825 51826 51827; do
      if ! usk_wg_port_in_use "$alt"; then
        tcp_port=$alt
        picked=1
        break
      fi
    done
    if [ "$picked" -eq 0 ]; then
      echo "USK_ERR: wireguard_tcp_port_in_use port=${tcp_port}" >&2
      return 1
    fi
  fi

  usk_wg_install_udp2raw || return 1

  if [ ! -f "$WG_TCP_KEY_FILE" ]; then
    openssl rand -hex 16 > "$WG_TCP_KEY_FILE"
    chmod 600 "$WG_TCP_KEY_FILE"
  fi
  local key
  key=$(tr -d '\n\r' < "$WG_TCP_KEY_FILE")

  cat > "/etc/systemd/system/${WG_TCP_UNIT}" <<UNIT
[Unit]
Description=WireGuard TCP bridge (udp2raw faketcp)
After=network-online.target wg-quick@wg0.service
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/udp2raw -s -l0.0.0.0:${tcp_port} -r127.0.0.1:${wg_port} -a -k ${key} --raw-mode faketcp --cipher-mode aes128cbc --fix-gro
Restart=on-failure
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

  systemctl daemon-reload
  systemctl enable "$WG_TCP_UNIT" 2>/dev/null || true
  systemctl restart "$WG_TCP_UNIT" 2>/dev/null || systemctl start "$WG_TCP_UNIT" 2>/dev/null || return 1
  sleep 1
  if ! systemctl is-active --quiet "$WG_TCP_UNIT" 2>/dev/null; then
    echo "USK_ERR: wireguard_tcp_bridge_start_failed port=${tcp_port}" >&2
    return 1
  fi
  ensure_ufw_port "$tcp_port" tcp wireguard-tcp
  return 0
}

usk_wg_tcp_enabled() {
  [ -f "/etc/systemd/system/${WG_TCP_UNIT}" ] && systemctl is-enabled "$WG_TCP_UNIT" >/dev/null 2>&1
}

usk_wg_tcp_port() {
  if [ -f "/etc/systemd/system/${WG_TCP_UNIT}" ]; then
    grep -oE '0\.0\.0\.0:[0-9]+' "/etc/systemd/system/${WG_TCP_UNIT}" 2>/dev/null | head -1 | cut -d: -f2
  fi
}

usk_wg_tcp_key() {
  if [ -f "$WG_TCP_KEY_FILE" ]; then
    tr -d '\n\r' < "$WG_TCP_KEY_FILE"
  fi
}
