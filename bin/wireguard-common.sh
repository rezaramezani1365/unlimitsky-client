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

usk_wg_conf_port() {
  local conf="/etc/wireguard/wg0.conf"
  local port
  [ -f "$conf" ] || return 1
  port=$(grep -E '^ListenPort' "$conf" 2>/dev/null | sed 's/.*=[[:space:]]*//' | tr -dc '0-9')
  if [ -n "$port" ]; then
    echo "$port"
    return 0
  fi
  if wg show wg0 listen-port >/dev/null 2>&1; then
    wg show wg0 listen-port 2>/dev/null | tr -dc '0-9'
    return 0
  fi
  return 1
}

usk_wg_ensure_server_keys() {
  local conf="/etc/wireguard/wg0.conf"
  [ -f "$conf" ] || return 1

  if [ -f /etc/wireguard/server_public.key ] && [ -s /etc/wireguard/server_public.key ]; then
    return 0
  fi

  if [ -f /etc/wireguard/server_private.key ]; then
    wg pubkey < /etc/wireguard/server_private.key > /etc/wireguard/server_public.key 2>/dev/null
  elif grep -qE '^PrivateKey\s*=' "$conf"; then
    grep -E '^PrivateKey\s*=' "$conf" | head -1 | sed 's/.*=[[:space:]]*//' | wg pubkey > /etc/wireguard/server_public.key 2>/dev/null
  elif wg show wg0 public-key >/dev/null 2>&1; then
    wg show wg0 public-key > /etc/wireguard/server_public.key 2>/dev/null
  fi

  if [ -s /etc/wireguard/server_public.key ]; then
    chmod 600 /etc/wireguard/server_public.key 2>/dev/null || true
    return 0
  fi
  return 1
}

# Missing ListenPort ΓåÆ random port each restart; client Endpoint never matches.
usk_wg_ensure_listen_port() {
  local conf="/etc/wireguard/wg0.conf"
  local port="${1:-51820}"
  port=$(echo "$port" | tr -dc '0-9')
  [ -n "$port" ] || port=51820
  [ -f "$conf" ] || return 1

  sed -i '/^SaveConfig/d' "$conf" 2>/dev/null || true

  if grep -qE '^ListenPort\s*=' "$conf"; then
    port=$(grep -E '^ListenPort' "$conf" | sed 's/.*=[[:space:]]*//' | tr -dc '0-9')
    [ -n "$port" ] || port=51820
  elif grep -qE '^Address\s*=' "$conf"; then
    sed -i "/^Address/a ListenPort = ${port}" "$conf"
  else
    sed -i "/^\[Interface\]/a ListenPort = ${port}" "$conf"
  fi

  if wg show wg0 >/dev/null 2>&1; then
    wg set wg0 listen-port "$port" 2>/dev/null || true
  fi
  echo "$port"
  return 0
}

usk_wg_ensure_base_config() {
  usk_wg_ensure_server_keys || return 1
  usk_wg_ensure_listen_port 51820 >/dev/null || return 1
  usk_wg_fix_postup_conf 2>/dev/null || true
  return 0
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
  local stripped
  [ -f "$conf" ] || return 1
  command -v wg-quick >/dev/null 2>&1 || return 1
  wg show wg0 >/dev/null 2>&1 || return 1
  stripped=$(mktemp)
  wg-quick strip wg0 "$conf" > "$stripped" 2>/dev/null || { rm -f "$stripped"; return 1; }
  wg syncconf wg0 "$stripped" 2>/dev/null
  local rc=$?
  rm -f "$stripped"
  return $rc
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
    usk_wg_ensure_base_config 2>/dev/null || true
    usk_wg_sync_peers_from_conf 2>/dev/null || true
    usk_wg_ensure_nat
    return 0
  fi
  [ -f /etc/wireguard/wg0.conf ] || return 1

  usk_wg_ensure_base_config || return 1
  usk_wg_repair_conf || return 1

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

# UFW resets raw iptables NAT on reload ΓÇö persist MASQUERADE in before.rules.
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

WG_TCP_LAST_ERR=""

usk_wg_udp2raw_valid() {
  local f="${1:-/usr/local/bin/udp2raw}"
  local sz
  [ -f "$f" ] || return 1
  [ -x "$f" ] || return 1
  sz=$(wc -c < "$f" 2>/dev/null | tr -dc '0-9')
  [ -n "$sz" ] && [ "$sz" -ge 8192 ] 2>/dev/null || return 1
  "$f" 2>&1 | head -3 | grep -qi udp2raw || return 1
  return 0
}

usk_wg_udp2raw_arch_member() {
  case "$(uname -m 2>/dev/null || echo amd64)" in
    x86_64|amd64) echo udp2raw_amd64 ;;
    aarch64|arm64) echo udp2raw_arm ;;
    armv7l|armv6l) echo udp2raw_arm ;;
    i686|i386) echo udp2raw_x86 ;;
    mips) echo udp2raw_mips24kc_be ;;
    mipsel) echo udp2raw_mips24kc_le ;;
    *) return 1 ;;
  esac
}

usk_wg_download_file() {
  local url="$1" dest="$2" min_bytes="${3:-4096}"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --connect-timeout 20 --max-time 120 -o "$dest" "$url" 2>/dev/null || return 1
  elif command -v wget >/dev/null 2>&1; then
    wget -q --tries=2 --timeout=30 -O "$dest" "$url" 2>/dev/null || return 1
  else
    return 1
  fi
  local sz
  sz=$(wc -c < "$dest" 2>/dev/null | tr -dc '0-9')
  [ -n "$sz" ] && [ "$sz" -ge "$min_bytes" ] 2>/dev/null || { rm -f "$dest"; return 1; }
  return 0
}

usk_wg_extract_udp2raw_from_tar() {
  local tarfile="$1" member="$2" dest="$3"
  local td
  td=$(mktemp -d)
  if ! tar -xzf "$tarfile" -C "$td" "$member" 2>/dev/null; then
    tar -xzf "$tarfile" -C "$td" 2>/dev/null || { rm -rf "$td"; return 1; }
  fi
  if [ ! -f "$td/$member" ]; then
    rm -rf "$td"
    return 1
  fi
  cp "$td/$member" "$dest"
  chmod +x "$dest"
  rm -rf "$td"
  return 0
}

usk_wg_install_udp2raw_from_tar() {
  local dest="$1" member="$2"
  local url tarfile
  tarfile=$(mktemp)
  for url in \
    "https://github.com/wangyu-/udp2raw/releases/download/20230206.0/udp2raw_binaries.tar.gz" \
    "https://github.com/wangyu-/udp2raw/releases/download/20200818.0/udp2raw_binaries.tar.gz"; do
    rm -f "$tarfile"
    usk_wg_download_file "$url" "$tarfile" 65536 || continue
    rm -f "$dest"
    if usk_wg_extract_udp2raw_from_tar "$tarfile" "$member" "$dest" && usk_wg_udp2raw_valid "$dest"; then
      rm -f "$tarfile"
      return 0
    fi
    rm -f "$dest"
  done
  rm -f "$tarfile"
  return 1
}

usk_wg_compile_udp2raw() {
  local dest="$1"
  local td repo bin
  td=$(mktemp -d)
  apt-get update -qq 2>/dev/null || true
  apt-get install -y -qq build-essential git ca-certificates 2>/dev/null || true
  repo="$td/udp2raw"
  if ! git clone --depth 1 --branch 20230206.0 https://github.com/wangyu-/udp2raw.git "$repo" 2>/dev/null; then
    git clone --depth 1 https://github.com/wangyu-/udp2raw.git "$repo" 2>/dev/null || { rm -rf "$td"; return 1; }
  fi
  if ! make -C "$repo" -j"$(nproc 2>/dev/null || echo 2)" 2>/dev/null; then
    rm -rf "$td"
    return 1
  fi
  bin=""
  if [ -f "$repo/udp2raw" ]; then
    bin="$repo/udp2raw"
  else
    bin=$(find "$repo" -maxdepth 1 -type f -name 'udp2raw*' ! -name '*.o' 2>/dev/null | head -1)
  fi
  if [ -z "$bin" ] || [ ! -f "$bin" ]; then
    rm -rf "$td"
    return 1
  fi
  cp "$bin" "$dest"
  chmod +x "$dest"
  rm -rf "$td"
  usk_wg_udp2raw_valid "$dest"
}

usk_wg_install_udp2raw() {
  local dest="/usr/local/bin/udp2raw"
  local arch member

  if usk_wg_udp2raw_valid "$dest"; then
    return 0
  fi
  rm -f "$dest"

  if command -v udp2raw >/dev/null 2>&1; then
    local sysbin
    sysbin=$(command -v udp2raw)
    if usk_wg_udp2raw_valid "$sysbin"; then
      ln -sf "$sysbin" "$dest" 2>/dev/null || cp "$sysbin" "$dest"
      chmod +x "$dest"
      usk_wg_udp2raw_valid "$dest" && return 0
    fi
  fi

  member=$(usk_wg_udp2raw_arch_member) || member=""
  arch=$(uname -m 2>/dev/null || echo unknown)
  if [ -n "$member" ] && usk_wg_install_udp2raw_from_tar "$dest" "$member"; then
    return 0
  fi

  if usk_wg_compile_udp2raw "$dest"; then
    return 0
  fi

  rm -f "$dest"
  WG_TCP_LAST_ERR="wireguard_udp2raw_download_failed"
  echo "USK_ERR: wireguard_udp2raw_download_failed arch=${arch}" >&2
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

  if ! usk_wg_install_udp2raw; then
    WG_TCP_LAST_ERR="${WG_TCP_LAST_ERR:-wireguard_udp2raw_download_failed}"
    return 1
  fi

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
AmbientCapabilities=CAP_NET_RAW CAP_NET_ADMIN
CapabilityBoundingSet=CAP_NET_RAW CAP_NET_ADMIN
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
UNIT

  systemctl daemon-reload
  systemctl enable "$WG_TCP_UNIT" 2>/dev/null || true
  systemctl stop "$WG_TCP_UNIT" 2>/dev/null || true
  systemctl reset-failed "$WG_TCP_UNIT" 2>/dev/null || true
  systemctl restart "$WG_TCP_UNIT" 2>/dev/null || systemctl start "$WG_TCP_UNIT" 2>/dev/null || true
  sleep 3
  local active=0 listening=0
  systemctl is-active --quiet "$WG_TCP_UNIT" 2>/dev/null && active=1
  if command -v ss >/dev/null 2>&1; then
    ss -H -ltn "sport = :${tcp_port}" 2>/dev/null | grep -q . && listening=1
  elif command -v netstat >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | grep -q ":${tcp_port} " && listening=1
  else
    listening=$active
  fi
  if [ "$active" -ne 1 ] || [ "$listening" -ne 1 ]; then
    journalctl -u "$WG_TCP_UNIT" -n 12 --no-pager 2>/dev/null | tail -8 >&2 || true
    if ! usk_wg_udp2raw_valid /usr/local/bin/udp2raw; then
      WG_TCP_LAST_ERR="wireguard_udp2raw_binary_bad"
      echo "USK_ERR: wireguard_udp2raw_binary_bad" >&2
      return 1
    fi
    WG_TCP_LAST_ERR="wireguard_tcp_bridge_start_failed"
    echo "USK_ERR: wireguard_tcp_bridge_start_failed port=${tcp_port}" >&2
    return 1
  fi
  ensure_ufw_port "$tcp_port" tcp wireguard-tcp
  WG_TCP_LAST_ERR=""
  return 0
}

usk_wg_setup_tcp_bridge_retry() {
  local wg_port="$1"
  shift
  local try_port err=""
  for try_port in "$@"; do
    [ -n "$try_port" ] || continue
    if usk_wg_setup_tcp_bridge "$wg_port" "$try_port"; then
      return 0
    fi
    err="${WG_TCP_LAST_ERR:-wireguard_tcp_bridge_start_failed}"
  done
  WG_TCP_LAST_ERR="$err"
  return 1
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
