#!/bin/bash
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_PROV_VLESS="${USK_XRAY_VLESS_PORT:-}"
_PROV_VMESS="${USK_XRAY_VMESS_PORT:-}"
source "$DIR/provision-common.sh"
source "$DIR/xray-common.sh"

if [ "$EUID" -ne 0 ]; then usk_json_fail "run_as_root"; fi

USERNAME="${1:-}"
VOLUME_GB="${2:-0}"
DURATION_DAYS="${3:-0}"
if [ -z "$USERNAME" ]; then usk_json_fail "username_required"; fi

EXPIRES=""
if [ "$DURATION_DAYS" -gt 0 ] 2>/dev/null; then
  EXPIRES=$(date -Iseconds -d "+${DURATION_DAYS} days" 2>/dev/null || date -Iseconds)
fi

if [ ! -f "$XRAY_CFG" ]; then
  usk_json_fail "xray_not_installed"
fi

ensure_jq
command -v jq >/dev/null 2>&1 || usk_json_fail "jq_required"

INBOUND_COUNT=$(jq -r '.inbounds | length // 0' "$XRAY_CFG" 2>/dev/null || echo 0)
if [ "$INBOUND_COUNT" -lt 2 ]; then
  usk_json_fail "xray_config_invalid"
fi

usk_xray_ports_from_config "$XRAY_CFG"
VLESS_PORT="$USK_XRAY_VLESS_PORT"
VMESS_PORT="$USK_XRAY_VMESS_PORT"
[ -n "$_PROV_VLESS" ] && VLESS_PORT="$_PROV_VLESS"
[ -n "$_PROV_VMESS" ] && VMESS_PORT="$_PROV_VMESS"

UUID=$(cat /proc/sys/kernel/random/uuid)
SERVER_IP=$(usk_server_ip)

tmp=$(mktemp)
if ! jq --arg id "$UUID" --arg email "$USERNAME" \
  --argjson vless_port "$VLESS_PORT" --argjson vmess_port "$VMESS_PORT" \
  '.inbounds[0].listen = "0.0.0.0" |
   .inbounds[1].listen = "0.0.0.0" |
   .inbounds[0].port = $vless_port |
   .inbounds[1].port = $vmess_port |
   .inbounds[0].settings.clients = ((.inbounds[0].settings.clients // []) | map(del(.flow)) + [{"id":$id,"email":$email}]) |
   .inbounds[1].settings.clients = ((.inbounds[1].settings.clients // []) + [{"id":$id,"email":$email}]) |
   .inbounds[0].streamSettings = {"network":"tcp","security":"none","tcpSettings":{"header":{"type":"none"}}} |
   .inbounds[1].streamSettings = {"network":"tcp","security":"none"}' \
  "$XRAY_CFG" > "$tmp"; then
  rm -f "$tmp"
  usk_json_fail "xray_config_update_failed"
fi
mv "$tmp" "$XRAY_CFG"

if ! usk_xray_test_config "$XRAY_CFG"; then
  usk_json_fail "xray_config_test_failed"
fi

if ! usk_xray_service_restart; then
  usk_json_fail "xray_restart_failed"
fi

if ! usk_xray_port_listening "$VLESS_PORT"; then
  usk_json_fail "xray_vless_port_not_listening"
fi

VLESS="vless://${UUID}@${SERVER_IP}:${VLESS_PORT}?encryption=none&security=none&type=tcp&headerType=none#${USERNAME}-vless"
VMESS_JSON=$(jq -cn \
  --arg id "$UUID" \
  --arg add "$SERVER_IP" \
  --argjson port "$VMESS_PORT" \
  --arg ps "${USERNAME}-vmess" \
  '{v:"2",ps:$ps,add:$add,port:$port,id:$id,aid:0,net:"tcp",type:"none",host:"",path:"",tls:""}')
VMESS="vmess://$(echo -n "$VMESS_JSON" | base64 -w0 2>/dev/null || echo -n "$VMESS_JSON" | base64 | tr -d '\n')"
LINKS="${VLESS}
${VMESS}"

REGISTRY="$DATA_ROOT/xray/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
tmp2=$(mktemp)
if ! jq --arg u "$USERNAME" --arg id "$UUID" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" \
   --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
  '. += [{"username":$u,"uuid":$id,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active"}]' \
  "$REGISTRY" > "$tmp2"; then
  rm -f "$tmp2"
  usk_json_fail "xray_registry_failed"
fi
mv "$tmp2" "$REGISTRY"

echo -n "USK_JSON:"
jq -cn \
  --arg u "$USERNAME" \
  --arg links "$LINKS" \
  --arg vless "$VLESS" \
  --arg vmess "$VMESS" \
  --arg id "$UUID" \
  --arg exp "$EXPIRES" \
  --argjson vol "$VOLUME_GB" \
  --argjson days "$DURATION_DAYS" \
  --argjson vless_port "$VLESS_PORT" \
  --argjson vmess_port "$VMESS_PORT" \
  '{ok:true, username:$u, protocol:"xray", config:$links, links:$links, subscription_url:$vless, uuid:$id, vless:$vless, vmess:$vmess, vless_port:$vless_port, vmess_port:$vmess_port, expires_at:$exp, volume_gb:$vol, duration_days:$days}'
exit 0
