#!/bin/bash
# AmneziaWG (Amnezia VPN) — shared helpers for UnlimitSky

AMNEZIA_CONF_DIR="/etc/amnezia/amneziawg"
AMNEZIA_CONF="${AMNEZIA_CONF_DIR}/awg0.conf"
AMNEZIA_PRIV="${AMNEZIA_CONF_DIR}/server_private.key"
AMNEZIA_PUB="${AMNEZIA_CONF_DIR}/server_public.key"
AMNEZIA_PARAMS="${USK_DATA_ROOT:-/var/lib/unlimitsky}/amnezia/obf.params"
AMNEZIA_MODE_FILE="${USK_DATA_ROOT:-/var/lib/unlimitsky}/amnezia/mode"
BIVLKED_MGMT="/root/awg/manage_amneziawg.sh"
BIVLKED_AWG_DIR="/root/awg"
AWG_GO_VERSION="0.2.18"
AWG_GO_MODULE="github.com/amnezia-vpn/amneziawg-go@v${AWG_GO_VERSION}"
AWG_DOCKER_IMAGE="amneziavpn/amneziawg-go:${AWG_GO_VERSION}"
AWG_TOOLS_VERSION="v1.0.20260223"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

usk_amnezia_bivlked() {
  [ -x "$BIVLKED_MGMT" ]
}

usk_amnezia_awg_bin() {
  if command -v awg >/dev/null 2>&1; then
    echo awg
  elif [ -x /usr/bin/awg ]; then
    echo /usr/bin/awg
  else
    return 1
  fi
}

usk_amnezia_main_iface() {
  ip route get 1.1.1.1 2>/dev/null | awk '{print $5; exit}'
}

usk_amnezia_ensure_deb_src() {
  if [ -f /etc/apt/sources.list.d/ubuntu.sources ]; then
    if ! grep -q 'deb-src' /etc/apt/sources.list.d/ubuntu.sources 2>/dev/null; then
      sed -i 's/Types: deb$/Types: deb deb-src/' /etc/apt/sources.list.d/ubuntu.sources 2>/dev/null || true
    fi
  elif [ -f /etc/apt/sources.list ]; then
    grep -q '^deb-src' /etc/apt/sources.list 2>/dev/null || \
      sed -i 's/^deb http/deb-src http/' /etc/apt/sources.list 2>/dev/null || true
  fi
}

usk_amnezia_detect_arch() {
  case "$(uname -m)" in
    x86_64|amd64) echo amd64 ;;
    aarch64|arm64) echo arm64 ;;
    *) echo amd64 ;;
  esac
}

usk_amnezia_userspace_mode() {
  [ -f "$AMNEZIA_MODE_FILE" ] && grep -q userspace "$AMNEZIA_MODE_FILE" 2>/dev/null
}

usk_amnezia_mark_mode() {
  local mode="$1"
  mkdir -p "$(dirname "$AMNEZIA_MODE_FILE")"
  echo "$mode" > "$AMNEZIA_MODE_FILE"
}

usk_amnezia_apt_optional() {
  apt-get update -qq 2>/dev/null || true
  for pkg in "$@"; do
    apt-get install -y "$pkg" 2>/dev/null || true
  done
}

usk_amnezia_unzip_dir() {
  local zipfile="$1"
  local dest="$2"
  if command -v unzip >/dev/null 2>&1; then
    unzip -q -o "$zipfile" -d "$dest" 2>/dev/null && return 0
  fi
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$zipfile" "$dest" <<'PY' 2>/dev/null && return 0
import sys, zipfile, os
z, dest = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(z) as zf:
    zf.extractall(dest)
PY
  fi
  return 1
}

usk_amnezia_download() {
  local url="$1"
  local dest="$2"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "$dest" "$url" 2>/dev/null && return 0
  fi
  if command -v wget >/dev/null 2>&1; then
    wget -q -O "$dest" "$url" 2>/dev/null && return 0
  fi
  return 1
}

usk_amnezia_install_go_docker() {
  local go_bin="/usr/local/bin/amneziawg-go"
  command -v docker >/dev/null 2>&1 || return 1
  docker pull "$AWG_DOCKER_IMAGE" >/dev/null 2>&1 || return 1
  local cid path
  cid=$(docker create "$AWG_DOCKER_IMAGE" 2>/dev/null) || return 1
  for path in /usr/local/bin/amneziawg-go /usr/bin/amneziawg-go /amneziawg-go /bin/amneziawg-go; do
    if docker cp "$cid:${path}" "$go_bin" 2>/dev/null; then
      docker rm "$cid" >/dev/null 2>&1
      chmod +x "$go_bin"
      [ -x "$go_bin" ] && return 0
    fi
  done
  docker rm "$cid" >/dev/null 2>&1
  return 1
}

usk_amnezia_install_go_goinstall() {
  local go_bin="/usr/local/bin/amneziawg-go"
  [ -x "$go_bin" ] && return 0
  if ! command -v go >/dev/null 2>&1; then
    usk_amnezia_apt_optional golang-go
  fi
  command -v go >/dev/null 2>&1 || return 1
  env GO111MODULE=on GOBIN=/usr/local/bin \
    go install "$AWG_GO_MODULE" 2>/dev/null || \
    go install github.com/amnezia-vpn/amneziawg-go@latest 2>/dev/null || return 1
  chmod +x "$go_bin" 2>/dev/null || true
  [ -x "$go_bin" ]
}

usk_amnezia_install_go_bootstrap() {
  local go_bin="/usr/local/bin/amneziawg-go"
  [ -x "$go_bin" ] && return 0
  local arch goarch gover td tarurl
  arch=$(usk_amnezia_detect_arch)
  goarch="$arch"
  gover="1.24.2"
  td=$(mktemp -d)
  tarurl="https://go.dev/dl/go${gover}.linux-${goarch}.tar.gz"
  usk_amnezia_download "$tarurl" "$td/go.tar.gz" || { rm -rf "$td"; return 1; }
  tar -C "$td" -xzf "$td/go.tar.gz" 2>/dev/null || { rm -rf "$td"; return 1; }
  env GO111MODULE=on GOBIN=/usr/local/bin \
    "$td/go/bin/go" install "$AWG_GO_MODULE" 2>/dev/null || \
    "$td/go/bin/go" install github.com/amnezia-vpn/amneziawg-go@latest 2>/dev/null || { rm -rf "$td"; return 1; }
  rm -rf "$td"
  chmod +x "$go_bin" 2>/dev/null || true
  [ -x "$go_bin" ]
}

usk_amnezia_go_cache_path() {
  echo "${USK_DATA_ROOT:-/var/lib/unlimitsky}/cache/amneziawg-go-$(usk_amnezia_detect_arch)"
}

usk_amnezia_install_go_cache() {
  local go_bin="/usr/local/bin/amneziawg-go"
  local cache
  cache=$(usk_amnezia_go_cache_path)
  [ -x "$cache" ] || return 1
  install -m 755 "$cache" "$go_bin"
  [ -x "$go_bin" ]
}

usk_amnezia_save_go_cache() {
  local go_bin="/usr/local/bin/amneziawg-go"
  local cache
  cache=$(usk_amnezia_go_cache_path)
  [ -x "$go_bin" ] || return 0
  mkdir -p "$(dirname "$cache")"
  cp "$go_bin" "$cache" 2>/dev/null && chmod 755 "$cache"
}

usk_amnezia_install_go_binary() {
  local go_bin="/usr/local/bin/amneziawg-go"
  [ -x "$go_bin" ] && return 0
  usk_amnezia_apt_optional ca-certificates curl wget tar git

  if usk_amnezia_install_go_cache; then
    return 0
  fi
  if usk_amnezia_install_go_docker; then
    usk_amnezia_save_go_cache
    return 0
  fi
  if usk_amnezia_install_go_goinstall; then
    usk_amnezia_save_go_cache
    return 0
  fi
  if [ "${USK_AMNEZIA_FAST:-0}" = "1" ] || [ "${USK_AMNEZIA_SKIP_BOOTSTRAP:-0}" = "1" ]; then
    return 1
  fi
  if usk_amnezia_install_go_bootstrap; then
    usk_amnezia_save_go_cache
    return 0
  fi
  return 1
}

usk_amnezia_install_tools_zip() {
  command -v awg >/dev/null 2>&1 && command -v awg-quick >/dev/null 2>&1 && return 0
  usk_amnezia_apt_optional ca-certificates curl wget unzip
  local arch zip_name tmpdir awg_bin quick_bin f base
  arch=$(usk_amnezia_detect_arch)
  zip_name="ubuntu-22.04-amneziawg-tools.zip"
  [ "$arch" = "arm64" ] && zip_name="alpine-3.19-amneziawg-tools.zip"
  tmpdir=$(mktemp -d)
  local url="https://github.com/amnezia-vpn/amneziawg-tools/releases/download/${AWG_TOOLS_VERSION}/${zip_name}"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "$tmpdir/tools.zip" "$url" 2>/dev/null || { rm -rf "$tmpdir"; return 1; }
  elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$tmpdir/tools.zip" "$url" 2>/dev/null || { rm -rf "$tmpdir"; return 1; }
  else
    rm -rf "$tmpdir"
    return 1
  fi
  usk_amnezia_unzip_dir "$tmpdir/tools.zip" "$tmpdir" || { rm -rf "$tmpdir"; return 1; }
  awg_bin=""
  quick_bin=""
  while IFS= read -r f; do
    base=$(basename "$f")
    if [ "$base" = "awg-quick" ]; then
      quick_bin="$f"
    elif [ "$base" = "awg" ]; then
      awg_bin="$f"
    fi
  done <<EOF
$(find "$tmpdir" -type f 2>/dev/null)
EOF
  [ -n "$awg_bin" ] && install -m 755 "$awg_bin" /usr/local/bin/awg
  [ -n "$quick_bin" ] && install -m 755 "$quick_bin" /usr/local/bin/awg-quick
  rm -rf "$tmpdir"
  command -v awg >/dev/null 2>&1 && command -v awg-quick >/dev/null 2>&1
}

usk_amnezia_install_tools_build() {
  command -v awg >/dev/null 2>&1 && command -v awg-quick >/dev/null 2>&1 && return 0
  if ! apt-cache show make >/dev/null 2>&1; then
    return 1
  fi
  usk_amnezia_apt_optional git make gcc
  command -v git >/dev/null 2>&1 && command -v make >/dev/null 2>&1 || return 1
  local td
  td=$(mktemp -d)
  git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-tools.git "$td/tools" 2>/dev/null || { rm -rf "$td"; return 1; }
  make -C "$td/tools/src" 2>/dev/null && make -C "$td/tools/src" install PREFIX=/usr/local 2>/dev/null
  rm -rf "$td"
  command -v awg >/dev/null 2>&1 && command -v awg-quick >/dev/null 2>&1
}

usk_amnezia_install_systemd_unit() {
  if [ -f /lib/systemd/system/awg-quick@.service ] || [ -f /etc/systemd/system/awg-quick@.service ]; then
    return 0
  fi
  local quick_bin
  quick_bin=$(command -v awg-quick 2>/dev/null || echo /usr/local/bin/awg-quick)
  mkdir -p /etc/systemd/system
  cat > /etc/systemd/system/awg-quick@.service <<UNIT
[Unit]
Description=AmneziaWG via awg-quick for %i
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=${quick_bin} up %i
ExecStop=${quick_bin} down %i
Environment=WG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go
Environment=AWG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload 2>/dev/null || true
}

usk_amnezia_setup_userspace_systemd() {
  usk_amnezia_install_systemd_unit
  mkdir -p /etc/systemd/system/awg-quick@.service.d
  cat > /etc/systemd/system/awg-quick@.service.d/unlimitsky-userspace.conf <<'UNIT'
[Service]
Environment=WG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go
Environment=AWG_QUICK_USERSPACE_IMPLEMENTATION=/usr/local/bin/amneziawg-go
UNIT
  systemctl daemon-reload 2>/dev/null || true
  usk_amnezia_mark_mode "userspace"
}

usk_amnezia_install_userspace() {
  usk_amnezia_apt_optional iptables iproute2 ca-certificates curl wget
  usk_amnezia_apt_optional qrencode unzip python3

  if ! usk_amnezia_install_tools_zip; then
    echo "USK_WARN: amnezia_tools_zip_failed" >&2
    if ! usk_amnezia_install_tools_build; then
      echo "USK_ERR: amnezia_tools_install_failed" >&2
      return 1
    fi
  fi
  if ! usk_amnezia_install_go_binary; then
    echo "USK_ERR: amnezia_go_download_failed" >&2
    return 1
  fi
  usk_amnezia_setup_userspace_systemd
  command -v awg >/dev/null 2>&1 && command -v awg-quick >/dev/null 2>&1
}

usk_amnezia_install_kernel_packages() {
  usk_amnezia_ensure_deb_src
  apt-get update -qq
  apt-get install -y software-properties-common gnupg2 qrencode curl wget iproute2 || true
  local kver headers_pkg
  kver=$(uname -r)
  headers_pkg="linux-headers-${kver}"
  if ! apt-cache show "$headers_pkg" >/dev/null 2>&1; then
    apt-get install -y linux-headers-generic 2>/dev/null || true
  else
    apt-get install -y "$headers_pkg" 2>/dev/null || apt-get install -y linux-headers-generic 2>/dev/null || true
  fi
  if ! command -v awg >/dev/null 2>&1; then
    add-apt-repository -y ppa:amnezia/ppa 2>/dev/null || true
    apt-get update -qq
    apt-get install -y amneziawg 2>/dev/null || return 1
  fi
  usk_amnezia_mark_mode "kernel"
  command -v awg >/dev/null 2>&1
}

usk_amnezia_install_packages() {
  usk_amnezia_install_userspace
}

usk_amnezia_ensure_running() {
  if systemctl is-active --quiet awg-quick@awg0 2>/dev/null; then
    return 0
  fi
  if command -v awg-quick >/dev/null 2>&1 && [ -f "$AMNEZIA_CONF" ]; then
    awg-quick down awg0 2>/dev/null || true
    awg-quick up awg0 2>/dev/null && return 0
    systemctl enable awg-quick@awg0 2>/dev/null || true
    systemctl restart awg-quick@awg0 2>/dev/null || systemctl start awg-quick@awg0 2>/dev/null || true
  fi
  sleep 1
  if command -v awg >/dev/null 2>&1 && awg show awg0 >/dev/null 2>&1; then
    return 0
  fi
  return 1
}

usk_amnezia_verify_installed() {
  [ -f "$AMNEZIA_CONF" ] && command -v awg >/dev/null 2>&1
}

# Official AmneziaWG 2.0 default I1 (DNS/Yandex CPS) — docs.amnezia.org
AWG_DEFAULT_I1='<r 2><b 0x8580000100010000000004796162730679616e6465780272750000010001c00c000100010000026d000457fa27d1>'

usk_amnezia_gen_obf_params() {
  local jc=$((4 + RANDOM % 4))
  local jmin=$((64 + RANDOM % 200))
  local jmax=$((jmin + 64 + RANDOM % 400))
  [ "$jmax" -gt 1024 ] && jmax=1024
  [ "$jmax" -le "$jmin" ] && jmax=$((jmin + 64))
  local s1=$((RANDOM % 65))
  local s2=$((RANDOM % 65))
  local s3=$((RANDOM % 65))
  local s4=$((RANDOM % 33))
  local h1lo=$((100000 + RANDOM % 900000))
  local h1hi=$((h1lo + 500000))
  [ "$h1hi" -gt 2147483647 ] && h1hi=2147483647
  local h2lo=$((100000 + RANDOM % 900000))
  local h2hi=$((h2lo + 500000))
  [ "$h2hi" -gt 2147483647 ] && h2hi=2147483647
  local h3lo=$((100000 + RANDOM % 900000))
  local h3hi=$((h3lo + 500000))
  [ "$h3hi" -gt 2147483647 ] && h3hi=2147483647
  local h4lo=$((100000 + RANDOM % 900000))
  local h4hi=$((h4lo + 500000))
  [ "$h4hi" -gt 2147483647 ] && h4hi=2147483647

  mkdir -p "$(dirname "$AMNEZIA_PARAMS")"
  cat > "$AMNEZIA_PARAMS" <<EOF
AWG_Jc=${jc}
AWG_Jmin=${jmin}
AWG_Jmax=${jmax}
AWG_S1=${s1}
AWG_S2=${s2}
AWG_S3=${s3}
AWG_S4=${s4}
AWG_H1=${h1lo}-${h1hi}
AWG_H2=${h2lo}-${h2hi}
AWG_H3=${h3lo}-${h3hi}
AWG_H4=${h4lo}-${h4hi}
AWG_I1=${AWG_DEFAULT_I1}
EOF
  chmod 600 "$AMNEZIA_PARAMS"
}

usk_amnezia_fixup_obf_params() {
  usk_amnezia_load_obf_params 2>/dev/null || true
  local need_fix=0
  for key in AWG_S1 AWG_S2 AWG_S3; do
    local v="${!key:-0}"
    v=$(echo "$v" | tr -dc '0-9')
    [ -z "$v" ] && v=0
    [ "$v" -gt 64 ] && need_fix=1
  done
  local s4v="${AWG_S4:-0}"
  s4v=$(echo "$s4v" | tr -dc '0-9')
  [ -z "$s4v" ] && s4v=0
  [ "$s4v" -gt 32 ] && need_fix=1
  local jmin="${AWG_Jmin:-0}"
  jmin=$(echo "$jmin" | tr -dc '0-9')
  [ -z "$jmin" ] && jmin=0
  [ "$jmin" -lt 64 ] && need_fix=1
  [ -z "${AWG_I1:-}" ] && need_fix=1
  if [ "$need_fix" -eq 1 ]; then
    usk_amnezia_gen_obf_params
    usk_amnezia_load_obf_params
    return 0
  fi
  return 1
}

usk_amnezia_sync_interface_from_params() {
  [ -f "$AMNEZIA_CONF" ] || return 1
  usk_amnezia_load_obf_params || return 1

  local port priv addr main_iface peer_section
  port=$(grep -E '^ListenPort' "$AMNEZIA_CONF" 2>/dev/null | head -1 | sed 's/.*=[[:space:]]*//')
  port=$(echo "$port" | tr -dc '0-9')
  [ -n "$port" ] || port=443
  priv=$(grep -E '^PrivateKey' "$AMNEZIA_CONF" 2>/dev/null | head -1 | sed 's/.*=[[:space:]]*//')
  addr=$(grep -E '^Address' "$AMNEZIA_CONF" 2>/dev/null | head -1 | sed 's/.*=[[:space:]]*//')
  [ -n "$priv" ] && [ -n "$addr" ] || return 1

  main_iface=$(usk_amnezia_main_iface)
  main_iface="${main_iface:-eth0}"
  peer_section=$(awk '/^\[Peer\]/{found=1} found{print}' "$AMNEZIA_CONF" 2>/dev/null)

  local tmp
  tmp=$(mktemp)
  cat > "$tmp" <<EOF
[Interface]
Address = ${addr}
ListenPort = ${port}
PrivateKey = ${priv}
Jc = ${AWG_Jc}
Jmin = ${AWG_Jmin}
Jmax = ${AWG_Jmax}
S1 = ${AWG_S1}
S2 = ${AWG_S2}
S3 = ${AWG_S3}
S4 = ${AWG_S4}
H1 = ${AWG_H1}
H2 = ${AWG_H2}
H3 = ${AWG_H3}
H4 = ${AWG_H4}
I1 = ${AWG_I1}
PostUp = iptables -A FORWARD -i awg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${main_iface} -j MASQUERADE
PostDown = iptables -D FORWARD -i awg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${main_iface} -j MASQUERADE
EOF
  if [ -n "$peer_section" ]; then
    printf '\n%s\n' "$peer_section" >> "$tmp"
  fi
  mv "$tmp" "$AMNEZIA_CONF"
  usk_amnezia_apply_conf
}

usk_amnezia_load_obf_params() {
  if [ ! -f "$AMNEZIA_PARAMS" ] && [ -f "$AMNEZIA_CONF" ]; then
    mkdir -p "$(dirname "$AMNEZIA_PARAMS")"
    {
      grep -E '^(Jc|Jmin|Jmax|S[1-4]|H[1-4]|I1)' "$AMNEZIA_CONF" 2>/dev/null | while read -r line; do
        key="${line%%=*}"
        key=$(echo "$key" | tr -d ' ')
        val="${line#*=}"
        val=$(echo "$val" | sed 's/^[[:space:]]*//')
        echo "AWG_${key}=${val}"
      done
    } > "$AMNEZIA_PARAMS"
  fi
  [ -f "$AMNEZIA_PARAMS" ] && . "$AMNEZIA_PARAMS"
}

usk_amnezia_init_server() {
  local port="$1"
  local subnet="${2:-10.9.9.0/24}"
  port=$(echo "$port" | tr -dc '0-9')
  [ -n "$port" ] && [ "$port" -ge 1 ] 2>/dev/null || port=443
  [ "$port" -gt 9999 ] 2>/dev/null && port=443

  usk_amnezia_fixup_obf_params 2>/dev/null || true

  local base="${subnet%.*}"
  local server_ip="${base}.1"
  local main_iface
  main_iface=$(usk_amnezia_main_iface)
  main_iface="${main_iface:-eth0}"

  mkdir -p "$AMNEZIA_CONF_DIR"
  local awg
  awg=$(usk_amnezia_awg_bin) || return 1

  if [ ! -f "$AMNEZIA_PRIV" ]; then
    umask 077
    $awg genkey | tee "$AMNEZIA_PRIV" | $awg pubkey > "$AMNEZIA_PUB"
  fi

  if [ ! -f "$AMNEZIA_PARAMS" ]; then
    usk_amnezia_gen_obf_params
  fi
  usk_amnezia_load_obf_params

  local priv
  priv=$(tr -d '\n\r' < "$AMNEZIA_PRIV")

  cat > "$AMNEZIA_CONF" <<EOF
[Interface]
Address = ${server_ip}/24
ListenPort = ${port}
PrivateKey = ${priv}
Jc = ${AWG_Jc}
Jmin = ${AWG_Jmin}
Jmax = ${AWG_Jmax}
S1 = ${AWG_S1}
S2 = ${AWG_S2}
S3 = ${AWG_S3}
S4 = ${AWG_S4}
H1 = ${AWG_H1}
H2 = ${AWG_H2}
H3 = ${AWG_H3}
H4 = ${AWG_H4}
I1 = ${AWG_I1}
PostUp = iptables -A FORWARD -i awg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${main_iface} -j MASQUERADE
PostDown = iptables -D FORWARD -i awg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${main_iface} -j MASQUERADE
EOF

  sysctl -w net.ipv4.ip_forward=1
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

  if command -v awg-quick >/dev/null 2>&1; then
    systemctl enable awg-quick@awg0 2>/dev/null || true
    awg-quick down awg0 2>/dev/null || true
    awg-quick up awg0 2>/dev/null || true
    usk_amnezia_ensure_running || systemctl restart awg-quick@awg0 2>/dev/null || true
  else
    ip link del awg0 2>/dev/null || true
    $awg setconf awg0 "$AMNEZIA_CONF" 2>/dev/null || true
  fi

  usk_amnezia_ensure_running || true

  ensure_ufw_port "$port" udp amnezia-awg
  echo "$port"
}

usk_amnezia_server_port() {
  if [ -f "$AMNEZIA_CONF" ]; then
    grep -E '^ListenPort' "$AMNEZIA_CONF" 2>/dev/null | awk '{print $3}'
  elif usk_amnezia_bivlked && [ -f /root/awg/awgsetup_cfg.init ]; then
    grep -E '^AWG_PORT=' /root/awg/awgsetup_cfg.init 2>/dev/null | cut -d= -f2 | tr -d "'\" "
  fi
}

usk_amnezia_server_pubkey() {
  if [ -f "$AMNEZIA_PUB" ]; then
    tr -d '\n\r' < "$AMNEZIA_PUB"
  elif [ -f /root/awg/server_public.key ]; then
    tr -d '\n\r' < /root/awg/server_public.key
  elif [ -f "$AMNEZIA_PRIV" ]; then
    local awg
    awg=$(usk_amnezia_awg_bin) || return 1
    tr -d '\n\r' < "$AMNEZIA_PRIV" | $awg pubkey
  fi
}

usk_amnezia_apply_conf() {
  local awg
  awg=$(usk_amnezia_awg_bin) || return 1
  usk_amnezia_ensure_running || true
  if command -v awg-quick >/dev/null 2>&1 && awg show awg0 >/dev/null 2>&1; then
    awg-quick strip awg0 "$AMNEZIA_CONF" 2>/dev/null | $awg syncconf awg0 /dev/stdin 2>/dev/null \
      || systemctl restart awg-quick@awg0 2>/dev/null || true
  else
    systemctl restart awg-quick@awg0 2>/dev/null || awg-quick up awg0 2>/dev/null || true
  fi
}

usk_amnezia_render_client_conf() {
  local name="$1"
  local client_ip="$2"
  local client_priv="$3"
  local server_pub="$4"
  local endpoint="$5"
  local port="$6"
  local client_psk="${7:-}"

  usk_amnezia_load_obf_params

  cat <<EOF
[Interface]
Address = ${client_ip}/32
DNS = 1.1.1.1, 1.0.0.1
PrivateKey = ${client_priv}
MTU = 1280
Jc = ${AWG_Jc}
Jmin = ${AWG_Jmin}
Jmax = ${AWG_Jmax}
S1 = ${AWG_S1}
S2 = ${AWG_S2}
S3 = ${AWG_S3}
S4 = ${AWG_S4}
H1 = ${AWG_H1}
H2 = ${AWG_H2}
H3 = ${AWG_H3}
H4 = ${AWG_H4}
EOF
  if [ -n "${AWG_I1:-}" ]; then
    echo "I1 = ${AWG_I1}"
  fi
  cat <<EOF

[Peer]
PublicKey = ${server_pub}
EOF
  if [ -n "$client_psk" ]; then
    echo "PresharedKey = ${client_psk}"
  fi
  cat <<EOF
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = ${endpoint}:${port}
PersistentKeepalive = 25
EOF
}

usk_amnezia_try_bivlked_install() {
  return 1
}

usk_amnezia_qr_b64() {
  local payload="$1"
  [ -n "$payload" ] || return 0
  if command -v qrencode >/dev/null 2>&1; then
    local ecc="M"
    [ "${#payload}" -gt 900 ] && ecc="L"
    qrencode -l "$ecc" -s 5 -m 2 -t PNG -o - "$payload" 2>/dev/null | base64 -w0 2>/dev/null \
      || qrencode -l "$ecc" -s 5 -m 2 -t PNG -o - "$payload" 2>/dev/null | base64
  fi
}

usk_amnezia_encode_payloads() {
  local conf="$1"
  local hostname="$2"
  local script tmpconf out
  [ -n "$conf" ] || return 1
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/amnezia-vpn-uri.py"
  tmpconf=$(mktemp)
  printf '%s\n' "$conf" > "$tmpconf"
  out=""
  if [ -f "$script" ] && command -v python3 >/dev/null 2>&1; then
    out=$(python3 "$script" all "$tmpconf" "$hostname" 2>/dev/null || true)
  fi
  rm -f "$tmpconf"
  [ -n "$out" ] && printf '%s' "$out"
}

usk_amnezia_generate_vpn_uri() {
  local conf="$1"
  local hostname="$2"
  local payloads uri
  payloads=$(usk_amnezia_encode_payloads "$conf" "$hostname" 2>/dev/null || true)
  [ -n "$payloads" ] || return 1
  if command -v jq >/dev/null 2>&1; then
    uri=$(echo "$payloads" | jq -r '.vpn_uri // empty' 2>/dev/null)
  else
    uri=$(echo "$payloads" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('vpn_uri',''))" 2>/dev/null || true)
  fi
  [ -n "$uri" ] && printf '%s' "$uri"
}
