#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"
CLIENT_DNS="${4:-}"
USK_CONNECT_HOST_ARG="${5:-}"
XRAY_EMAIL="${6:-$USERNAME}"
NODE_ID="${7:-}"
if [ -z "$USERNAME" ]; then usk_json_fail "username_required"; fi
XRAY_EMAIL=$(printf '%s' "$XRAY_EMAIL" | tr -c 'a-zA-Z0-9@._+-' '_' | cut -c1-96)
[ -n "$XRAY_EMAIL" ] || XRAY_EMAIL="$USERNAME"

EXPIRES=""
if [ "$DURATION_DAYS" -gt 0 ] 2>/dev/null; then
  EXPIRES=$(date -Iseconds -d "+${DURATION_DAYS} days" 2>/dev/null || date -Iseconds)
fi

usk_xray_resolve_cfg
if [ ! -f "$XRAY_CFG" ]; then
  usk_json_fail "xray_not_installed"
fi

ensure_jq
command -v jq >/dev/null 2>&1 || usk_json_fail "jq_required"

INBOUND_COUNT=$(jq -r '[.inbounds[]? | select(.protocol=="vless")] | length' "$XRAY_CFG" 2>/dev/null || echo 0)
if [ "$INBOUND_COUNT" -lt 1 ]; then
  usk_json_fail "xray_config_invalid"
fi

usk_xray_load_reality || usk_json_fail "xray_reality_not_configured"
usk_xray_migrate_legacy_config "$XRAY_CFG" 2>/dev/null || true
usk_xray_ensure_stats_policy "$XRAY_CFG" 2>/dev/null || true

usk_xray_ports_from_config "$XRAY_CFG"
VLESS_PORT="$USK_XRAY_VLESS_PORT"

EXISTING_UUID=$(jq -r --arg email "$XRAY_EMAIL" '
  [.inbounds[]? | select(.protocol == "vless") | .settings.clients[]?
   | select(.email == $email) | .id] | first // empty
' "$XRAY_CFG" 2>/dev/null || true)
if [ -n "$EXISTING_UUID" ]; then
  UUID="$EXISTING_UUID"
else
  UUID=$(cat /proc/sys/kernel/random/uuid)
fi
PANEL_ROOT="${PANEL_ROOT:-$(dirname "$DIR")}"
SERVER_IP=$(usk_xray_resolve_connect_host "$PANEL_ROOT" 2>/dev/null || true)
[ -n "$SERVER_IP" ] || SERVER_IP=$(usk_server_ip)

CFG_BAK=$(mktemp)
cp "$XRAY_CFG" "$CFG_BAK"

if ! usk_xray_add_client "$XRAY_CFG" "$UUID" "$XRAY_EMAIL"; then
  rm -f "$CFG_BAK"
  usk_json_fail "xray_config_update_failed"
fi

if [ -n "$NODE_ID" ]; then
  HUB_TUN_SCRIPT="${DIR}/setup-hub-node-tunnel.sh"
  SEND_THROUGH=""
  if [ -x "$HUB_TUN_SCRIPT" ]; then
    SEND_THROUGH=$(/bin/bash "$HUB_TUN_SCRIPT" send-through "$NODE_ID" 2>/dev/null | sed -n 's/^USK_OK: send_through=//p' | head -1)
  fi
  if [ -z "$SEND_THROUGH" ]; then
    rm -f "$CFG_BAK"
    usk_json_fail "node_tunnel_not_ready"
  fi
  usk_xray_ensure_node_outbound "$XRAY_CFG" "$NODE_ID" "$SEND_THROUGH" || {
    rm -f "$CFG_BAK"
    usk_json_fail "xray_node_outbound_failed"
  }
  usk_xray_bind_user_to_node "$XRAY_CFG" "$XRAY_EMAIL" "$NODE_ID" || {
    rm -f "$CFG_BAK"
    usk_json_fail "xray_node_routing_failed"
  }
fi

if ! usk_xray_test_config "$XRAY_CFG"; then
  mv "$CFG_BAK" "$XRAY_CFG"
  rm -f "$CFG_BAK"
  usk_json_fail "xray_config_test_failed"
fi

if usk_xray_port_has_relay_dnat "$VLESS_PORT"; then
  usk_node_clear_relay_rules "$DIR" 2>/dev/null || true
  if usk_xray_port_has_relay_dnat "$VLESS_PORT"; then
    rm -f "$CFG_BAK"
    usk_json_fail "xray_relay_dnat_conflict port=${VLESS_PORT}"
  fi
fi

if ! usk_xray_service_restart; then
  mv "$CFG_BAK" "$XRAY_CFG"
  systemctl restart xray 2>/dev/null || true
  rm -f "$CFG_BAK"
  usk_json_fail "xray_restart_failed"
fi
rm -f "$CFG_BAK"

if ! usk_xray_port_listening "$VLESS_PORT"; then
  if usk_xray_port_has_relay_dnat "$VLESS_PORT"; then
    usk_json_fail "xray_relay_dnat_conflict port=${VLESS_PORT}"
  fi
  usk_json_fail "xray_vless_port_not_listening port=${VLESS_PORT}"
fi

SID=$(usk_xray_reality_short_id_for_client)
FP="${REALITY_FINGERPRINT:-chrome}"
VLESS=$(usk_xray_build_vless_uri "$UUID" "$SERVER_IP" "$VLESS_PORT" "${USERNAME}-vless" \
  "$REALITY_PUBLIC_KEY" "$REALITY_SNI" "$SID" "$FP")

QR_B64=""
if command -v qrencode >/dev/null 2>&1; then
  QR_B64=$(qrencode -t PNG -o - "$VLESS" 2>/dev/null | base64 -w0 2>/dev/null || qrencode -t PNG -o - "$VLESS" 2>/dev/null | base64)
fi

CLIENT_JSON=$(usk_xray_build_client_json "$UUID" "$SERVER_IP" "$VLESS_PORT" "$USERNAME" "$CLIENT_DNS" 2>/dev/null || echo "")

PROFILE_DIR="$DATA_ROOT/xray/profiles"
mkdir -p "$PROFILE_DIR"
safe_user=$(echo "$USERNAME" | tr -c 'a-zA-Z0-9_-' '_')
JSON_FILE="${PROFILE_DIR}/${safe_user}.json"
if [ -n "$CLIENT_JSON" ]; then
  printf '%s\n' "$CLIENT_JSON" > "$JSON_FILE"
  chmod 644 "$JSON_FILE"
fi
JSON_FILENAME="${safe_user}.json"
DOWNLOAD_TOKEN=$(openssl rand -hex 16 2>/dev/null || cat /proc/sys/kernel/random/uuid 2>/dev/null | tr -d '-' | cut -c1-32)

DNS_NOTE=""
if [ -n "$CLIENT_DNS" ]; then
  DNS_NOTE="DNS (IPv4): ${CLIENT_DNS}"
else
  DNS_NOTE="DNS: system default (IPv4 only — set custom DNS in panel if 1.1.1.1/8.8.8.8 fails on national network)"
fi

CONFIG="=== VLESS + Reality (Iran) ===
Import vless:// link in v2rayN / Nekoray / Hiddify / Streisand.
Or import the JSON profile (recommended — includes DNS settings).

${VLESS}

${DNS_NOTE}

=== JSON profile ===
${CLIENT_JSON}"

LINKS="${VLESS}"

REGISTRY="$DATA_ROOT/xray/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
tmp2=$(mktemp)
if ! jq --arg u "$USERNAME" --arg id "$UUID" --arg email "$XRAY_EMAIL" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" \
   --arg dns "$CLIENT_DNS" --arg token "$DOWNLOAD_TOKEN" --arg vless "$VLESS" \
   --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
  '. += [{"username":$u,"uuid":$id,"email":$email,"xray_email":$email,"usage_id":$email,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active","client_dns":$dns,"download_token":$token,"vless":$vless}]' \
  "$REGISTRY" > "$tmp2"; then
  rm -f "$tmp2"
  usk_json_fail "xray_registry_failed"
fi
mv "$tmp2" "$REGISTRY"

echo -n "USK_JSON:"
jq -cn \
  --arg u "$USERNAME" \
  --arg email "$XRAY_EMAIL" \
  --arg cfg "$CONFIG" \
  --arg links "$LINKS" \
  --arg vless "$VLESS" \
  --arg json "$CLIENT_JSON" \
  --arg id "$UUID" \
  --arg exp "$EXPIRES" \
  --arg dns "$CLIENT_DNS" \
  --arg token "$DOWNLOAD_TOKEN" \
  --arg file "$JSON_FILE" \
  --arg fname "$JSON_FILENAME" \
  --arg sni "$REALITY_SNI" \
  --arg fp "$FP" \
  --arg qr "$QR_B64" \
  --argjson vol "$VOLUME_GB" \
  --argjson days "$DURATION_DAYS" \
  --argjson port "$VLESS_PORT" \
  --arg transport "reality" \
  '{ok:true, username:$u, email:$email, xray_email:$email, usage_id:$email, protocol:"xray", config:$cfg, links:$links, subscription_url:$vless, uuid:$id, vless:$vless, client_json:$json, vless_port:$port, transport:$transport, reality_sni:$sni, fingerprint:$fp, client_dns:$dns, download_token:$token, json_filename:$fname, profile_path:$file, expires_at:$exp, volume_gb:$vol, duration_days:$days, qr_png:$qr}'
exit 0
