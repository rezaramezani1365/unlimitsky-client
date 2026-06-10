#!/bin/bash
# Shared OpenVPN helpers for unlimitsky
OVPN_SUBNET="${OVPN_SUBNET:-10.9.0.0/24}"
OVPN_NET="${OVPN_NET:-10.9.0.0}"
OVPN_MASK="${OVPN_MASK:-255.255.255.0}"
OVPN_TCP_SUBNET="${OVPN_TCP_SUBNET:-10.9.1.0/24}"
OVPN_TCP_NET="${OVPN_TCP_NET:-10.9.1.0}"
OVPN_TCP_MASK="${OVPN_TCP_MASK:-255.255.255.0}"
OVPN_STATUS_DIR="${OVPN_STATUS_DIR:-/var/log/openvpn}"

usk_openvpn_prepare_status_dir() {
  mkdir -p "$OVPN_STATUS_DIR"
  chmod 755 "$OVPN_STATUS_DIR" 2>/dev/null || true
}

usk_openvpn_status_log_path() {
  local name="$1"
  case "$name" in
    server-udp) echo "${OVPN_STATUS_DIR}/openvpn-udp-status.log" ;;
    server-tcp) echo "${OVPN_STATUS_DIR}/openvpn-tcp-status.log" ;;
    server)     echo "${OVPN_STATUS_DIR}/openvpn-status.log" ;;
    *)          echo "${OVPN_STATUS_DIR}/openvpn-${name}-status.log" ;;
  esac
}

usk_openvpn_ensure_status_policy() {
  local cfg name status_log
  usk_openvpn_prepare_status_dir
  for cfg in /etc/openvpn/server-udp.conf /etc/openvpn/server-tcp.conf /etc/openvpn/server.conf; do
    [ -f "$cfg" ] || continue
    name=$(basename "$cfg" .conf)
    status_log=$(usk_openvpn_status_log_path "$name")
    if ! grep -qE '^status ' "$cfg" 2>/dev/null; then
      {
        echo "status ${status_log} 10"
        echo "status-version 2"
      } >> "$cfg"
    elif ! grep -qE '^status-version ' "$cfg" 2>/dev/null; then
      echo "status-version 2" >> "$cfg"
    fi
  done
}

usk_openvpn_main_iface() {
  ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}'
}

usk_openvpn_ensure_dev_names() {
  local cfg dev
  for cfg in /etc/openvpn/server-udp.conf /etc/openvpn/server-tcp.conf; do
    [ -f "$cfg" ] || continue
    case "$cfg" in
      *server-udp*) dev="tun0" ;;
      *server-tcp*) dev="tun1" ;;
      *) continue ;;
    esac
    if grep -qE '^dev[[:space:]]+tun[[:space:]]*$' "$cfg" 2>/dev/null \
      || grep -qE '^dev[[:space:]]+tun$' "$cfg" 2>/dev/null; then
      sed -i "s/^dev[[:space:]]*tun[[:space:]]*$/dev ${dev}/" "$cfg"
    fi
  done
}

usk_openvpn_setup_nat() {
  local iface tun
  iface=$(usk_openvpn_main_iface)
  iface="${iface:-eth0}"

  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null \
    || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

  if command -v iptables >/dev/null 2>&1; then
    iptables -C FORWARD -i tun+ -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD 1 -i tun+ -j ACCEPT 2>/dev/null || true
    iptables -C FORWARD -o tun+ -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD 1 -o tun+ -j ACCEPT 2>/dev/null || true
    iptables -t nat -C POSTROUTING -s "$OVPN_SUBNET" -o "$iface" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -s "$OVPN_SUBNET" -o "$iface" -j MASQUERADE 2>/dev/null || true
    iptables -t nat -C POSTROUTING -s "$OVPN_TCP_SUBNET" -o "$iface" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -s "$OVPN_TCP_SUBNET" -o "$iface" -j MASQUERADE 2>/dev/null || true
  fi

  if command -v ufw >/dev/null 2>&1; then
    if grep -q 'DEFAULT_FORWARD_POLICY="DROP"' /etc/default/ufw 2>/dev/null; then
      sed -i 's/DEFAULT_FORWARD_POLICY="DROP"/DEFAULT_FORWARD_POLICY="ACCEPT"/' /etc/default/ufw 2>/dev/null || true
    fi
    for tun in tun0 tun1; do
      ufw route allow in on "$tun" out on "$iface" >/dev/null 2>&1 || true
      ufw route allow in on "$iface" out on "$tun" >/dev/null 2>&1 || true
    done
    ufw reload >/dev/null 2>&1 || true
  fi
}

usk_openvpn_write_server() {
  local name="$1"
  local port="$2"
  local proto="$3"
  local cfg="/etc/openvpn/${name}.conf"
  local easy="/etc/openvpn/easy-rsa"
  local status_log
  status_log=$(usk_openvpn_status_log_path "$name")
  usk_openvpn_prepare_status_dir

  local extra_notify="" dev="tun0" net="$OVPN_NET" mask="$OVPN_MASK"
  local connect_hook
  connect_hook="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/openvpn-client-connect.sh"
  case "$name" in
    server-udp) dev="tun0" ;;
    server-tcp) dev="tun1"; net="$OVPN_TCP_NET"; mask="$OVPN_TCP_MASK" ;;
    *) dev="tun0" ;;
  esac
  if [ "$proto" = "udp" ]; then
    extra_notify=$'\nexplicit-exit-notify 1'
  fi

  cat > "$cfg" <<OVPN
port ${port}
proto ${proto}
dev ${dev}
ca ${easy}/pki/ca.crt
cert ${easy}/pki/issued/server.crt
key ${easy}/pki/private/server.key
dh ${easy}/pki/dh.pem
topology subnet
server ${net} ${mask}
push "redirect-gateway def1 bypass-dhcp"
push "dhcp-option DNS 1.1.1.1"
push "dhcp-option DNS 8.8.8.8"
keepalive 10 120
cipher AES-256-GCM
auth SHA256
data-ciphers AES-256-GCM:AES-128-GCM
data-ciphers-fallback AES-256-GCM
persist-key
persist-tun
status ${status_log} 10
status-version 2
client-connect ${connect_hook}
user nobody
group nogroup
verb 3${extra_notify}
OVPN
  chmod 644 "$cfg"
}

usk_openvpn_server_conf() {
  local proto="$1"
  proto=$(echo "$proto" | tr '[:upper:]' '[:lower:]')
  if [ "$proto" = "tcp" ]; then
    if [ -f /etc/openvpn/server-tcp.conf ]; then
      echo /etc/openvpn/server-tcp.conf
      return 0
    fi
    return 1
  fi
  if [ -f /etc/openvpn/server-udp.conf ]; then
    echo /etc/openvpn/server-udp.conf
  elif [ -f /etc/openvpn/server.conf ]; then
    echo /etc/openvpn/server.conf
  else
    return 1
  fi
}

usk_openvpn_read_proto_port() {
  local proto="$1"
  local cfg
  cfg=$(usk_openvpn_server_conf "$proto")
  if [ ! -f "$cfg" ]; then
    return 1
  fi
  USK_OVPN_PROTO=$(grep -E '^proto ' "$cfg" 2>/dev/null | awk '{print $2}' | head -1)
  USK_OVPN_PORT=$(grep -E '^port ' "$cfg" 2>/dev/null | awk '{print $2}' | head -1)
  USK_OVPN_PROTO=${USK_OVPN_PROTO:-$proto}
  USK_OVPN_PORT=${USK_OVPN_PORT:-1194}
}

usk_openvpn_enable_service() {
  local name="$1"
  systemctl enable "openvpn@${name}" 2>/dev/null || true
  systemctl restart "openvpn@${name}" 2>/dev/null || systemctl start "openvpn@${name}" 2>/dev/null || return 1
}

usk_openvpn_discover_status_files() {
  local cfg f seen=""
  for cfg in /etc/openvpn/server-udp.conf /etc/openvpn/server-tcp.conf /etc/openvpn/server.conf /etc/openvpn/*.conf; do
    [ -f "$cfg" ] || continue
    f=$(awk '/^status[[:space:]]+/ { print $2; exit }' "$cfg" 2>/dev/null || true)
    [ -n "$f" ] || continue
    case " $seen " in
      *" $f "*) continue ;;
    esac
    seen="${seen} ${f}"
    [ -r "$f" ] && echo "$f"
  done
  for f in \
    "$(usk_openvpn_status_log_path server-udp)" \
    "$(usk_openvpn_status_log_path server-tcp)" \
    "$(usk_openvpn_status_log_path server)" \
    "/run/openvpn-server/status.log"; do
    case " $seen " in
      *" $f "*) continue ;;
    esac
    seen="${seen} ${f}"
    [ -r "$f" ] && echo "$f"
  done
}

usk_openvpn_verify_status_logs() {
  local n=0 f
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    n=$((n + 1))
  done < <(usk_openvpn_discover_status_files)
  [ "$n" -gt 0 ]
}

usk_openvpn_ensure_management() {
  usk_openvpn_prepare_status_dir
  local cfg name port=7505 mgmt changed=0 connect_hook
  connect_hook="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/openvpn-client-connect.sh"
  for cfg in /etc/openvpn/server-udp.conf /etc/openvpn/server-tcp.conf /etc/openvpn/server.conf; do
    [ -f "$cfg" ] || continue
    name=$(basename "$cfg" .conf)
    changed=0
    if ! grep -qE '^management[[:space:]]' "$cfg" 2>/dev/null; then
      mgmt="127.0.0.1 ${port}"
      {
        echo ""
        echo "# unlimitsky — per-client connection limit enforcement"
        echo "management ${mgmt}"
      } >> "$cfg"
      changed=1
      port=$((port + 1))
    fi
    if [ -f "$connect_hook" ] && ! grep -qF 'openvpn-client-connect.sh' "$cfg" 2>/dev/null; then
      {
        echo ""
        echo "# unlimitsky — reject connect when max_connections slots full"
        echo "client-connect ${connect_hook}"
      } >> "$cfg"
      changed=1
    fi
    if [ "$changed" = 1 ]; then
      systemctl restart "openvpn@${name}" 2>/dev/null || true
    fi
  done
}

usk_openvpn_management_port_for_status() {
  local status_file="$1"
  local cfg port=7505 mgmt_file mgmt_host mgmt_port
  for cfg in /etc/openvpn/server-udp.conf /etc/openvpn/server-tcp.conf /etc/openvpn/server.conf; do
    [ -f "$cfg" ] || continue
    mgmt_file=$(awk '/^status[[:space:]]+/ { print $2; exit }' "$cfg" 2>/dev/null || true)
    if [ -n "$mgmt_file" ] && [ "$mgmt_file" = "$status_file" ]; then
      mgmt_host=$(awk '/^management[[:space:]]+/ { print $2; exit }' "$cfg" 2>/dev/null || true)
      mgmt_port=$(awk '/^management[[:space:]]+/ { print $3; exit }' "$cfg" 2>/dev/null || true)
      if [ -n "$mgmt_host" ] && [ -n "$mgmt_port" ]; then
        echo "$mgmt_port"
        return 0
      fi
    fi
    port=$((port + 1))
  done
  echo "$port"
}

usk_openvpn_mgmt_kill() {
  local port="$1"
  local cn="$2"
  local addr="$3"
  [ -n "$port" ] && [ -n "$cn" ] && [ -n "$addr" ] || return 1
  if command -v nc >/dev/null 2>&1; then
    { printf 'kill %s:%s\nquit\n' "$cn" "$addr"; sleep 0.15; } | nc -w2 127.0.0.1 "$port" >/dev/null 2>&1 || true
    return 0
  fi
  if command -v timeout >/dev/null 2>&1 && command -v bash >/dev/null 2>&1; then
    timeout 2 bash -c "exec 3<>/dev/tcp/127.0.0.1/${port}; printf 'kill %s:%s\nquit\n' '$cn' '$addr' >&3; sleep 0.15" 2>/dev/null || true
  fi
}
