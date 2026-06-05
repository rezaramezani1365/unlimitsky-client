#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/openvpn-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"
if [ -z "$USERNAME" ]; then usk_json_fail "username_required"; fi

EXPIRES=""
if [ "$DURATION_DAYS" -gt 0 ] 2>/dev/null; then
  EXPIRES=$(date -Iseconds -d "+${DURATION_DAYS} days" 2>/dev/null || date -Iseconds)
fi

PROTO="${USK_OPENVPN_PROTO:-udp}"
PROTO=$(echo "$PROTO" | tr '[:upper:]' '[:lower:]')
if [ "$PROTO" != "tcp" ]; then
  PROTO="udp"
fi

CFG=$(usk_openvpn_server_conf "$PROTO")
if [ ! -f "$CFG" ]; then
  usk_json_fail "openvpn_not_installed"
fi

usk_openvpn_read_proto_port "$PROTO" || usk_json_fail "openvpn_config_invalid"
PORT="$USK_OVPN_PORT"
PROTO="$USK_OVPN_PROTO"

EASYRSA="/etc/openvpn/easy-rsa"
if [ ! -d "$EASYRSA/pki" ]; then
  usk_json_fail "openvpn_pki_missing"
fi

cd "$EASYRSA"
if [ ! -f "pki/issued/${USERNAME}.crt" ]; then
  ./easyrsa --batch build-client-full "$USERNAME" nopass
fi

SERVER_IP=$(usk_server_ip)
CA=$(cat pki/ca.crt)
CERT=$(cat "pki/issued/${USERNAME}.crt")
KEY=$(cat "pki/private/${USERNAME}.key")
TLS=$(cat /etc/openvpn/ta.key 2>/dev/null || true)

CONFIG="client
dev tun
proto ${PROTO}
remote ${SERVER_IP} ${PORT}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
redirect-gateway def1
dhcp-option DNS 1.1.1.1
dhcp-option DNS 8.8.8.8
cipher AES-256-GCM
auth SHA256
data-ciphers AES-256-GCM:AES-128-GCM
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

PROFILE_DIR="$DATA_ROOT/openvpn/profiles"
mkdir -p "$PROFILE_DIR"
OVPN_FILE="${PROFILE_DIR}/${USERNAME}.ovpn"
printf '%s\n' "$CONFIG" > "$OVPN_FILE"
chmod 644 "$OVPN_FILE"

DOWNLOAD_TOKEN=$(openssl rand -hex 16 2>/dev/null || cat /proc/sys/kernel/random/uuid | tr -d '-')

REGISTRY="$DATA_ROOT/openvpn/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
ensure_jq
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
if command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" \
     --arg proto "$PROTO" --argjson port "$PORT" --arg token "$DOWNLOAD_TOKEN" \
     --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
    '. += [{"username":$u,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active","proto":$proto,"port":$port,"download_token":$token}]' \
    "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
  echo -n "USK_JSON:"
  jq -n \
    --arg u "$USERNAME" \
    --arg cfg "$CONFIG" \
    --arg ip "$SERVER_IP" \
    --arg exp "$EXPIRES" \
    --arg proto "$PROTO" \
    --arg token "$DOWNLOAD_TOKEN" \
    --arg file "$OVPN_FILE" \
    --arg fname "${USERNAME}.ovpn" \
    --argjson port "$PORT" \
    --argjson vol "$VOLUME_GB" \
    --argjson days "$DURATION_DAYS" \
    '{ok:true, username:$u, protocol:"openvpn", config:$cfg, links:$cfg, subscription_url:$cfg, server_ip:$ip, port:$port, proto:$proto, ovpn_filename:$fname, profile_path:$file, download_token:$token, expires_at:$exp, volume_gb:$vol, duration_days:$days}'
  exit 0
fi

usk_json_fail "jq_required"
