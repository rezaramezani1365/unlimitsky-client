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

if [ ! -f /etc/xl2tpd/xl2tpd.conf ]; then
  usk_json_fail "l2tp_not_installed"
fi

PSK_FILE="/etc/unlimitsky-l2tp.psk"
if [ ! -f "$PSK_FILE" ]; then
  usk_json_fail "l2tp_psk_missing"
fi
PSK=$(cat "$PSK_FILE")

# Enable per-user authentication
if grep -q 'require authentication = no' /etc/xl2tpd/xl2tpd.conf; then
  sed -i 's/require authentication = no/require authentication = yes/' /etc/xl2tpd/xl2tpd.conf
  systemctl restart xl2tpd || true
fi

PASS=$(openssl rand -base64 12 | tr -d '/+=' | head -c 12)
SERVER_IP=$(usk_server_ip)

CHAP="/etc/ppp/chap-secrets"
touch "$CHAP"
if ! grep -q "^${USERNAME} " "$CHAP" 2>/dev/null; then
  echo "${USERNAME} l2tpd ${PASS} *" >> "$CHAP"
fi

REGISTRY="$DATA_ROOT/l2tp/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
ensure_jq
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
if command -v jq >/dev/null 2>&1; then
  tmp=$(mktemp)
  jq --arg u "$USERNAME" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" \
     --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
    '. += [{"username":$u,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active"}]' "$REGISTRY" > "$tmp" && mv "$tmp" "$REGISTRY"
fi

CONFIG="L2TP/IPsec VPN
Server: ${SERVER_IP}
Username: ${USERNAME}
Password: ${PASS}
Pre-Shared Key (PSK): ${PSK}
Type: L2TP/IPsec with Pre-Shared Key

Windows: Settings → Network → VPN → Add
iOS/Android: Use built-in L2TP client with above credentials."

echo -n "USK_JSON:"
jq -n \
  --arg u "$USERNAME" \
  --arg cfg "$CONFIG" \
  --arg pass "$PASS" \
  --arg psk "$PSK" \
  --arg ip "$SERVER_IP" \
  --arg exp "$EXPIRES" \
  --argjson vol "$VOLUME_GB" \
  --argjson days "$DURATION_DAYS" \
  '{ok:true, username:$u, protocol:"l2tp", config:$cfg, links:$cfg, password:$pass, psk:$psk, server_ip:$ip, expires_at:$exp, volume_gb:$vol, duration_days:$days}'
exit 0
