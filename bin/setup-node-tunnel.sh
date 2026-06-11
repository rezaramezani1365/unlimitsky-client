#!/bin/bash
# Node-side WireGuard tunnel to Hub — NATs egress from hub tunnel subnet to node public IP.
# Usage:
#   setup-node-tunnel.sh init
#   setup-node-tunnel.sh ensure <node_id> <hub_pubkey> <hub_endpoint_ip> <hub_wg_port>
#   setup-node-tunnel.sh pubkey <node_id>
#   setup-node-tunnel.sh status <node_id>
#
set -euo pipefail

NODE_ROOT="${USK_NODE_ROOT:-/opt/unlimitsky-node}"
STATE_DIR="${NODE_ROOT}/data/tunnel"
WG_DIR="/etc/wireguard"
CRON_FILE="/etc/cron.d/unlimitsky-node-tunnel"

usk_err() { echo "USK_ERR: $*"; exit 1; }

ensure_wg() {
  command -v wg >/dev/null 2>&1 || {
    apt-get update -qq 2>/dev/null || true
    apt-get install -y wireguard wireguard-tools jq iptables 2>/dev/null || true
  }
  command -v wg >/dev/null 2>&1 || usk_err "wireguard_required"
  command -v wg-quick >/dev/null 2>&1 || usk_err "wireguard_required"
}

ensure_jq() {
  command -v jq >/dev/null 2>&1 || {
    apt-get install -y jq 2>/dev/null || true
  }
  command -v jq >/dev/null 2>&1 || usk_err "jq_required"
}

node_hash() {
  printf '%s' "$1" | md5sum | awk '{print $1}'
}

node_octet() {
  local h o
  h=$(node_hash "$1" | cut -c1-2)
  o=$((16#$h))
  [ "$o" -lt 1 ] && o=1
  [ "$o" -gt 254 ] && o=254
  echo "$o"
}

node_wg_port() {
  local h p
  h=$(node_hash "$1" | cut -c3-6)
  p=$((51820 + (16#$h % 2000)))
  echo "$p"
}

node_iface() {
  printf 'usk%s' "$(node_hash "$1" | cut -c1-8)"
}

state_file() {
  echo "${STATE_DIR}/$1.json"
}

detect_wan_if() {
  local iface
  iface=$(ip -4 route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')
  [ -n "$iface" ] && echo "$iface" && return
  iface=$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $2; exit}')
  [ -n "$iface" ] && echo "$iface" && return
  echo "eth0"
}

enable_forwarding() {
  sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 || true
  if [ -f /etc/sysctl.conf ] && ! grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf 2>/dev/null; then
    echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  fi
}

assign_node_network() {
  local id="$1" octet
  octet=$(node_octet "$id")
  HUB_TUN_IP="10.66.${octet}.1"
  NODE_TUN_IP="10.66.${octet}.2"
  SUBNET="10.66.${octet}.0/24"
  NODE_WG_PORT=$(node_wg_port "$id")
  IFACE=$(node_iface "$id")
}

load_state() {
  local id="$1" sf
  sf=$(state_file "$id")
  [ -f "$sf" ] || return 1
  NODE_PRIV=$(jq -r '.node_private_key // empty' "$sf")
  NODE_PUB=$(jq -r '.node_public_key // empty' "$sf")
  HUB_PUB=$(jq -r '.hub_public_key // empty' "$sf")
  HUB_TUN_IP=$(jq -r '.hub_tunnel_ip // empty' "$sf")
  NODE_TUN_IP=$(jq -r '.node_tunnel_ip // empty' "$sf")
  SUBNET=$(jq -r '.subnet // empty' "$sf")
  NODE_WG_PORT=$(jq -r '.node_wg_port // empty' "$sf")
  HUB_WG_PORT=$(jq -r '.hub_wg_port // empty' "$sf")
  IFACE=$(jq -r '.iface // empty' "$sf")
  HUB_ENDPOINT=$(jq -r '.hub_endpoint // empty' "$sf")
  [ -n "$NODE_PRIV" ] && [ -n "$NODE_PUB" ]
}

gen_node_keys() {
  local tmp
  tmp=$(mktemp -d)
  umask 077
  wg genkey | tee "${tmp}/priv" | wg pubkey > "${tmp}/pub"
  NODE_PRIV=$(cat "${tmp}/priv")
  NODE_PUB=$(cat "${tmp}/pub")
  rm -rf "$tmp"
}

save_state() {
  local id="$1"
  mkdir -p "$STATE_DIR"
  jq -n \
    --arg id "$id" \
    --arg node_priv "$NODE_PRIV" \
    --arg node_pub "$NODE_PUB" \
    --arg hub_pub "${HUB_PUB:-}" \
    --arg hub_tun_ip "$HUB_TUN_IP" \
    --arg node_tun_ip "$NODE_TUN_IP" \
    --arg subnet "$SUBNET" \
    --argjson node_port "$NODE_WG_PORT" \
    --argjson hub_port "${HUB_WG_PORT:-0}" \
    --arg iface "$IFACE" \
    --arg hub_endpoint "${HUB_ENDPOINT:-}" \
    '{node_id:$id, node_private_key:$node_priv, node_public_key:$node_pub, hub_public_key:$hub_pub,
      hub_tunnel_ip:$hub_tun_ip, node_tunnel_ip:$node_tun_ip, subnet:$subnet,
      node_wg_port:$node_port, hub_wg_port:$hub_port, iface:$iface, hub_endpoint:$hub_endpoint,
      updated_at:(now|todate)}' > "$(state_file "$id")"
  chmod 600 "$(state_file "$id")"
}

write_wg_conf() {
  local id="$1" wan conf="${WG_DIR}/${IFACE}.conf"
  wan=$(detect_wan_if)
  mkdir -p "$WG_DIR"
  chmod 700 "$WG_DIR"
  cat > "$conf" <<EOF
# unlimitsky node←hub egress tunnel (${id})
[Interface]
Address = ${NODE_TUN_IP}/24
ListenPort = ${NODE_WG_PORT}
PrivateKey = ${NODE_PRIV}
Table = off
PostUp = sysctl -w net.ipv4.ip_forward=1; iptables -C FORWARD -i %i -j ACCEPT 2>/dev/null || iptables -A FORWARD -i %i -j ACCEPT; iptables -C FORWARD -o %i -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || iptables -A FORWARD -o %i -m state --state ESTABLISHED,RELATED -j ACCEPT; iptables -t nat -C POSTROUTING -s ${SUBNET} -o ${wan} -j MASQUERADE 2>/dev/null || iptables -t nat -A POSTROUTING -s ${SUBNET} -o ${wan} -j MASQUERADE
PostDown = iptables -D FORWARD -i %i -j ACCEPT 2>/dev/null || true; iptables -D FORWARD -o %i -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true; iptables -t nat -D POSTROUTING -s ${SUBNET} -o ${wan} -j MASQUERADE 2>/dev/null || true

[Peer]
PublicKey = ${HUB_PUB}
Endpoint = ${HUB_ENDPOINT}:${HUB_WG_PORT}
AllowedIPs = ${HUB_TUN_IP}/32
PersistentKeepalive = 25
EOF
  chmod 600 "$conf"
}

ensure_node_firewall() {
  local port="$1"
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi 'Status: active'; then
    ufw allow "${port}/udp" comment "unlimitsky-hub-wg" >/dev/null 2>&1 || true
  fi
}

install_reboot_hook() {
  mkdir -p "$STATE_DIR"
  cat > "$CRON_FILE" <<EOF
@reboot root for f in ${STATE_DIR}/*.json; do [ -f "\$f" ] || continue; id=\$(basename "\$f" .json); ${NODE_ROOT}/bin/setup-node-tunnel.sh ensure-boot "\$id" >/dev/null 2>&1; done
EOF
  chmod 644 "$CRON_FILE" 2>/dev/null || true
}

cmd_init() {
  ensure_wg
  ensure_jq
  enable_forwarding
  mkdir -p "$STATE_DIR"
  install_reboot_hook
  echo "USK_OK: tunnel_init"
}

cmd_pubkey() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_wg
  ensure_jq
  assign_node_network "$id"
  if load_state "$id" 2>/dev/null; then
    [ -n "${NODE_PRIV:-}" ] || gen_node_keys
  else
    gen_node_keys
  fi
  save_state "$id"
  ensure_node_firewall "$NODE_WG_PORT"
  echo -n "USK_JSON:"
  jq -cn \
    --arg id "$id" \
    --arg node_pub "$NODE_PUB" \
    --argjson node_port "$NODE_WG_PORT" \
    --arg node_tun_ip "$NODE_TUN_IP" \
    '{ok:true, node_id:$id, node_public_key:$node_pub, node_wg_port:$node_port, node_tunnel_ip:$node_tun_ip}'
}

cmd_ensure() {
  local id="${1:-}" hub_pub="${2:-}" hub_endpoint="${3:-}" hub_wg_port="${4:-}"
  [ -n "$id" ] && [ -n "$hub_pub" ] && [ -n "$hub_endpoint" ] && [ -n "$hub_wg_port" ] || usk_err "missing_args"
  ensure_wg
  ensure_jq
  enable_forwarding
  assign_node_network "$id"
  if load_state "$id" 2>/dev/null; then
    [ -n "${NODE_PRIV:-}" ] || gen_node_keys
  else
    gen_node_keys
  fi
  HUB_PUB="$hub_pub"
  HUB_ENDPOINT="$hub_endpoint"
  HUB_WG_PORT="$hub_wg_port"
  write_wg_conf "$id"
  systemctl enable "wg-quick@${IFACE}" 2>/dev/null || true
  wg-quick down "$IFACE" 2>/dev/null || true
  wg-quick up "$IFACE"
  save_state "$id"
  install_reboot_hook
  ensure_node_firewall "$NODE_WG_PORT"
  echo "USK_OK: node_tunnel_ready id=${id} iface=${IFACE} node_wg_port=${NODE_WG_PORT}"
}

cmd_ensure_boot() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_wg
  ensure_jq
  load_state "$id" || exit 0
  [ -f "${WG_DIR}/${IFACE}.conf" ] || write_wg_conf "$id"
  wg-quick up "$IFACE" 2>/dev/null || true
  echo "USK_OK: tunnel_boot id=${id}"
}

cmd_status() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_jq
  [ -f "$(state_file "$id")" ] || usk_err "tunnel_not_configured"
  local up=0 iface
  iface=$(jq -r '.iface // empty' "$(state_file "$id")")
  if [ -n "$iface" ] && ip link show "$iface" >/dev/null 2>&1; then
    up=1
  fi
  echo "USK_OK: tunnel_status up=${up}"
  jq -c '.' "$(state_file "$id")"
}

ACTION="${1:-}"
shift || true
case "$ACTION" in
  init) cmd_init ;;
  pubkey) cmd_pubkey "$@" ;;
  ensure) cmd_ensure "$@" ;;
  ensure-boot) cmd_ensure_boot "$@" ;;
  status) cmd_status "$@" ;;
  *) usk_err "usage: setup-node-tunnel.sh init|pubkey|ensure|status" ;;
esac
