#!/bin/bash
# Install Xray (VLESS + VMess) on Ubuntu — plain TCP, no TLS (works with Nekoray/v2rayN)
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/usk-common.sh"

apt-get update -qq
apt-get install -y curl unzip jq

bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install

UUID=$(cat /proc/sys/kernel/random/uuid)
mkdir -p /usr/local/etc/xray
XRAY_CFG="/usr/local/etc/xray/config.json"

# Preserve existing client UUIDs on reinstall (drop invalid xtls flow)
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
  '{
    log: { loglevel: "warning" },
    inbounds: [
      {
        listen: "0.0.0.0",
        port: 443,
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
        port: 8443,
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
systemctl restart xray 2>/dev/null || systemctl restart xray.service 2>/dev/null || usk_fail "xray_service_failed"

sleep 1
systemctl is-active xray >/dev/null 2>&1 || systemctl is-active xray.service >/dev/null 2>&1 || usk_fail "xray_not_running"

if command -v ss >/dev/null 2>&1; then
  ss -tlnp 2>/dev/null | grep -q ':443 ' || usk_fail "xray_port_443_not_listening"
  ss -tlnp 2>/dev/null | grep -q ':8443 ' || usk_fail "xray_port_8443_not_listening"
fi

ensure_ufw_port 443 tcp xray-vless
ensure_ufw_port 8443 tcp xray-vmess
usk_ok
