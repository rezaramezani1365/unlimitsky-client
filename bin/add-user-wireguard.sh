#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"

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
ENDPOINT=$(usk_server_ip)
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

CONFIG="[Interface]
PrivateKey = $CLIENT_PRIV
Address = ${CLIENT_IP}/32
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = $SERVER_PUB
Endpoint = ${ENDPOINT}:${PORT}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25"

QR_B64=""
if command -v qrencode >/dev/null 2>&1; then
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
    --arg ep "${ENDPOINT}:${PORT}" \
    --arg qr "$QR_B64" \
    --arg exp "$EXPIRES" \
    --arg pk "$CLIENT_PUB" \
    --argjson vol "$VOLUME_GB" \
    --argjson days "$DURATION_DAYS" \
    '{ok:true, username:$u, protocol:"wireguard", config:$cfg, links:$cfg, client_ip:$ip, endpoint:$ep, qr_png:$qr, expires_at:$exp, public_key:$pk, volume_gb:$vol, duration_days:$days}'
  exit 0
fi

usk_json_fail "jq_required"
