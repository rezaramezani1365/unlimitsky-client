#!/bin/bash
# AmneziaWG (Amnezia VPN) — shared helpers for UnlimitSky

AMNEZIA_CONF_DIR="/etc/amnezia/amneziawg"
AMNEZIA_CONF="${AMNEZIA_CONF_DIR}/awg0.conf"
AMNEZIA_PRIV="${AMNEZIA_CONF_DIR}/server_private.key"
AMNEZIA_PUB="${AMNEZIA_CONF_DIR}/server_public.key"
AMNEZIA_PARAMS="${USK_DATA_ROOT:-/var/lib/unlimitsky}/amnezia/obf.params"
BIVLKED_MGMT="/root/awg/manage_amneziawg.sh"
BIVLKED_AWG_DIR="/root/awg"

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

usk_amnezia_install_packages() {
  usk_amnezia_ensure_deb_src
  apt-get update -qq
  apt-get install -y software-properties-common gnupg2 qrencode curl wget \
    "linux-headers-$(uname -r)" 2>/dev/null || apt-get install -y linux-headers-generic

  if ! command -v awg >/dev/null 2>&1; then
    add-apt-repository -y ppa:amnezia/ppa 2>/dev/null || true
    apt-get update -qq
    apt-get install -y amneziawg || return 1
  fi
  command -v awg >/dev/null 2>&1
}

usk_amnezia_gen_obf_params() {
  local jc=3
  local jmin=$((30 + RANDOM % 21))
  local jmax=$((jmin + 20 + RANDOM % 61))
  local s1=$((RANDOM * 1000 + RANDOM + 1))
  local s2=$((RANDOM * 1000 + RANDOM + 1))
  local s3=$((RANDOM * 1000 + RANDOM + 1))
  local s4=$((RANDOM * 1000 + RANDOM + 1))
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
EOF
  chmod 600 "$AMNEZIA_PARAMS"
}

usk_amnezia_load_obf_params() {
  if [ ! -f "$AMNEZIA_PARAMS" ] && [ -f "$AMNEZIA_CONF" ]; then
    mkdir -p "$(dirname "$AMNEZIA_PARAMS")"
    {
      grep -E '^(Jc|Jmin|Jmax|S[1-4]|H[1-4])' "$AMNEZIA_CONF" 2>/dev/null | while read -r line; do
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
  [ -n "$port" ] && [ "$port" -ge 1 ] 2>/dev/null || port=51821

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
PostUp = iptables -A FORWARD -i awg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o ${main_iface} -j MASQUERADE
PostDown = iptables -D FORWARD -i awg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o ${main_iface} -j MASQUERADE
EOF

  sysctl -w net.ipv4.ip_forward=1
  grep -q 'net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf

  if command -v awg-quick >/dev/null 2>&1; then
    systemctl enable awg-quick@awg0 2>/dev/null || true
    awg-quick down awg0 2>/dev/null || true
    awg-quick up awg0 2>/dev/null || systemctl restart awg-quick@awg0 2>/dev/null || true
  else
    ip link del awg0 2>/dev/null || true
    $awg setconf awg0 "$AMNEZIA_CONF" 2>/dev/null || true
  fi

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
  if command -v awg-quick >/dev/null 2>&1; then
    awg-quick strip awg0 "$AMNEZIA_CONF" 2>/dev/null | $awg syncconf awg0 /dev/stdin 2>/dev/null \
      || systemctl restart awg-quick@awg0 2>/dev/null || true
  else
    systemctl restart awg-quick@awg0 2>/dev/null || true
  fi
}

usk_amnezia_render_client_conf() {
  local name="$1"
  local client_ip="$2"
  local client_priv="$3"
  local server_pub="$4"
  local endpoint="$5"
  local port="$6"

  usk_amnezia_load_obf_params

  cat <<EOF
[Interface]
PrivateKey = ${client_priv}
Address = ${client_ip}/32
DNS = 1.1.1.1, 8.8.8.8
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

[Peer]
PublicKey = ${server_pub}
Endpoint = ${endpoint}:${port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 33
EOF
}

usk_amnezia_try_bivlked_install() {
  local port="$1"
  local ver="v5.14.1"
  local script="/tmp/install_amneziawg_en.sh"
  wget -q -O "$script" "https://raw.githubusercontent.com/bivlked/amneziawg-installer/${ver}/install_amneziawg_en.sh" 2>/dev/null \
    || curl -fsSL -o "$script" "https://raw.githubusercontent.com/bivlked/amneziawg-installer/${ver}/install_amneziawg_en.sh" 2>/dev/null \
    || return 1
  chmod +x "$script"
  bash "$script" --yes --route-amnezia --port="${port}" --preset=mobile --no-tweaks 2>&1
}

usk_amnezia_qr_b64() {
  local conf="$1"
  if command -v qrencode >/dev/null 2>&1; then
    qrencode -t PNG -o - "$conf" 2>/dev/null | base64 -w0 2>/dev/null || qrencode -t PNG -o - "$conf" 2>/dev/null | base64
  fi
}
