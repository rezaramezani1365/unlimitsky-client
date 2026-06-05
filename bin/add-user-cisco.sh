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

if [ ! -f /etc/ocserv/ocserv.conf ]; then
  usk_json_fail "cisco_not_installed"
fi

PASS=$(openssl rand -base64 12 | tr -d '/+=' | head -c 12)
printf '%s\n%s\n' "$PASS" "$PASS" | ocpasswd -c /etc/ocserv/ocpasswd "$USERNAME" 2>/dev/null \
  || usk_json_fail "cisco_user_create_failed"

SERVER_IP=$(usk_server_ip)
PORT=$(usk_protocol_port /etc/ocserv/ocserv.conf '^tcp-port' 4443)

CONFIG="Cisco AnyConnect / OpenConnect VPN
Server: ${SERVER_IP}
Port: ${PORT}
Username: ${USERNAME}
Password: ${PASS}

Android/iOS: Cisco AnyConnect or OpenConnect app
Windows: Cisco AnyConnect or OpenConnect client
URL: ${SERVER_IP}:${PORT}"

REGISTRY="$DATA_ROOT/cisco/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
ensure_jq
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
if command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" \
     --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
    '. += [{"username":$u,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active"}]' \
    "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
fi

echo -n "USK_JSON:"
jq -n \
  --arg u "$USERNAME" \
  --arg cfg "$CONFIG" \
  --arg pass "$PASS" \
  --arg ip "$SERVER_IP" \
  --argjson port "$PORT" \
  --arg exp "$EXPIRES" \
  --argjson vol "$VOLUME_GB" \
  --argjson days "$DURATION_DAYS" \
  '{ok:true, username:$u, protocol:"cisco", config:$cfg, links:$cfg, password:$pass, server_ip:$ip, port:$port, expires_at:$exp, volume_gb:$vol, duration_days:$days}'
exit 0
