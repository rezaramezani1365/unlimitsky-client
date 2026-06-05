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

XRAY_CFG="/usr/local/etc/xray/config.json"
if [ ! -f "$XRAY_CFG" ]; then
  usk_json_fail "xray_not_installed"
fi

ensure_jq
command -v jq >/dev/null 2>&1 || usk_json_fail "jq_required"

UUID=$(cat /proc/sys/kernel/random/uuid)
SERVER_IP=$(usk_server_ip)
VLESS_PORT=443
VMESS_PORT=8443

tmp=$(mktemp)
jq --arg id "$UUID" --arg email "$USERNAME" \
  '.inbounds[0].settings.clients += [{"id":$id,"email":$email,"flow":"xtls-rprx-vision"}] |
   .inbounds[1].settings.clients += [{"id":$id,"email":$email,"alterId":0}]' \
  "$XRAY_CFG" > "$tmp" && mv "$tmp" "$XRAY_CFG"

systemctl restart xray || systemctl restart xray.service

VLESS="vless://${UUID}@${SERVER_IP}:${VLESS_PORT}?encryption=none&security=none&type=tcp#${USERNAME}-vless"
VMESS_JSON=$(jq -n \
  --arg id "$UUID" \
  --arg add "$SERVER_IP" \
  --argjson port "$VMESS_PORT" \
  --arg ps "${USERNAME}-vmess" \
  '{v:"2",ps:$ps,add:$add,port:$port,id:$id,aid:0,net:"tcp",type:"none",host:"",path:"",tls:""}')
VMESS="vmess://$(echo -n "$VMESS_JSON" | base64 -w0 2>/dev/null || echo -n "$VMESS_JSON" | base64)"
LINKS="${VLESS}
${VMESS}"

REGISTRY="$DATA_ROOT/xray/clients.json"
mkdir -p "$(dirname "$REGISTRY")"
[ -f "$REGISTRY" ] || echo "[]" > "$REGISTRY"
tmp2=$(mktemp)
jq --arg u "$USERNAME" --arg id "$UUID" --arg ts "$(date -Iseconds)" --arg exp "$EXPIRES" \
   --argjson vol "$VOLUME_GB" --argjson days "$DURATION_DAYS" \
  '. += [{"username":$u,"uuid":$id,"created":$ts,"volume_gb":$vol,"duration_days":$days,"expires_at":$exp,"status":"active"}]' "$REGISTRY" > "$tmp2" && mv "$tmp2" "$REGISTRY"

echo -n "USK_JSON:"
jq -n \
  --arg u "$USERNAME" \
  --arg links "$LINKS" \
  --arg vless "$VLESS" \
  --arg vmess "$VMESS" \
  --arg id "$UUID" \
  --arg exp "$EXPIRES" \
  --argjson vol "$VOLUME_GB" \
  --argjson days "$DURATION_DAYS" \
  '{ok:true, username:$u, protocol:"xray", config:$links, links:$links, subscription_url:$vless, uuid:$id, vless:$vless, vmess:$vmess, expires_at:$exp, volume_gb:$vol, duration_days:$days}'
exit 0
