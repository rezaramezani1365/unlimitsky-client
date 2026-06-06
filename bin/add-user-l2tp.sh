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
PSK=$(tr -d '\n\r' < "$PSK_FILE")

PASS=$(openssl rand -base64 12 | tr -d '/+=' | head -c 12)
SERVER_IP=$(usk_server_ip)

CHAP="/etc/ppp/chap-secrets"
touch "$CHAP"
chmod 600 "$CHAP"
if grep -q "^${USERNAME} " "$CHAP" 2>/dev/null; then
  sed -i "/^${USERNAME} /d" "$CHAP"
fi
echo "${USERNAME} l2tpd ${PASS} *" >> "$CHAP"

if grep -q 'require authentication = no' /etc/xl2tpd/xl2tpd.conf; then
  sed -i 's/require authentication = no/require authentication = yes/' /etc/xl2tpd/xl2tpd.conf
  systemctl restart xl2tpd 2>/dev/null || true
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
Server / IP: ${SERVER_IP}
Username: ${USERNAME}
Password: ${PASS}
Pre-Shared Key (PSK): ${PSK}

--- Windows 10/11 ---
1) Settings → Network & Internet → VPN → Add VPN
2) VPN provider: Windows (built-in)
3) VPN type: L2TP/IPsec with pre-shared key  ← مهم
4) Server: ${SERVER_IP}
5) Pre-shared key: ${PSK}
6) Username: ${USERNAME}  Password: ${PASS}
   (یا بعد از ساخت: VPN → Advanced → Edit → PSK را وارد کن)

--- iPhone/iPad ---
Settings → General → VPN → Add → Type: L2TP
Server: ${SERVER_IP} | Account: ${USERNAME} | Password: ${PASS}
Secret: ${PSK}

Firewall VPS: UDP 500, 4500, 1701 must be open."

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
