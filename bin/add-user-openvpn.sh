#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"
if [ -z "$USERNAME" ]; then usk_json_fail "username_required"; fi

EXPIRES=""
if [ "$DURATION_DAYS" -gt 0 ] 2>/dev/null; then
  EXPIRES=$(date -Iseconds -d "+${DURATION_DAYS} days" 2>/dev/null || date -Iseconds)
fi

if [ ! -f /etc/openvpn/server.conf ]; then
  usk_json_fail "openvpn_not_installed"
fi

EASYRSA="/etc/openvpn/easy-rsa"
if [ ! -d "$EASYRSA/pki" ]; then
  usk_json_fail "openvpn_pki_missing"
fi

cd "$EASYRSA"
if [ ! -f "pki/issued/${USERNAME}.crt" ]; then
  ./easyrsa --batch build-client-full "$USERNAME" nopass
fi

SERVER_IP=$(usk_server_ip)
PORT=1194
CA=$(cat pki/ca.crt)
CERT=$(cat "pki/issued/${USERNAME}.crt")
KEY=$(cat "pki/private/${USERNAME}.key")
TLS=$(cat /etc/openvpn/ta.key 2>/dev/null || true)

CONFIG="client
dev tun
proto udp
remote ${SERVER_IP} ${PORT}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
cipher AES-256-GCM
verb 3
<ca>
${CA}
</ca>
<cert>
${CERT}
</cert>
<key>
${KEY}
</key>"

if [ -n "$TLS" ]; then
  CONFIG="${CONFIG}
<tls-auth>
${TLS}
</tls-auth>
key-direction 1"
fi

REGISTRY="$DATA_ROOT/openvpn/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
ensure_jq
if command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  [ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
  jq --arg u "$USERNAME" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
    '. += [{"username":$u,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active"}]' "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
  echo -n "USK_JSON:"
  jq -n \
    --arg u "$USERNAME" \
    --arg cfg "$CONFIG" \
    --arg ip "$SERVER_IP" \
    --arg exp "$EXPIRES" \
    --argjson vol "$VOLUME_GB" \
    --argjson days "$DURATION_DAYS" \
    '{ok:true, username:$u, protocol:"openvpn", config:$cfg, links:$cfg, server_ip:$ip, port:1194, expires_at:$exp, volume_gb:$vol, duration_days:$days}'
  exit 0
fi

usk_json_fail "jq_required"
