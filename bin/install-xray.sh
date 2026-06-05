#!/bin/bash
# Install Xray (VLESS + VMess) on Ubuntu — plain TCP for Nekoray / v2rayN
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"
source "$DIR/xray-common.sh"

VLESS_PORT="${USK_XRAY_VLESS_PORT}"
VMESS_PORT="${USK_XRAY_VMESS_PORT}"

apt-get update -qq
apt-get install -y curl unzip jq

bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install

UUID=$(cat /proc/sys/kernel/random/uuid)
mkdir -p /usr/local/etc/xray

EXISTING_VLESS='[]'
EXISTING_VMESS='[]'
if [ -f "$XRAY_CFG" ] && command -v jq >/dev/null 2>&1; then
  EXISTING_VLESS=$(jq -c '[.inbounds[0].settings.clients[]? | del(.flow) | {id, email}]' "$XRAY_CFG" 2>/dev/null || echo '[]')
  EXISTING_VMESS=$(jq -c '[.inbounds[1].settings.clients[]? | {id, email, alterId: (.alterId // 0)}]' "$XRAY_CFG" 2>/dev/null || echo '[]')
fi

if [ "$EXISTING_VLESS" = "[]" ] || [ "$EXISTING_VLESS" = "null" ]; then
  EXISTING_VLESS="[{\"id\":\"$UUID\",\"email\":\"bootstrap\"}]"
fi
if [ "$EXISTING_VMESS" = "[]" ] || [ "$EXISTING_VMESS" = "null" ]; then
  EXISTING_VMESS="[{\"id\":\"$UUID\",\"alterId\":0,\"email\":\"bootstrap\"}]"
fi

jq -n \
  --argjson vless "$EXISTING_VLESS" \
  --argjson vmess "$EXISTING_VMESS" \
  --argjson vless_port "$VLESS_PORT" \
  --argjson vmess_port "$VMESS_PORT" \
  '{
    log: { loglevel: "warning" },
    inbounds: [
      {
        listen: "0.0.0.0",
        port: $vless_port,
        protocol: "vless",
        tag: "vless-in",
        settings: { clients: $vless, decryption: "none" },
        streamSettings: {
          network: "tcp",
          security: "none",
          tcpSettings: { header: { type: "none" } }
        },
        sniffing: { enabled: true, destOverride: ["http", "tls"] }
      },
      {
        listen: "0.0.0.0",
        port: $vmess_port,
        protocol: "vmess",
        tag: "vmess-in",
        settings: { clients: $vmess },
        streamSettings: { network: "tcp", security: "none" },
        sniffing: { enabled: true, destOverride: ["http", "tls"] }
      }
    ],
    outbounds: [{ protocol: "freedom", tag: "direct" }]
  }' > "$XRAY_CFG"

systemctl enable xray 2>/dev/null || systemctl enable xray.service 2>/dev/null || true

usk_xray_open_firewall "$VLESS_PORT" "xray-vless"
usk_xray_open_firewall "$VMESS_PORT" "xray-vmess"

usk_xray_verify_or_fail "$XRAY_CFG" || exit 1

echo "USK_META:vless_port=${VLESS_PORT};vmess_port=${VMESS_PORT}"
usk_ok
