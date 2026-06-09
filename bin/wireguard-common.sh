#!/bin/bash

WG_TCP_KEY_FILE="/etc/wireguard/udp2raw.key"
WG_TCP_UNIT="udp2raw-wg.service"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=usk-common.sh
source "$DIR/usk-common.sh"

usk_wg_main_iface() {
  ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}'
}

usk_wg_registry() {
  echo "${USK_DATA_ROOT:-/var/lib/unlimitsky}/wireguard/clients.json"
}

# Drop [Peer] blocks with no valid PublicKey (broken sed removals leave wg-quick failing).
usk_wg_sanitize_conf() {
  local conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 1
  local tmp
  tmp=$(mktemp)
  awk '
    BEGIN { section="none"; peer_buf=""; peer_has_pk=0 }
    function flush_peer() {
      if (section != "peer") return
      if (peer_has_pk) printf "%s", peer_buf
      peer_buf=""
      peer_has_pk=0
      section="none"
    }
    /^\[Interface\]/ { flush_peer(); section="iface"; print; next }
    /^\[Peer\]/ {
      flush_peer()
      section="peer"
      peer_buf=$0 "\n"
      peer_has_pk=0
      next
    }
    section == "iface" { print; next }
    section == "peer" {
      peer_buf = peer_buf $0 "\n"
      if ($0 ~ /^PublicKey[[:space:]]*=[[:space:]]*[A-Za-z0-9+\/]{43}=/) peer_has_pk=1
      next
    }
    { print }
    END { flush_peer() }
  ' "$conf" > "$tmp" && mv "$tmp" "$conf"
}

usk_wg_subnet() {
  local conf="/etc/wireguard/wg0.conf"
  local addr base mask
  addr=$(grep -E '^Address\s*=' "$conf" 2>/dev/null | head -1 | awk '{print $3}')
  if [[ "$addr" =~ ^([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/([0-9]+)$ ]]; then
    base="${BASH_REMATCH[1]}"
    mask="${BASH_REMATCH[2]}"
    if [ "$mask" -ge 24 ] 2>/dev/null; then
      echo "${base}.0/${mask}"
      return 0
    fi
  fi
  echo "10.8.0.0/24"
}

usk_wg_conf_valid() {
  local conf="/etc/wireguard/wg0.conf"
  local stripped
  [ -f "$conf" ] || return 1
  command -v wg >/dev/null 2>&1 && command -v wg-quick >/dev/null 2>&1 || return 1
  if wg show wg0 >/dev/null 2>&1; then
    return 0
  fi
  stripped=$(mktemp)
  wg-quick strip wg0 "$conf" > "$stripped" 2>/dev/null || { rm -f "$stripped"; return 1; }
  ip link del usk-wg-validate 2>/dev/null || true
  if ! ip link add usk-wg-validate type wireguard 2>/dev/null; then
    rm -f "$stripped"
    return 1
  fi
  if wg setconf usk-wg-validate "$stripped" 2>/dev/null; then
    ip link del usk-wg-validate 2>/dev/null || true
    rm -f "$stripped"
    return 0
  fi
  ip link del usk-wg-validate 2>/dev/null || true
  rm -f "$stripped"
  return 1
}

usk_wg_sync_peers_from_conf() {
  local conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 1
  command -v wg-quick >/dev/null 2>&1 || return 1
  wg show wg0 >/dev/null 2>&1 || return 1
  wg syncconf wg0 <(wg-quick strip wg0 "$conf") 2>/dev/null
}

usk_wg_rebuild_peers_from_registry() {
  local conf="/etc/wireguard/wg0.conf"
  local registry
  registry=$(usk_wg_registry)
  [ -f "$conf" ] || return 1
  [ -f "$registry" ] || return 1
  command -v jq >/dev/null 2>&1 || return 1

  local tmp
  tmp=$(mktemp)
  awk '/^\[Peer\]/{exit} {print}' "$conf" > "$tmp"
  jq -r '.[] | select(.public_key != null and .public_key != "" and .public_key != "null") |
    select(.ip != null and .ip != "" and .ip != "null") |
    "\n[Peer]\n# " + .username + "\nPublicKey = " + .public_key + "\nAllowedIPs = " + .ip + "/32\n"' \
    "$registry" >> "$tmp"
  mv "$tmp" "$conf"
}

# Fix invalid wg0.conf then verify with wg setconf.
usk_wg_repair_conf() {
  local conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 1

  if usk_wg_conf_valid; then
    return 0
  fi

  cp "$conf" "${conf}.bak.$(date +%s)" 2>/dev/null || true
  usk_wg_sanitize_conf || true
  if usk_wg_conf_valid; then
    echo "USK_WARN:wireguard_conf_sanitized" >&2
    return 0
  fi

  if usk_wg_rebuild_peers_from_registry && usk_wg_sanitize_conf && usk_wg_conf_valid; then
    echo "USK_WARN:wireguard_peers_rebuilt" >&2
    return 0
  fi

  local tmp
  tmp=$(mktemp)
  awk '/^\[Peer\]/{exit} {print}' "$conf" > "$tmp"
  if [ -s "$tmp" ] && grep -q '^\[Interface\]' "$tmp"; then
    mv "$tmp" "$conf"
    if usk_wg_conf_valid; then
      echo "USK_WARN:wireguard_peers_cleared" >&2
      return 0
    fi
  fi
  rm -f "$tmp"
  return 1
}

usk_wg_remove_peer_from_conf() {
  local username="$1"
  local pubkey="$2"
  local conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 0
  local tmp
  tmp=$(mktemp)
  awk -v user="$username" -v pk="$pubkey" '
    BEGIN { in_peer=0; buf=""; drop=0; has_pk=0 }
    function flush_peer() {
      if (!in_peer) return
      if (!drop && has_pk) printf "%s", buf
      in_peer=0; buf=""; drop=0; has_pk=0
    }
    /^\[Peer\]/ {
      flush_peer()
      in_peer=1
      buf=$0 "\n"
      drop=0
      has_pk=0
      next
    }
    in_peer {
      buf = buf $0 "\n"
      if (user != "" && $0 ~ ("^# " user "$")) drop=1
      if (pk != "" && index($0, "PublicKey = " pk) == 1) drop=1
      if ($0 ~ /^PublicKey[[:space:]]*=/) has_pk=1
      next
    }
    { print }
    END { flush_peer() }
  ' "$conf" > "$tmp" && mv "$tmp" "$conf"
  usk_wg_sanitize_conf || true
}

# Bring up wg0 when config exists but the interface is down (common after reboot).
usk_wg_ensure_running() {
  if command -v wg >/dev/null 2>&1 && wg show wg0 >/dev/null 2>&1; then
    usk_wg_fix_postup_conf 2>/dev/null || true
    usk_wg_sync_peers_from_conf 2>/dev/null || true
    usk_wg_ensure_nat
    return 0
  fi
  [ -f /etc/wireguard/wg0.conf ] || return 1

  usk_wg_repair_conf || return 1
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
  local iface subnet old
  iface=$(usk_wg_main_iface)
  iface="${iface:-eth0}"
  subnet=$(usk_wg_subnet)

  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null \
    || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  sysctl -w net.ipv4.conf.all.rp_filter=2 >/dev/null 2>&1 || true
  sysctl -w net.ipv4.conf.default.rp_filter=2 >/dev/null 2>&1 || true
  sysctl -w net.ipv4.conf.wg0.rp_filter=2 >/dev/null 2>&1 || true

  if command -v iptables >/dev/null 2>&1; then
    iptables -C FORWARD -i wg0 -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD 1 -i wg0 -j ACCEPT 2>/dev/null || true
    iptables -C FORWARD -o wg0 -j ACCEPT 2>/dev/null \
      || iptables -I FORWARD 1 -o wg0 -j ACCEPT 2>/dev/null || true
    for old in eth0 ens3 ens18 enp0s3 enp1s0; do
      if [ "$old" != "$iface" ]; then
        iptables -t nat -D POSTROUTING -s "$subnet" -o "$old" -j MASQUERADE 2>/dev/null || true
      fi
    done
    iptables -t nat -C POSTROUTING -s "$subnet" -o "$iface" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -s "$subnet" -o "$iface" -j MASQUERADE 2>/dev/null || true
    iptables -t nat -C POSTROUTING -o "$iface" -j MASQUERADE 2>/dev/null \
      || iptables -t nat -A POSTROUTING -o "$iface" -j MASQUERADE 2>/dev/null || true
  fi

  if command -v ufw >/dev/null 2>&1; then
    if grep -q 'DEFAULT_FORWARD_POLICY="DROP"' /etc/default/ufw 2>/dev/null; then
      sed -i 's/DEFAULT_FORWARD_POLICY="DROP"/DEFAULT_FORWARD_POLICY="ACCEPT"/' /etc/default/ufw 2>/dev/null || true
    fi
    ufw route allow in on wg0 out on "$iface" >/dev/null 2>&1 || true
    ufw route allow in on "$iface" out on wg0 >/dev/null 2>&1 || true
    usk_wg_ensure_ufw_nat "$iface" "$subnet"
    ufw reload >/dev/null 2>&1 || true
  fi
}

# UFW resets raw iptables NAT on reload — persist MASQUERADE in before.rules.
usk_wg_ensure_ufw_nat() {
  local iface="$1"
  local subnet="$2"
  local rules="/etc/ufw/before.rules"
  local marker="unlimitsky-wg-nat"

  [ -f "$rules" ] || return 0
  command -v ufw >/dev/null 2>&1 || return 0
  ufw status 2>/dev/null | grep -qi 'Status: active' || return 0

  if grep -q "$marker" "$rules" 2>/dev/null; then
    sed -i "s|^-A POSTROUTING -s .* -o .* -j MASQUERADE.*$marker|-A POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE # ${marker}|" "$rules" 2>/dev/null || true
    return 0
  fi

  if grep -q '^\*nat' "$rules" 2>/dev/null; then
    sed -i "/^\*nat/a\\
-A POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE # ${marker}" "$rules" 2>/dev/null || true
    return 0
  fi

  sed -i "/^# Don't delete these required lines/i\\
# ${marker}\\
*nat\\
:POSTROUTING ACCEPT [0:0]\\
-A POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE\\
COMMIT\\
" "$rules" 2>/dev/null || true
}

# Rewrite PostUp/PostDown in wg0.conf when the VPS main NIC changed (eth0 vs ens3).
usk_wg_fix_postup_conf() {
  local iface conf
  conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 1
  iface=$(usk_wg_main_iface)
  iface="${iface:-eth0}"

  local subnet
  subnet=$(usk_wg_subnet)
  if grep -q '^PostUp' "$conf"; then
    sed -i "s|^PostUp = .*|PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE|" "$conf"
    sed -i "s|^PostDown = .*|PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE|" "$conf"
    return 0
  fi
  sed -i "/^ListenPort/a PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE" "$conf" 2>/dev/null \
    || sed -i "/^Address/a PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE" "$conf" 2>/dev/null || true
  sed -i "/^PostUp/a PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -s ${subnet} -o ${iface} -j MASQUERADE" "$conf" 2>/dev/null || true
}

usk_wg_install_udp2raw() {
  local dest="/usr/local/bin/udp2raw"
  if [ -x "$dest" ] && "$dest" 2>&1 | head -1 | grep -qi udp2raw; then
    return 0
  fi
  local arch url urls=()
  arch=$(uname -m 2>/dev/null || echo amd64)
  case "$arch" in
    x86_64|amd64)
      urls=(
        "https://github.com/wangyu-/udp2raw/releases/download/20200818.0/udp2raw_amd64"
        "https://github.com/wangyu-/udp2raw/releases/download/udp2raw_amd64/udp2raw_amd64"
      )
      ;;
    aarch64|arm64)
      urls=(
        "https://github.com/wangyu-/udp2raw/releases/download/20200818.0/udp2raw_arm"
        "https://github.com/wangyu-/udp2raw/releases/download/udp2raw_arm/udp2raw_arm"
      )
      ;;
    *)
      return 1
      ;;
  esac
  for url in "${urls[@]}"; do
    if command -v wget >/dev/null 2>&1; then
      wget -q -O "$dest" "$url" 2>/dev/null || continue
    elif command -v curl >/dev/null 2>&1; then
      curl -fsSL -o "$dest" "$url" 2>/dev/null || continue
    else
      return 1
    fi
    chmod +x "$dest"
    if [ -s "$dest" ] && [ -x "$dest" ]; then
      if "$dest" 2>&1 | head -1 | grep -qi udp2raw; then
        return 0
      fi
    fi
    rm -f "$dest"
  done
  return 1
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
  systemctl restart "$WG_TCP_UNIT" 2>/dev/null || systemctl start "$WG_TCP_UNIT" 2>/dev/null || true
  sleep 2
  if ! systemctl is-active --quiet "$WG_TCP_UNIT" 2>/dev/null; then
    journalctl -u "$WG_TCP_UNIT" -n 8 --no-pager 2>/dev/null | tail -5 >&2 || true
    if ! /usr/local/bin/udp2raw 2>&1 | head -1 | grep -qi udp2raw; then
      echo "USK_ERR: wireguard_udp2raw_binary_bad" >&2
    fi
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
