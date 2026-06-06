#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/wireguard-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"
TRANSPORT="${4:-udp}"
TRANSPORT=$(echo "$TRANSPORT" | tr '[:upper:]' '[:lower:]')
[ "$TRANSPORT" = "tcp" ] || TRANSPORT="udp"
CLIENT_DNS="${5:-}"
USK_CONNECT_HOST_ARG="${6:-}"

if [ -z "$USERNAME" ]; then usk_json_fail "username_required"; fi

if [ ! -f /etc/wireguard/wg0.conf ]; then
  usk_json_fail "wireguard_not_installed"
fi

EXPIRES=""
if [ "$DURATION_DAYS" -gt 0 ] 2>/dev/null; then
  EXPIRES=$(date -Iseconds -d "+${DURATION_DAYS} days" 2>/dev/null || date -Iseconds)
fi

REGISTRY="$DATA_ROOT/wireguard/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"

CLIENT_IP=$(usk_next_ip "10.8.0.1" "$REGISTRY")
CLIENT_PRIV=$(wg genkey)
CLIENT_PUB=$(echo "$CLIENT_PRIV" | wg pubkey)
SERVER_PUB=$(cat /etc/wireguard/server_public.key)
SERVER_IP=$(usk_server_ip)
PORT=$(usk_protocol_port /etc/wireguard/wg0.conf '^ListenPort' 51820)

wg set wg0 peer "$CLIENT_PUB" allowed-ips "${CLIENT_IP}/32"

if ! grep -q "$CLIENT_PUB" /etc/wireguard/wg0.conf; then
  cat >> /etc/wireguard/wg0.conf <<PEER

[Peer]
# $USERNAME
PublicKey = $CLIENT_PUB
AllowedIPs = ${CLIENT_IP}/32
PEER
fi

TCP_CLIENT_CMD=""
TCP_KEY=""
TCP_PORT=""
ENDPOINT="${SERVER_IP}:${PORT}"

if [ "$TRANSPORT" = "tcp" ]; then
  TCP_PORT=$(usk_wg_tcp_port)
  TCP_KEY=$(usk_wg_tcp_key)
  if [ -z "$TCP_PORT" ] || [ -z "$TCP_KEY" ]; then
    usk_json_fail "wireguard_tcp_not_installed"
  fi
  ENDPOINT="127.0.0.1:${PORT}"
  TCP_CLIENT_CMD="udp2raw -c -l127.0.0.1:${PORT} -r${SERVER_IP}:${TCP_PORT} -k ${TCP_KEY} --raw-mode faketcp --cipher-mode aes128cbc --fix-gro"
fi

CONFIG="[Interface]
PrivateKey = $CLIENT_PRIV
Address = ${CLIENT_IP}/32"
if [ -n "$CLIENT_DNS" ]; then
  CONFIG="${CONFIG}
DNS = ${CLIENT_DNS}"
fi
CONFIG="${CONFIG}

[Peer]
PublicKey = $SERVER_PUB
Endpoint = ${ENDPOINT}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25"

if [ "$TRANSPORT" = "tcp" ]; then
  CONFIG="${CONFIG}

# --- TCP mode (Iran / filtered networks) ---
# 1) Download udp2raw for your OS: https://github.com/wangyu-/udp2raw/releases
# 2) Run this tunnel BEFORE connecting WireGuard (keep it running):
# ${TCP_CLIENT_CMD}
# 3) WireGuard Endpoint must stay 127.0.0.1:${PORT}"
fi

QR_B64=""
if [ "$TRANSPORT" = "udp" ] && command -v qrencode >/dev/null 2>&1; then
  QR_B64=$(qrencode -t PNG -o - "$CONFIG" 2>/dev/null | base64 -w0 2>/dev/null || qrencode -t PNG -o - "$CONFIG" 2>/dev/null | base64)
fi

ensure_jq
if command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" --arg ip "$CLIENT_IP" --arg pk "$CLIENT_PUB" --arg ts "$(date -Iseconds)" \
     --argjson vol "$VOLUME_GB" --arg exp "$EXPIRES" \
    '. += [{"username":$u,"ip":$ip,"public_key":$pk,"created":$ts,"volume_gb":$vol,"expires_at":$exp,"status":"active"}]' \
    "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"

  echo -n "USK_JSON:"
  jq -n \
    --arg u "$USERNAME" \
    --arg cfg "$CONFIG" \
    --arg ip "$CLIENT_IP" \
    --arg ep "$ENDPOINT" \
    --arg qr "$QR_B64" \
    --arg exp "$EXPIRES" \
    --arg pk "$CLIENT_PUB" \
    --arg transport "$TRANSPORT" \
    --arg tcp_cmd "$TCP_CLIENT_CMD" \
    --arg tcp_key "$TCP_KEY" \
    --arg tcp_port "$TCP_PORT" \
    --argjson vol "$VOLUME_GB" \
    --argjson days "$DURATION_DAYS" \
    '{ok:true, username:$u, protocol:"wireguard", wireguard_transport:$transport, config:$cfg, links:$cfg, client_ip:$ip, endpoint:$ep, qr_png:$qr, expires_at:$exp, public_key:$pk, volume_gb:$vol, duration_days:$days, tcp_client_cmd:$tcp_cmd, tcp_port:$tcp_port}'
  exit 0
fi

usk_json_fail "jq_required"
