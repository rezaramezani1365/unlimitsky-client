#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/amnezia-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"
if [ -z "$USERNAME" ]; then usk_json_fail "username_required"; fi

if ! [ -f "$AMNEZIA_CONF" ] && ! usk_amnezia_bivlked; then
  usk_json_fail "amnezia_not_installed"
fi

EXPIRES=""
if [ "$DURATION_DAYS" -gt 0 ] 2>/dev/null; then
  EXPIRES=$(date -Iseconds -d "+${DURATION_DAYS} days" 2>/dev/null || date -Iseconds)
fi

REGISTRY="$DATA_ROOT/amnezia/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"

SERVER_IP=$(usk_server_ip)
PORT=$(usk_amnezia_server_port)
PORT=$(echo "$PORT" | tr -dc '0-9')
[ -n "$PORT" ] || PORT=51821

CONFIG=""
QR_B64=""
QR_CONF_B64=""
CLIENT_IP=""
CLIENT_PUB=""
VPN_URI=""
WG_CONF=""

if usk_amnezia_bivlked; then
  safe_name=$(echo "$USERNAME" | tr -c 'a-zA-Z0-9_-' '_')
  bash "$BIVLKED_MGMT" add "$safe_name" 2>/dev/null || usk_json_fail "amnezia_user_create_failed"
  conf_file="${BIVLKED_AWG_DIR}/${safe_name}.conf"
  [ -f "$conf_file" ] || usk_json_fail "amnezia_user_create_failed"
  WG_CONF=$(cat "$conf_file")
  [ -f "${BIVLKED_AWG_DIR}/${safe_name}.vpnuri" ] && VPN_URI=$(tr -d '\n\r' < "${BIVLKED_AWG_DIR}/${safe_name}.vpnuri")
  CLIENT_IP=$(grep -E '^Address' "$conf_file" | head -1 | awk '{print $3}' | cut -d/ -f1)
  CLIENT_PUB=$(grep -E '^PublicKey' "$conf_file" | tail -1 | awk '{print $3}')
  if [ -z "$CLIENT_PUB" ]; then
    awg=$(usk_amnezia_awg_bin) || true
    priv=$(grep -E '^PrivateKey' "$conf_file" | head -1 | awk '{print $3}')
    [ -n "$priv" ] && [ -n "$awg" ] && CLIENT_PUB=$(echo "$priv" | $awg pubkey)
  fi
else
  awg=$(usk_amnezia_awg_bin) || usk_json_fail "amnezia_not_installed"
  usk_amnezia_ensure_running || true
  SERVER_PUB=$(usk_amnezia_server_pubkey) || usk_json_fail "amnezia_not_installed"

  CLIENT_IP=$(usk_next_ip "10.9.9.1" "$REGISTRY")
  CLIENT_PRIV=$($awg genkey)
  CLIENT_PUB=$(echo "$CLIENT_PRIV" | $awg pubkey)

  $awg set awg0 peer "$CLIENT_PUB" allowed-ips "${CLIENT_IP}/32" 2>/dev/null || true

  if ! grep -q "$CLIENT_PUB" "$AMNEZIA_CONF" 2>/dev/null; then
    cat >> "$AMNEZIA_CONF" <<PEER

[Peer]
# $USERNAME
PublicKey = $CLIENT_PUB
AllowedIPs = ${CLIENT_IP}/32
PEER
  fi
  usk_amnezia_apply_conf

  WG_CONF=$(usk_amnezia_render_client_conf "$USERNAME" "$CLIENT_IP" "$CLIENT_PRIV" "$SERVER_PUB" "$SERVER_IP" "$PORT")
fi

if [ -z "$VPN_URI" ]; then
  VPN_URI=$(usk_amnezia_generate_vpn_uri "$WG_CONF" "$SERVER_IP" "$PORT" 2>/dev/null || true)
fi

if [ -n "$VPN_URI" ]; then
  QR_B64=$(usk_amnezia_qr_b64 "$VPN_URI")
  QR_CONF_B64=$(usk_amnezia_qr_b64 "$WG_CONF")
else
  QR_B64=$(usk_amnezia_qr_b64 "$WG_CONF")
fi

LINKS="$VPN_URI"
[ -n "$LINKS" ] && [ -n "$WG_CONF" ] && LINKS="${LINKS}

--- AmneziaWG native (.conf) ---
${WG_CONF}"
[ -z "$LINKS" ] && LINKS="$WG_CONF"

CONFIG="=== Amnezia VPN app (recommended) ===
Copy the vpn:// link below into Amnezia VPN app, or scan the QR code labeled Amnezia app.

${VPN_URI:-(vpn:// link could not be generated — install python3 on the server and retry)}

=== AmneziaWG native (.conf) ===
For AmneziaWG / WireGuard apps — import the .conf block below or scan the second QR.

${WG_CONF}"

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
    --arg links "$LINKS" \
    --arg wg "$WG_CONF" \
    --arg ip "$CLIENT_IP" \
    --arg ep "${SERVER_IP}:${PORT}" \
    --arg qr "$QR_B64" \
    --arg qrc "$QR_CONF_B64" \
    --arg exp "$EXPIRES" \
    --arg pk "$CLIENT_PUB" \
    --arg vuri "$VPN_URI" \
    --arg sub "$VPN_URI" \
    --argjson vol "$VOLUME_GB" \
    --argjson days "$DURATION_DAYS" \
    --argjson port "$PORT" \
    '{ok:true, username:$u, protocol:"amnezia", config:$cfg, links:$links, wg_conf:$wg, client_ip:$ip, endpoint:$ep, qr_png:$qr, qr_conf_png:$qrc, subscription_url:$sub, expires_at:$exp, public_key:$pk, volume_gb:$vol, duration_days:$days, port:$port, vpn_uri:$vuri}'
  exit 0
fi

usk_json_fail "jq_required"
