#!/bin/bash
# Hub-side WireGuard tunnel to a Node — policy-routes Xray egress (sendThrough) via node public IP.
# Usage:
#   setup-hub-node-tunnel.sh prepare <node_id>
#   setup-hub-node-tunnel.sh ensure <node_id> <node_pubkey> <node_endpoint_ip> <node_wg_port>
#   setup-hub-node-tunnel.sh send-through <node_id>
#   setup-hub-node-tunnel.sh status <node_id>
#   setup-hub-node-tunnel.sh remove <node_id>
#
set -euo pipefail

DATA_ROOT="${USK_DATA_ROOT:-/var/lib/unlimitsky}"
STATE_DIR="${DATA_ROOT}/node-tunnels"
WG_DIR="/etc/wireguard"

usk_err() { echo "USK_ERR: $*"; exit 1; }

ensure_wg() {
  command -v wg >/dev/null 2>&1 || {
    apt-get update -qq 2>/dev/null || true
    apt-get install -y wireguard wireguard-tools 2>/dev/null || true
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

route_table_id() {
  echo $((100 + $(node_octet "$1")))
}

state_file() {
  echo "${STATE_DIR}/$1.json"
}

detect_hub_endpoint() {
  local panel_root="${PANEL_ROOT:-}"
  local host=""
  if [ -n "$panel_root" ] && [ -f "${panel_root}/data/settings/connect-host.json" ] && command -v jq >/dev/null 2>&1; then
    host=$(jq -r 'if (.enabled // false) and ((.connect_host // "") != "") then .connect_host else empty end' \
      "${panel_root}/data/settings/connect-host.json" 2>/dev/null || true)
  fi
  if [ -n "$host" ]; then
    echo "$host"
    return 0
  fi
  curl -4 -s --max-time 6 ifconfig.me 2>/dev/null \
    || curl -4 -s --max-time 6 icanhazip.com 2>/dev/null \
    || hostname -I 2>/dev/null | awk '{print $1}'
}

load_state() {
  local id="$1" sf
  sf=$(state_file "$id")
  [ -f "$sf" ] || return 1
  HUB_PRIV=$(jq -r '.hub_private_key // empty' "$sf")
  HUB_PUB=$(jq -r '.hub_public_key // empty' "$sf")
  NODE_PUB=$(jq -r '.node_public_key // empty' "$sf")
  HUB_TUN_IP=$(jq -r '.hub_tunnel_ip // empty' "$sf")
  NODE_TUN_IP=$(jq -r '.node_tunnel_ip // empty' "$sf")
  SUBNET=$(jq -r '.subnet // empty' "$sf")
  HUB_WG_PORT=$(jq -r '.hub_wg_port // empty' "$sf")
  NODE_WG_PORT=$(jq -r '.node_wg_port // empty' "$sf")
  IFACE=$(jq -r '.iface // empty' "$sf")
  NODE_ENDPOINT=$(jq -r '.node_endpoint // empty' "$sf")
  HUB_ENDPOINT=$(jq -r '.hub_endpoint // empty' "$sf")
  RT_TABLE=$(jq -r '.route_table // empty' "$sf")
  [ -n "$HUB_PRIV" ] && [ -n "$HUB_PUB" ]
}

assign_node_network() {
  local id="$1" octet
  octet=$(node_octet "$id")
  HUB_TUN_IP="10.66.${octet}.1"
  NODE_TUN_IP="10.66.${octet}.2"
  SUBNET="10.66.${octet}.0/24"
  HUB_WG_PORT=$(node_wg_port "$id")
  IFACE=$(node_iface "$id")
  RT_TABLE=$(route_table_id "$id")
}

gen_hub_keys() {
  local tmp
  tmp=$(mktemp -d)
  umask 077
  wg genkey | tee "${tmp}/priv" | wg pubkey > "${tmp}/pub"
  HUB_PRIV=$(cat "${tmp}/priv")
  HUB_PUB=$(cat "${tmp}/pub")
  rm -rf "$tmp"
}

save_state() {
  local id="$1"
  mkdir -p "$STATE_DIR"
  jq -n \
    --arg id "$id" \
    --arg hub_priv "$HUB_PRIV" \
    --arg hub_pub "$HUB_PUB" \
    --arg node_pub "${NODE_PUB:-}" \
    --arg hub_tun_ip "$HUB_TUN_IP" \
    --arg node_tun_ip "$NODE_TUN_IP" \
    --arg subnet "$SUBNET" \
    --argjson hub_port "$HUB_WG_PORT" \
    --argjson node_port "${NODE_WG_PORT:-0}" \
    --arg iface "$IFACE" \
    --arg node_endpoint "${NODE_ENDPOINT:-}" \
    --arg hub_endpoint "${HUB_ENDPOINT:-}" \
    --argjson route_table "$RT_TABLE" \
    '{node_id:$id, hub_private_key:$hub_priv, hub_public_key:$hub_pub, node_public_key:$node_pub,
      hub_tunnel_ip:$hub_tun_ip, node_tunnel_ip:$node_tun_ip, subnet:$subnet,
      hub_wg_port:$hub_port, node_wg_port:$node_port, iface:$iface,
      node_endpoint:$node_endpoint, hub_endpoint:$hub_endpoint, route_table:$route_table,
      updated_at:(now|todate)}' > "$(state_file "$id")"
  chmod 600 "$(state_file "$id")"
}

ensure_route_table() {
  local table_id="$1" table_name="$2"
  grep -qE "^${table_id}[[:space:]]" /etc/iproute2/rt_tables 2>/dev/null \
    || echo "${table_id} ${table_name}" >> /etc/iproute2/rt_tables
}

apply_policy_route() {
  local hub_ip="$1" iface="$2" table_id="$3"
  local table_name="usk-node-${table_id}"
  ensure_route_table "$table_id" "$table_name"
  ip rule del from "${hub_ip}/32" table "${table_name}" 2>/dev/null || true
  ip rule add from "${hub_ip}/32" table "${table_name}"
  ip route flush table "${table_name}" 2>/dev/null || true
  ip route add default dev "${iface}" table "${table_name}"
}

remove_policy_route() {
  local hub_ip="$1" table_id="$2"
  local table_name="usk-node-${table_id}"
  ip rule del from "${hub_ip}/32" table "${table_name}" 2>/dev/null || true
  ip route flush table "${table_name}" 2>/dev/null || true
}

write_wg_conf() {
  local id="$1" conf="${WG_DIR}/${IFACE}.conf" table_name="usk-node-${RT_TABLE}"
  mkdir -p "$WG_DIR"
  chmod 700 "$WG_DIR"
  cat > "$conf" <<EOF
# unlimitsky hub→node egress tunnel (${id})
[Interface]
Address = ${HUB_TUN_IP}/24
ListenPort = ${HUB_WG_PORT}
PrivateKey = ${HUB_PRIV}
Table = off
PostUp = ip rule add from ${HUB_TUN_IP}/32 table ${table_name} 2>/dev/null || true; ip route add default dev %i table ${table_name} 2>/dev/null || true
PostDown = ip rule del from ${HUB_TUN_IP}/32 table ${table_name} 2>/dev/null || true; ip route flush table ${table_name} 2>/dev/null || true

[Peer]
PublicKey = ${NODE_PUB}
Endpoint = ${NODE_ENDPOINT}:${NODE_WG_PORT}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF
  chmod 600 "$conf"
}

ensure_hub_firewall() {
  local port="$1"
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi 'Status: active'; then
    ufw allow "${port}/udp" comment "unlimitsky-node-wg" >/dev/null 2>&1 || true
  fi
}

bring_up_iface() {
  local iface="$1"
  systemctl enable "wg-quick@${iface}" 2>/dev/null || true
  wg-quick down "$iface" 2>/dev/null || true
  wg-quick up "$iface"
  apply_policy_route "$HUB_TUN_IP" "$iface" "$RT_TABLE"
}

cmd_prepare() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_wg
  ensure_jq
  assign_node_network "$id"
  if ! load_state "$id" 2>/dev/null; then
    gen_hub_keys
  elif [ -z "${HUB_PRIV:-}" ] || [ -z "${HUB_PUB:-}" ]; then
    gen_hub_keys
  fi
  HUB_ENDPOINT=$(detect_hub_endpoint)
  save_state "$id"
  ensure_hub_firewall "$HUB_WG_PORT"
  echo -n "USK_JSON:"
  jq -cn \
    --arg id "$id" \
    --arg hub_pub "$HUB_PUB" \
    --arg hub_endpoint "$HUB_ENDPOINT" \
    --argjson hub_port "$HUB_WG_PORT" \
    --arg hub_tun_ip "$HUB_TUN_IP" \
    --arg node_tun_ip "$NODE_TUN_IP" \
    --arg subnet "$SUBNET" \
    --arg iface "$IFACE" \
    '{ok:true, node_id:$id, hub_public_key:$hub_pub, hub_endpoint:$hub_endpoint, hub_wg_port:$hub_port,
      hub_tunnel_ip:$hub_tun_ip, node_tunnel_ip:$node_tun_ip, subnet:$subnet, iface:$iface}'
}

cmd_ensure() {
  local id="${1:-}" node_pub="${2:-}" node_endpoint="${3:-}" node_wg_port="${4:-}"
  [ -n "$id" ] && [ -n "$node_pub" ] && [ -n "$node_endpoint" ] && [ -n "$node_wg_port" ] || usk_err "missing_args"
  ensure_wg
  ensure_jq
  assign_node_network "$id"
  if load_state "$id" 2>/dev/null; then
    [ -n "${HUB_PRIV:-}" ] || gen_hub_keys
  else
    gen_hub_keys
  fi
  NODE_PUB="$node_pub"
  NODE_ENDPOINT="$node_endpoint"
  NODE_WG_PORT="$node_wg_port"
  HUB_ENDPOINT=$(detect_hub_endpoint)
  write_wg_conf "$id"
  bring_up_iface "$IFACE"
  save_state "$id"
  echo "USK_OK: hub_tunnel_ready node=${id} send_through=${HUB_TUN_IP} iface=${IFACE} hub_wg_port=${HUB_WG_PORT}"
}

cmd_send_through() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_jq
  load_state "$id" || usk_err "tunnel_not_configured"
  echo "USK_OK: send_through=${HUB_TUN_IP}"
}

cmd_status() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_jq
  [ -f "$(state_file "$id")" ] || usk_err "tunnel_not_configured"
  local up=0
  load_state "$id" || true
  if [ -n "${IFACE:-}" ] && ip link show "$IFACE" >/dev/null 2>&1; then
    up=1
  fi
  echo "USK_OK: tunnel_status up=${up}"
  jq -c '.' "$(state_file "$id")"
}

cmd_remove() {
  local id="${1:-}"
  [ -n "$id" ] || usk_err "node_id_required"
  ensure_jq
  if load_state "$id" 2>/dev/null; then
    wg-quick down "$IFACE" 2>/dev/null || true
    systemctl disable "wg-quick@${IFACE}" 2>/dev/null || true
    rm -f "${WG_DIR}/${IFACE}.conf"
    remove_policy_route "$HUB_TUN_IP" "$RT_TABLE"
  fi
  rm -f "$(state_file "$id")"
  echo "USK_OK: hub_tunnel_removed id=${id}"
}

ACTION="${1:-}"
shift || true
case "$ACTION" in
  prepare) cmd_prepare "$@" ;;
  ensure) cmd_ensure "$@" ;;
  send-through) cmd_send_through "$@" ;;
  status) cmd_status "$@" ;;
  remove) cmd_remove "$@" ;;
  *) usk_err "usage: setup-hub-node-tunnel.sh prepare|ensure|send-through|status|remove" ;;
esac
